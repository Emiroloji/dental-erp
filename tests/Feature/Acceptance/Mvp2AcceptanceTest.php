<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Transfer\Models\TransferRequest;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 16 — MVP 2 Uçtan Uca Kabul Testi.
 *
 * "Çok şubeli/çok depolu bir senaryoda transfer, satın alma ve stok sayımı
 * birlikte test edilir: bir depoya sipariş ile ürün girer, bir kısmı başka bir
 * depoya transfer edilir, ardından bir sayım yapılıp fark düzeltilir — tüm
 * zincir boyunca stok rakamları her adımda doğru kalmalıdır."
 *
 * Tek zincir, kurulum dahil her adım ekranlardan (Livewire sayfaları ve HTTP
 * route'ları). Her adımdan sonra her deponun stoğu beş bağımsız görünümde
 * doğrulanır: lot toplamı, Depo Stokları ekranı, Stok raporu (şube), Stok
 * Hareketleri raporu (tüm zamanların net toplamı) ve Dashboard.
 *
 * Organizasyon: Merkez Şube (Varsayılan Depo = M, Cerrahi Depo = C), Kadıköy Şubesi (K).
 */
class Mvp2AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    private User $merkezKeeper;

    private User $kadikoyKeeper;

    private Branch $merkez;

    private Branch $kadikoy;

    private Warehouse $m;

    private Warehouse $c;

    private Warehouse $k;

    private Product $product;

    private Supplier $supplier;

    public function test_mvp2_end_to_end_acceptance(): void
    {
        $this->setUpOrganizationFromScreens();
        $this->assertStockEverywhere(0, 0, 0, 'kurulum');

        $this->step1_purchase_order_is_received_in_two_partial_deliveries();
        $this->step2_part_of_it_is_transferred_to_another_branch();
        $this->step3_and_to_another_warehouse_of_the_same_branch();
        $this->step4_clinics_use_stock_in_the_receiving_warehouses();
        $this->step5_counts_correct_differences_in_two_branches();
        $this->step6_reports_notifications_and_scope_agree_with_the_chain();
        $this->step7_second_organization_never_leaks_into_mvp2_screens();
    }

    private function setUpOrganizationFromScreens(): void
    {
        $this->seed();
        $this->admin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->actingAs($this->admin);
        $this->m = Warehouse::where('is_default', true)->sole();
        $this->merkez = $this->m->branch;

        Livewire::test('pages::organization.branches')->call('create')->set('name', 'Kadıköy Şubesi')->call('save')->assertHasNoErrors();
        $this->kadikoy = Branch::where('name', 'Kadıköy Şubesi')->sole();
        $this->k = $this->kadikoy->warehouses()->sole();

        Livewire::test('pages::organization.warehouses')->call('create')
            ->set('branch_id', (string) $this->merkez->id)->set('name', 'Cerrahi Depo')->call('save')->assertHasNoErrors();
        $this->c = Warehouse::where('name', 'Cerrahi Depo')->sole();

        Livewire::test('pages::catalog.suppliers')->call('openForm')->set('name', 'Dental Tedarik A.Ş.')->call('save')->assertHasNoErrors();
        $this->supplier = Supplier::sole();

        Livewire::test('pages::catalog.products')->call('openForm')
            ->set('name', 'Kompozit A')->set('base_unit', 'Adet')->set('purchase_price', '48')->set('supplier_id', (string) $this->supplier->id)
            ->call('save')->assertHasNoErrors();
        $this->product = Product::sole();

        $this->buyer = $this->createStaff('Satınalmacı', 'satinalma@dental-erp.test', $this->merkez, ['purchasing']);
        $this->merkezKeeper = $this->createStaff('Merkez Depocusu', 'merkez@dental-erp.test', $this->merkez, ['transfer', 'stock_movement']);
        $this->kadikoyKeeper = $this->createStaff('Kadıköy Sorumlusu', 'kadikoy@dental-erp.test', $this->kadikoy, ['transfer', 'stock_movement']);
    }

    /**
     * 1) Sipariş: 200 adet → Merkez Varsayılan Depo; 150 + 50 iki teslimde.
     */
    private function step1_purchase_order_is_received_in_two_partial_deliveries(): void
    {
        $this->actingAs($this->buyer);
        Livewire::test('pages::purchasing.index')->call('create')
            ->set('supplier_id', (string) $this->supplier->id)->set('warehouse_id', (string) $this->m->id)
            ->set('lines.0.product_id', (string) $this->product->id)->set('lines.0.quantity', '200')
            ->call('save', true)->assertHasNoErrors();
        $order = PurchaseOrder::sole();
        $this->assertStockEverywhere(0, 0, 0, 'sipariş talebi');

        $this->actingAs($this->admin);
        Livewire::test('pages::purchasing.index')->call('approve', $order->id);
        $this->actingAs($this->buyer);
        Livewire::test('pages::purchasing.index')->call('markOrdered', $order->id);
        $this->assertStockEverywhere(0, 0, 0, 'sipariş verildi');

        $line = $order->lines()->sole();
        Livewire::test('pages::purchasing.index')->call('openReceipt', $order->id)
            ->set('invoice_number', 'FTR-1')
            ->set("receiptLines.{$line->id}.quantity", '150')
            ->set("receiptLines.{$line->id}.lot_no", 'KMP-1')
            ->set("receiptLines.{$line->id}.expiry_date", now()->addYear()->toDateString())
            ->call('receive')->assertHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
        $this->assertSame(50.0, $line->fresh()->remaining());
        $this->assertStockEverywhere(150, 0, 0, '1. kısmi teslim');

        Livewire::test('pages::purchasing.index')->call('openReceipt', $order->id)
            ->set('invoice_number', 'FTR-2')
            ->set("receiptLines.{$line->id}.quantity", '50')
            ->set("receiptLines.{$line->id}.lot_no", 'KMP-2')
            ->set("receiptLines.{$line->id}.expiry_date", now()->addYears(2)->toDateString())
            ->call('receive')->assertHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Completed, $order->fresh()->status);
        $this->assertStockEverywhere(200, 0, 0, '2. teslim — sipariş tamamlandı');
    }

    /**
     * 2) Şubeler arası: Kadıköy, Merkez'den 60 adet ister. Stok yalnızca
     *    "Gönderildi"de kaynaktan düşer, "Teslim Alındı"da hedefe eklenir.
     */
    private function step2_part_of_it_is_transferred_to_another_branch(): void
    {
        $this->actingAs($this->kadikoyKeeper);
        Livewire::test('pages::transfer.index')->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('to_warehouse_id', (string) $this->k->id)->set('from_warehouse_id', (string) $this->m->id)
            ->set('quantity', '60')->set('reason', 'Kadıköy stoğu bitti')
            ->call('save')->assertHasNoErrors();
        $transfer = TransferRequest::sole();

        $this->actingAs($this->merkezKeeper);
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id)->call('prepare', $transfer->id);
        $this->assertStockEverywhere(200, 0, 0, 'transfer onaylandı/hazırlanıyor');

        Livewire::test('pages::transfer.index')->call('ship', $transfer->id);
        $this->assertStockEverywhere(140, 0, 0, 'transfer gönderildi (yolda)');

        $this->actingAs($this->kadikoyKeeper);
        Livewire::test('pages::transfer.index')->call('receive', $transfer->id);
        $this->assertStockEverywhere(140, 0, 60, 'transfer teslim alındı');

        // FEFO: SKT'si yakın KMP-1 gönderildi; lot numarası hedefte korunur.
        $this->assertSame(60.0, (float) StockLot::where('warehouse_id', $this->k->id)->where('lot_no', 'KMP-1')->sole()->quantity);
    }

    /**
     * 3) Aynı şube içinde depolar arası: Merkez Varsayılan Depo → Cerrahi Depo 30 adet.
     */
    private function step3_and_to_another_warehouse_of_the_same_branch(): void
    {
        $this->actingAs($this->merkezKeeper);
        Livewire::test('pages::transfer.index')->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('to_warehouse_id', (string) $this->c->id)->set('from_warehouse_id', (string) $this->m->id)
            ->set('quantity', '30')->set('reason', 'Cerrahi hazırlık')
            ->call('save')->assertHasNoErrors();
        $transfer = TransferRequest::where('to_warehouse_id', $this->c->id)->sole();

        // Talep eden kendi talebini onaylayamaz; Admin onaylar ve gönderir.
        $this->actingAs($this->admin);
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id)->call('prepare', $transfer->id)->call('ship', $transfer->id);
        $this->assertStockEverywhere(110, 0, 60, 'depo transferi gönderildi');

        $this->actingAs($this->merkezKeeper);
        Livewire::test('pages::transfer.index')->call('receive', $transfer->id);
        $this->assertStockEverywhere(110, 30, 60, 'depo transferi teslim alındı');
    }

    /**
     * 4) Kullanım: Kadıköy 10, Cerrahi Depo 5.
     */
    private function step4_clinics_use_stock_in_the_receiving_warehouses(): void
    {
        $this->actingAs($this->kadikoyKeeper);
        Livewire::test('pages::stock.out')->call('openForm')
            ->set('product_id', (string) $this->product->id)->set('warehouse_id', (string) $this->k->id)
            ->set('quantity', '10')->set('reasonCategory', StockOutReason::ClinicalUse->value)
            ->call('save')->assertHasNoErrors();
        $this->assertStockEverywhere(110, 30, 50, 'Kadıköy kullanımı');

        $this->actingAs($this->merkezKeeper);
        Livewire::test('pages::stock.out')->call('openForm')
            ->set('product_id', (string) $this->product->id)->set('warehouse_id', (string) $this->c->id)
            ->set('quantity', '5')->set('reasonCategory', StockOutReason::ClinicalUse->value)
            ->call('save')->assertHasNoErrors();
        $this->assertStockEverywhere(110, 25, 50, 'Cerrahi kullanımı');
    }

    /**
     * 5) Sayım: Merkez Varsayılan Depo'da KMP-1 60 yerine 58 (−2, kayıp);
     *    Kadıköy'de 50 yerine 51 (+1, kayıt hatası). Onaya kadar stok değişmez.
     */
    private function step5_counts_correct_differences_in_two_branches(): void
    {
        $this->actingAs($this->merkezKeeper);
        $merkezCount = $this->startCount($this->m);
        $this->assertSame([50.0, 60.0], $merkezCount->lines()->orderBy('system_quantity')->pluck('system_quantity')->map(fn ($q) => (float) $q)->all());

        $entries = Livewire::test('pages::inventory.show', ['count' => $merkezCount->id]);
        foreach ($merkezCount->lines()->with('lot')->get() as $line) {
            $counted = $line->lot->lot_no === 'KMP-1' ? '58' : '50';
            $entries->set("entries.{$line->id}.counted_quantity", $counted);
            if ($counted === '58') {
                $entries->set("entries.{$line->id}.reason", 'loss')->set("entries.{$line->id}.note", 'Raf altında hasarlı ambalaj');
            }
        }
        $entries->call('submit');
        $this->assertSame(StockCountStatus::PendingApproval, $merkezCount->fresh()->status);

        $this->actingAs($this->kadikoyKeeper);
        $kadikoyCount = $this->startCount($this->k);
        $line = $kadikoyCount->lines()->sole();
        Livewire::test('pages::inventory.show', ['count' => $kadikoyCount->id])
            ->set("entries.{$line->id}.counted_quantity", '51')
            ->set("entries.{$line->id}.reason", 'record_error')
            ->set("entries.{$line->id}.note", 'Bir kullanım iki kez girilmiş')
            ->call('submit');
        $this->assertStockEverywhere(110, 25, 50, 'sayımlar onay bekliyor');

        $this->actingAs($this->admin);
        Livewire::test('pages::inventory.show', ['count' => $merkezCount->id])->call('approve');
        $this->assertStockEverywhere(108, 25, 50, 'Merkez sayımı onaylandı');
        Livewire::test('pages::inventory.show', ['count' => $kadikoyCount->id])->call('approve');
        $this->assertStockEverywhere(108, 25, 51, 'Kadıköy sayımı onaylandı');
    }

    /**
     * 6) Zincirin sonunda raporlar, bildirimler ve yetki kapsamı tutarlı.
     */
    private function step6_reports_notifications_and_scope_agree_with_the_chain(): void
    {
        $this->actingAs($this->admin);
        $allTime = ['from' => '', 'to' => ''];

        // Hareket defteri: 200 giriş, ±90 transfer, −15 kullanım, −1 net sayım farkı = 184.
        Livewire::test('pages::reports.movements')->set($allTime)
            ->assertViewHas('totals', fn (array $totals) => $totals['byType']['in'] === 200.0
                && $totals['byType']['transfer_out'] === -90.0 && $totals['byType']['transfer_in'] === 90.0
                && $totals['byType']['out'] === -15.0 && $totals['byType']['count_adjust'] === -1.0
                && $totals['net'] === 184.0);

        // Kullanım 15 (sayım farkı ve transfer kullanım değildir); maliyet 15 × 48.
        Livewire::test('pages::reports.usage')->set($allTime)
            ->assertViewHas('totals', fn (array $totals) => $totals['usage_quantity'] === 15.0 && $totals['usage_cost'] === 720.0
                && $totals['transfer_net'] === 0.0 && $totals['count_adjust'] === -1.0);
        Livewire::test('pages::reports.usage')->set($allTime)->set('branchId', (string) $this->kadikoy->id)
            ->assertViewHas('totals', fn (array $totals) => $totals['usage_quantity'] === 10.0 && $totals['transfer_net'] === 60.0 && $totals['count_adjust'] === 1.0);

        // Satın alma: tek sipariş, 200 teslim, açık kalan yok.
        Livewire::test('pages::reports.purchasing')->set($allTime)
            ->assertViewHas('totals', fn (array $totals) => $totals['order_count'] === 1 && $totals['received_quantity'] === 200.0
                && $totals['received_amount'] === 9600.0 && $totals['open_amount'] === 0.0);

        // Dashboard: bu ayın kullanımı ve stok değeri (184 × 48).
        Livewire::test('pages::dashboard')->assertViewHas('summary', fn (array $summary) => $summary['monthlyUsage'] === 15.0 && $summary['totalStockValue'] === 8832.0);

        // Bildirimler zincirin onay noktalarında doğru kişilere düştü.
        $titles = fn (User $user) => $user->fresh()->notifications()->where('type', WorkflowNotification::class)->get()->pluck('data.title');
        $this->assertContains('Satın alma talebi onay bekliyor', $titles($this->admin));
        $this->assertSame(2, $titles($this->admin)->filter(fn ($title) => $title === 'Sayım tamamlandı — onay bekliyor')->count());
        $this->assertContains('Satın alma talebi onaylandı', $titles($this->buyer));
        $this->assertContains('Yeni transfer talebi', $titles($this->merkezKeeper));
        $this->assertContains('Transfer gönderildi — teslim alın', $titles($this->kadikoyKeeper));
        $this->assertContains('Sayım onaylandı', $titles($this->kadikoyKeeper));

        // Kapsam: Kadıköy sorumlusu Merkez'in sayımını ve stoğunu göremez.
        $this->actingAs($this->kadikoyKeeper);
        $merkezCount = StockCount::where('warehouse_id', $this->m->id)->sole();
        $this->get("/stok-sayimi/{$merkezCount->id}")->assertNotFound();
        $this->get('/stok-sayimi')->assertOk()->assertDontSee($merkezCount->number());
        $this->get('/stok-hareketleri')->assertOk()->assertDontSee('Cerrahi Depo');
        $this->get('/satin-alma')->assertForbidden();
    }

    /**
     * 7) İzolasyon (fazlar-adimlar.md "izolasyon testi her büyük aşamadan sonra
     *    tekrarlanır"): ikinci organizasyonun MVP 2 verisi hiçbir ekrana sızmaz.
     */
    private function step7_second_organization_never_leaks_into_mvp2_screens(): void
    {
        $rival = Organization::create(['name' => 'Rakip Klinik', 'status' => 'active', 'plan' => 'starter']);
        $rivalBranch = Branch::create(['organization_id' => $rival->id, 'name' => 'Rakip Şube', 'status' => 'active']);
        $rivalWarehouse = Warehouse::create(['branch_id' => $rivalBranch->id, 'name' => 'Rakip Depo', 'is_default' => true, 'status' => 'active']);
        $rivalAdmin = User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active', 'name' => 'Rakip Admin']);
        $this->actingAs($rivalAdmin);
        $rivalSupplier = Supplier::create(['name' => 'Rakip Tedarikçi', 'status' => 'active']);
        $rivalProduct = Product::create(['name' => 'Rakip Kompozit', 'base_unit' => 'Adet', 'supplier_id' => $rivalSupplier->id, 'status' => 'active']);
        app(StockMovementService::class)->in($rivalProduct, $rivalWarehouse, 500, ['lot_no' => 'RAKIP-LOT', 'unit_cost' => 1], $rivalAdmin);

        $this->actingAs($this->admin);
        foreach (['/transferler', '/satin-alma', '/stok-sayimi', '/iadeler', '/depo-stoklari', '/raporlar', '/raporlar/hareketler', '/raporlar/kullanim', '/raporlar/satin-alma', '/bildirimler', '/dashboard'] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('Rakip Kompozit')->assertDontSee('RAKIP-LOT')->assertDontSee('Rakip Depo')
                ->assertDontSee('Rakip Şube')->assertDontSee('Rakip Tedarikçi');
        }

        Livewire::test('pages::reports.movements')->set(['from' => '', 'to' => ''])->assertViewHas('totals', fn (array $totals) => $totals['net'] === 184.0);
        $this->assertStockEverywhere(108, 25, 51, 'rakip organizasyon eklendikten sonra');
    }

    /**
     * Bir deponun stoğunu beş bağımsız görünümde doğrular (Admin gözüyle).
     */
    private function assertStockEverywhere(float $m, float $c, float $k, string $step): void
    {
        $actor = auth()->user();
        $this->actingAs($this->admin);
        $expected = [$this->m->id => $m, $this->c->id => $c, $this->k->id => $k];

        foreach ($expected as $warehouseId => $quantity) {
            // 1. Lot toplamı (tek gerçek kaynak).
            $this->assertSame($quantity, (float) StockLot::where('warehouse_id', $warehouseId)->sum('quantity'), "{$step}: lot toplamı #{$warehouseId}");

            // 2. Stok Hareketleri raporu: tüm zamanların net toplamı = stok.
            Livewire::test('pages::reports.movements')->set(['from' => '', 'to' => '', 'warehouseId' => (string) $warehouseId])
                ->assertViewHas('totals', fn (array $totals) => $totals['net'] === $quantity);
        }

        foreach ([$this->merkez->id => $m + $c, $this->kadikoy->id => $k] as $branchId => $branchTotal) {
            // 3. Depo Stokları ekranı: depo kırılımı ve şube toplamı.
            Livewire::test('pages::reports.warehouse-stock')->set('branchId', (string) $branchId)
                ->assertViewHas('report', fn (array $report) => collect($report['warehouseTotals'])->only(array_keys($expected))->every(fn ($value, $id) => (float) $value === $expected[$id]))
                ->assertViewHas('branchSummaries', fn ($summaries) => (float) collect($summaries)->firstWhere('id', $branchId)['quantity'] === $branchTotal);

            // 4. Stok raporu, şube filtresiyle.
            Livewire::test('pages::reports.stock')->set('branchId', (string) $branchId)
                ->assertViewHas('rows', fn ($rows) => (float) $rows->getCollection()->sum('quantity') === $branchTotal);
        }

        // 5. Dashboard (cache'e rağmen güncel).
        Livewire::test('pages::dashboard')->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === $m + $c + $k);

        // Hareket defterinin toplamı (organizasyon ürünleri üzerinden kapsamlı) = toplam stok.
        $this->assertSame($m + $c + $k, round((float) StockMovement::whereHas('lot.product')->sum('quantity'), 2), "{$step}: hareket defteri toplamı");

        $this->actingAs($actor);
    }

    private function startCount(Warehouse $warehouse): StockCount
    {
        Livewire::test('pages::inventory.index')->call('openStart')->set('warehouse_id', (string) $warehouse->id)->call('start')->assertHasNoErrors();

        return StockCount::where('warehouse_id', $warehouse->id)->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function createStaff(string $name, string $email, Branch $branch, array $modules): User
    {
        $form = Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', $name)->set('email', $email)->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $branch->id);

        foreach ($modules as $module) {
            $form->set("modules.{$module}.read", true)->set("modules.{$module}.write", true);
        }

        $form->call('save')->assertHasNoErrors();

        return User::where('email', $email)->sole();
    }
}
