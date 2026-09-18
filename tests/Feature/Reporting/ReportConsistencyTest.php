<?php

namespace Tests\Feature\Reporting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Reporting\Services\MovementReportService;
use App\Domain\Reporting\Services\PurchaseReportService;
use App\Domain\Reporting\Services\StockReportService;
use App\Domain\Reporting\Services\UsageReportService;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Transfer\Services\TransferService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 15 doğrulaması: raporlar farklı filtre
 * kombinasyonlarıyla (tarih aralığı, şube, depo, kategori, tedarikçi) test
 * edilir, doğru ve tutarlı veri döndürür.
 *
 * Senaryo (Ağustos–Eylül 2026, iki şube, üç depo):
 *   Merkez şubesi: Merkezi Depo (A), Cerrahi Depo (B); Kuzey şubesi: Kuzey Depo (C)
 *   Kompozit A (Kompozit, Dental Tedarik) · Nitril Eldiven (Eldiven, Medikal A.Ş.)
 *   10.08 SA-1: Dental Tedarik'ten 100 Kompozit @50 → A, tamamı teslim
 *   15.08 C'ye 200 eldiven @2 (doğrudan giriş)
 *   20.08 A'dan 10 kompozit kullanım; C'den 30 eldiven kullanım, 5 hasarlı
 *   05.09 A → B 20 kompozit transfer (gönderildi + teslim alındı)
 *   10.09 B'den 4, A'dan 6 kompozit kullanım; C'den 10 eldiven kullanım → iptal edildi
 *   12.09 A sayımı: sistem 64, sayılan 62 → −2
 *   14.09 SA-2: Medikal A.Ş.'den 500 eldiven @2 → C, 300'ü @1,90 teslim (kalan 200 açık)
 *   15.09 A'dan 3 kompozit Dental Tedarik'e iade → tamamlandı, 150 ₺ kredi notu;
 *         C'den 5 eldiven iade talebi (henüz kargolanmadı)
 * Bugün: 18.09.2026. Stok: A 59, B 16, C 465.
 */
class ReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $central;

    private Branch $north;

    private Warehouse $a;

    private Warehouse $b;

    private Warehouse $c;

    private Category $composites;

    private Category $gloves;

    private Supplier $dental;

    private Supplier $medical;

    private Product $composite;

    private Product $glove;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->a = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->b = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);
        $this->c = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->composites = Category::create(['name' => 'Kompozit', 'status' => 'active']);
        $this->gloves = Category::create(['name' => 'Eldiven', 'status' => 'active']);
        $this->dental = Supplier::create(['name' => 'Dental Tedarik', 'status' => 'active']);
        $this->medical = Supplier::create(['name' => 'Medikal A.Ş.', 'status' => 'active']);
        $this->composite = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'category_id' => $this->composites->id, 'supplier_id' => $this->dental->id, 'status' => 'active', 'min_stock' => 0]);
        $this->glove = Product::create(['name' => 'Nitril Eldiven', 'base_unit' => 'Adet', 'category_id' => $this->gloves->id, 'supplier_id' => $this->medical->id, 'status' => 'active', 'min_stock' => 0]);

        $this->buildScenario();
        $this->travelTo('2026-09-18 12:00:00');
    }

    private function buildScenario(): void
    {
        $stock = app(StockMovementService::class);
        $orders = app(PurchaseOrderService::class);
        $admin = $this->admin;

        $this->travelTo('2026-08-10 10:00:00');
        $first = $orders->create($this->dental, $this->a, [['product_id' => $this->composite->id, 'quantity' => 100, 'unit_price' => 50]], null, null, $admin);
        $orders->submit($first, $admin);
        $orders->approve($first, $admin);
        $orders->markOrdered($first, $admin);
        $orders->receive($first, [$first->lines()->sole()->id => ['quantity' => 100, 'lot_no' => 'K-1']], ['invoice_number' => 'FTR-1'], $admin);

        $this->travelTo('2026-08-15 10:00:00');
        $stock->in($this->glove, $this->c, 200, ['lot_no' => 'E-1', 'unit_cost' => 2], $admin);

        $this->travelTo('2026-08-20 10:00:00');
        $stock->out($this->composite, $this->a, 10, null, $admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->out($this->glove, $this->c, 30, null, $admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->out($this->glove, $this->c, 5, null, $admin, 'Hasarlı', StockOutReason::Damaged);

        $this->travelTo('2026-09-05 10:00:00');
        $transfers = app(TransferService::class);
        $transfer = $transfers->request($this->composite, $this->a, $this->b, 20, 'Cerrahi ihtiyaç', $admin);
        $transfers->approve($transfer, $admin);
        $transfers->prepare($transfer, $admin);
        $transfers->ship($transfer, $admin);
        $transfers->receive($transfer, $admin);

        $this->travelTo('2026-09-10 10:00:00');
        $stock->out($this->composite, $this->b, 4, null, $admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->out($this->composite, $this->a, 6, null, $admin, 'Kullanım', StockOutReason::ClinicalUse);
        $mistake = $stock->out($this->glove, $this->c, 10, null, $admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->cancel($mistake->sole(), $admin);

        $this->travelTo('2026-09-12 10:00:00');
        $counts = app(StockCountService::class);
        $count = $counts->start($this->a, $admin);
        $counts->record($count, [$count->lines()->sole()->id => ['counted_quantity' => 62, 'reason' => 'loss']], $admin);
        $counts->submit($count, $admin);
        $counts->approve($count, $admin);

        $this->travelTo('2026-09-14 10:00:00');
        $second = $orders->create($this->medical, $this->c, [['product_id' => $this->glove->id, 'quantity' => 500, 'unit_price' => 2]], null, null, $admin);
        $orders->submit($second, $admin);
        $orders->approve($second, $admin);
        $orders->markOrdered($second, $admin);
        $orders->receive($second, [$second->lines()->sole()->id => ['quantity' => 300, 'lot_no' => 'E-2', 'unit_cost' => 1.9]], ['invoice_number' => 'FTR-2'], $admin);

        $this->travelTo('2026-09-15 10:00:00');
        $returns = app(ReturnService::class);
        $return = $returns->request(StockLot::where('lot_no', 'K-1')->where('warehouse_id', $this->a->id)->sole(), 3, ReturnReason::Damaged, $this->dental, $admin, ['purchase_order_id' => $first->id]);
        $returns->approve($return, $admin);
        $returns->ship($return, $admin);
        $returns->supplierApprove($return, $admin, 'Kabul');
        $returns->complete($return, $admin, 'KN-1', 150);
        $returns->request(StockLot::where('lot_no', 'E-1')->sole(), 5, ReturnReason::Defective, $this->medical, $admin);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function filters(array $input = []): ReportFilters
    {
        return ReportFilters::fromArray($input);
    }

    private function stockQuantity(array $filters = [], ?array $branchIds = null): float
    {
        $reports = app(StockReportService::class);

        return (float) $reports->rows($reports->query($filters)->get(), $filters['warehouse_id'] ?? null, $branchIds)->sum('quantity');
    }

    public function test_the_scenario_leaves_the_expected_stock(): void
    {
        $this->assertSame(59.0, (float) StockLot::where('warehouse_id', $this->a->id)->sum('quantity'));
        $this->assertSame(16.0, (float) StockLot::where('warehouse_id', $this->b->id)->sum('quantity'));
        $this->assertSame(465.0, (float) StockLot::where('warehouse_id', $this->c->id)->sum('quantity'));
    }

    public function test_movement_report_with_filter_combinations(): void
    {
        $report = app(MovementReportService::class);
        $org = $this->organization->id;

        // Eylül + Merkez şubesi: transfer iki depoda (−20/+20), 10 kullanım, −2 sayım, −3 iade.
        $september = $report->totals($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30', 'branch_id' => $this->central->id]), null);
        $this->assertSame(-15.0, $september['net']);
        $this->assertSame(6, $september['count']);
        $this->assertSame(-20.0, $september['byType']['transfer_out']);
        $this->assertSame(20.0, $september['byType']['transfer_in']);
        $this->assertSame(-10.0, $september['byType']['out']);
        $this->assertSame(-2.0, $september['byType']['count_adjust']);
        $this->assertSame(-3.0, $september['byType']['return']);

        // Eylül + Eldiven kategorisi: iptal edilen çıkış ve iptali birbirini sıfırlar, +300 teslim.
        $gloves = $report->totals($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30', 'category_id' => $this->gloves->id]), null);
        $this->assertSame(300.0, $gloves['net']);
        $this->assertSame(-10.0, $gloves['byType']['out']);
        $this->assertSame(10.0, $gloves['byType']['cancel']);

        // Ağustos + Dental Tedarik (ürünün tedarikçisi): 100 giriş, 10 kullanım.
        $this->assertSame(90.0, $report->totals($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31', 'supplier_id' => $this->dental->id]), null)['net']);

        // Ağustos, filtresiz: 300 giriş, 45 çıkış.
        $this->assertSame(255.0, $report->totals($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']), null)['net']);

        // Kesişimi boş kombinasyon: Kuzey şubesinde kompozit hareketi yok.
        $this->assertSame(0, $report->totals($org, $this->filters(['branch_id' => $this->north->id, 'category_id' => $this->composites->id]), null)['count']);

        // Tek depo + tip filtresi: B'ye yalnızca transfer girişi.
        $this->assertSame(20.0, $report->totals($org, $this->filters(['warehouse_id' => $this->b->id]), null, 'transfer_in')['net']);
        $this->assertSame(1, $report->query($org, $this->filters(['warehouse_id' => $this->b->id]), null, 'transfer_in')->count());
    }

    public function test_all_time_movement_net_equals_the_stock_report_for_every_location(): void
    {
        $report = app(MovementReportService::class);
        $org = $this->organization->id;

        foreach ([$this->a, $this->b, $this->c] as $warehouse) {
            $this->assertSame(
                $this->stockQuantity(['warehouse_id' => $warehouse->id]),
                $report->totals($org, $this->filters(['warehouse_id' => $warehouse->id]), null)['net'],
                "{$warehouse->name}: hareket defteri ile stok raporu tutmalı",
            );
        }

        $this->assertSame(75.0, $report->totals($org, $this->filters(['branch_id' => $this->central->id]), null)['net']);
        $this->assertSame(75.0, $this->stockQuantity([], [$this->central->id]));
        $this->assertSame(540.0, $report->totals($org, $this->filters(), null)['net']);
        $this->assertSame(540.0, $this->stockQuantity());

        // Kategori + şube kombinasyonu da tutar.
        $this->assertSame(
            $this->stockQuantity(['category_id' => $this->gloves->id], [$this->north->id]),
            $report->totals($org, $this->filters(['category_id' => $this->gloves->id, 'branch_id' => $this->north->id]), null)['net'],
        );
    }

    public function test_usage_report_with_filter_combinations(): void
    {
        $report = app(UsageReportService::class);
        $org = $this->organization->id;

        $august = $report->totals($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']), null);
        $this->assertSame(['in_quantity' => 300.0, 'usage_quantity' => 40.0, 'usage_cost' => 560.0, 'other_out_quantity' => 5.0, 'transfer_net' => 0.0, 'count_adjust' => 0.0], $august);

        // Eylül: iptal edilen eldiven kullanımı sayılmaz; iade diğer çıkıştır; transfer organizasyon içinde net 0.
        $september = $report->totals($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30']), null);
        $this->assertSame(['in_quantity' => 300.0, 'usage_quantity' => 10.0, 'usage_cost' => 500.0, 'other_out_quantity' => 3.0, 'transfer_net' => 0.0, 'count_adjust' => -2.0], $september);

        // Ürün satırları.
        $rows = $report->query($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']), null)->get()->keyBy('product_name');
        $this->assertSame(['Nitril Eldiven', 'Kompozit A'], $rows->keys()->all(), 'kullanıma göre çoktan aza (30 > 10)');
        $this->assertSame(30.0, $report->normalize($rows['Nitril Eldiven'])['usage_quantity']);
        $this->assertSame(60.0, $report->normalize($rows['Nitril Eldiven'])['usage_cost']);

        // Depo + tarih: Cerrahi Depo'ya 20 transfer girdi, 4 kullanıldı.
        $surgery = $report->totals($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30', 'warehouse_id' => $this->b->id]), null);
        $this->assertSame(20.0, $surgery['transfer_net']);
        $this->assertSame(4.0, $surgery['usage_quantity']);

        // Şube + kategori + tarih: Kuzey'de eylülde eldiven kullanımı yok (iptal edildi), 300 giriş.
        $north = $report->totals($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30', 'branch_id' => $this->north->id, 'category_id' => $this->gloves->id]), null);
        $this->assertSame(0.0, $north['usage_quantity']);
        $this->assertSame(300.0, $north['in_quantity']);

        // Tedarikçi filtresi: Medikal A.Ş. ürünleri, ağustos.
        $this->assertSame(30.0, $report->totals($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31', 'supplier_id' => $this->medical->id]), null)['usage_quantity']);
    }

    public function test_usage_report_agrees_with_the_dashboard_for_the_current_month(): void
    {
        $dashboard = app(DashboardMetricsService::class)->summaryFor($this->organization->id);
        $usage = app(UsageReportService::class)->totals($this->organization->id, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-18']), null);

        $this->assertSame($dashboard['monthlyUsage'], $usage['usage_quantity']);
        $this->assertSame($dashboard['monthlyOtherOut'], $usage['other_out_quantity']);
    }

    public function test_purchase_report_with_filter_combinations(): void
    {
        $report = app(PurchaseReportService::class);
        $org = $this->organization->id;

        $all = $report->metrics($org, $this->filters(), null);
        $this->assertSame(['order_count' => 1, 'ordered_amount' => 5000.0, 'open_amount' => 0.0, 'received_quantity' => 100.0, 'received_amount' => 5000.0, 'return_count' => 1, 'returned_quantity' => 3.0, 'credit_amount' => 150.0], $all[$this->dental->id]);
        $this->assertSame(['order_count' => 1, 'ordered_amount' => 1000.0, 'open_amount' => 400.0, 'received_quantity' => 300.0, 'received_amount' => 570.0, 'return_count' => 1, 'returned_quantity' => 0.0, 'credit_amount' => 0.0], $all[$this->medical->id]);
        $this->assertSame(5570.0, $report->totals($all)['received_amount']);

        // Eylül: Dental Tedarik'in siparişi ağustosta — yalnızca iadesi görünür.
        $september = $report->metrics($org, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30']), null);
        $this->assertSame(0, $september[$this->dental->id]['order_count']);
        $this->assertSame(0.0, $september[$this->dental->id]['received_amount']);
        $this->assertSame(150.0, $september[$this->dental->id]['credit_amount']);
        $this->assertSame(570.0, $september[$this->medical->id]['received_amount']);

        // Şube: Kuzey yalnızca Medikal A.Ş.; kategori: Kompozit yalnızca Dental Tedarik.
        $this->assertSame([$this->medical->id], $report->metrics($org, $this->filters(['branch_id' => $this->north->id]), null)->keys()->all());
        $this->assertSame([$this->dental->id], $report->metrics($org, $this->filters(['category_id' => $this->composites->id]), null)->keys()->all());

        // Tedarikçi + ağustos + depo kombinasyonu.
        $august = $report->metrics($org, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31', 'supplier_id' => $this->dental->id, 'warehouse_id' => $this->a->id]), null);
        $this->assertSame([$this->dental->id], $august->keys()->all());
        $this->assertSame(0, $august[$this->dental->id]['return_count']);

        // Boş kesişim.
        $this->assertTrue($report->metrics($org, $this->filters(['warehouse_id' => $this->b->id]), null)->isEmpty());
    }

    public function test_purchase_report_received_amount_matches_the_booked_stock_entries(): void
    {
        $booked = StockMovement::where('type', 'in')
            ->where('related_entity_type', (new PurchaseReceipt)->getMorphClass())
            ->with('lot')
            ->get()
            ->sum(fn (StockMovement $movement) => (float) $movement->quantity * (float) $movement->lot->unit_cost);

        $this->assertSame(
            round($booked, 2),
            app(PurchaseReportService::class)->totals(app(PurchaseReportService::class)->metrics($this->organization->id, $this->filters(), null))['received_amount'],
        );

        // Açık sipariş tutarı sipariş satırlarının kalanıyla tutar.
        $open = PurchaseOrder::with('lines')->get()->flatMap->lines->sum(fn ($line) => $line->remaining() * (float) $line->unit_price);
        $this->assertSame(400.0, (float) $open);
    }

    public function test_branch_scope_limits_every_report_and_a_foreign_branch_filter_returns_nothing(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $viewer->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => 'own_branch']);
        $scope = $viewer->fresh()->accessibleBranchIds(Module::Reports);
        $org = $this->organization->id;

        $this->assertSame(465.0, app(MovementReportService::class)->totals($org, $this->filters(), $scope)['net']);
        $this->assertSame(0, app(MovementReportService::class)->totals($org, $this->filters(['branch_id' => $this->central->id]), $scope)['count']);
        $this->assertSame(0, app(MovementReportService::class)->totals($org, $this->filters(['warehouse_id' => $this->a->id]), $scope)['count']);
        $this->assertSame(0.0, app(UsageReportService::class)->totals($org, $this->filters(['branch_id' => $this->central->id]), $scope)['usage_quantity']);
        $this->assertSame([$this->medical->id], app(PurchaseReportService::class)->metrics($org, $this->filters(), $scope)->keys()->all());
        $this->assertTrue(app(PurchaseReportService::class)->metrics($org, $this->filters(['branch_id' => $this->central->id]), $scope)->isEmpty());
    }

    public function test_reports_never_include_another_organization(): void
    {
        $foreignOrg = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $foreignBranch = Branch::create(['organization_id' => $foreignOrg->id, 'name' => 'Dış', 'status' => 'active']);
        $foreignWarehouse = Warehouse::create(['branch_id' => $foreignBranch->id, 'name' => 'Dış Depo', 'is_default' => true, 'status' => 'active']);
        $foreignAdmin = User::factory()->create(['organization_id' => $foreignOrg->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($foreignAdmin);
        $foreignProduct = Product::create(['name' => 'Dış Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($foreignProduct, $foreignWarehouse, 999, ['unit_cost' => 1], $foreignAdmin);

        $this->actingAs($this->admin);
        $this->assertSame(540.0, app(MovementReportService::class)->totals($this->organization->id, $this->filters(), null)['net']);
        $this->assertSame(0, app(MovementReportService::class)->totals($foreignOrg->id, $this->filters(['warehouse_id' => $this->a->id]), null)['count']);
    }
}
