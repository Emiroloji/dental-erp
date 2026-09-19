<?php

namespace Tests\Feature\Reporting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Reporting\Exports\TableExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class AdvancedReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $central;

    private Branch $north;

    private Warehouse $centralWarehouse;

    private Warehouse $northWarehouse;

    private User $admin;

    private Category $category;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-18 12:00:00');

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $this->northWarehouse = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->category = Category::create(['name' => 'Kompozit', 'status' => 'active']);
        $this->supplier = Supplier::create(['name' => 'Dental Tedarik', 'status' => 'active']);
        $composite = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'category_id' => $this->category->id, 'supplier_id' => $this->supplier->id, 'status' => 'active', 'min_stock' => 0]);
        $glove = Product::create(['name' => 'Nitril Eldiven', 'base_unit' => 'Adet', 'status' => 'active', 'min_stock' => 0]);

        $stock = app(StockMovementService::class);
        $stock->in($composite, $this->centralWarehouse, 50, ['lot_no' => 'K-1', 'unit_cost' => 40], $this->admin);
        $stock->out($composite, $this->centralWarehouse, 5, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);
        $stock->in($glove, $this->northWarehouse, 80, ['lot_no' => 'E-1', 'unit_cost' => 2], $this->admin);
        $stock->out($glove, $this->northWarehouse, 12, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);

        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->supplier, $this->centralWarehouse, [['product_id' => $composite->id, 'quantity' => 10, 'unit_price' => 40]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
    }

    public function test_every_report_tab_renders_with_the_current_month_by_default(): void
    {
        foreach (['/raporlar', '/raporlar/hareketler', '/raporlar/kullanim', '/raporlar/satin-alma'] as $url) {
            $this->get($url)->assertOk()->assertSee('Stok Hareketleri')->assertSee('Kullanım ve Maliyet')->assertSee('Satın Alma ve İade');
        }

        Livewire::test('pages::reports.movements')
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-09-18')
            ->assertSee('Kompozit A')
            ->assertSee('Nitril Eldiven');
    }

    public function test_filters_narrow_each_report_on_screen(): void
    {
        Livewire::test('pages::reports.movements')
            ->set('branchId', (string) $this->north->id)
            ->assertSee('Nitril Eldiven')->assertDontSee('Kompozit A')
            ->assertViewHas('totals', fn (array $totals) => $totals['net'] === 68.0)
            ->set('branchId', '')
            ->set('categoryId', (string) $this->category->id)
            ->assertSee('Kompozit A')->assertDontSee('Nitril Eldiven')
            ->set('type', 'out')
            ->assertViewHas('totals', fn (array $totals) => $totals['net'] === -5.0)
            ->set('from', '2026-10-01')->set('to', '2026-10-31')
            ->assertSee('Bu filtrelerle hareket bulunamadı.');

        Livewire::test('pages::reports.usage')
            ->assertViewHas('totals', fn (array $totals) => $totals['usage_quantity'] === 17.0 && $totals['usage_cost'] === 224.0)
            ->set('supplierId', (string) $this->supplier->id)
            ->assertViewHas('totals', fn (array $totals) => $totals['usage_quantity'] === 5.0 && $totals['usage_cost'] === 200.0)
            ->assertSee('Kompozit A')->assertDontSee('Nitril Eldiven');

        Livewire::test('pages::reports.purchasing')
            ->assertSee('Dental Tedarik')
            ->assertViewHas('totals', fn (array $totals) => $totals['order_count'] === 1 && $totals['ordered_amount'] === 400.0)
            ->set('branchId', (string) $this->north->id)
            ->assertSee('Bu filtrelerle satın alma veya iade bulunamadı.');

        // Stok durumu: şube filtresi (tarih filtresi yok).
        Livewire::test('pages::reports.stock')
            ->set('branchId', (string) $this->north->id)
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->sum('quantity') === 68.0);
    }

    public function test_changing_the_branch_resets_the_warehouse_and_clear_restores_defaults(): void
    {
        Livewire::test('pages::reports.movements')
            ->set('warehouseId', (string) $this->centralWarehouse->id)
            ->set('branchId', (string) $this->north->id)
            ->assertSet('warehouseId', '')
            ->assertViewHas('options', fn (array $options) => $options['warehouses']->pluck('id')->all() === [$this->northWarehouse->id])
            ->set('from', '2026-01-01')
            ->call('clearFilters')
            ->assertSet('branchId', '')
            ->assertSet('from', '2026-09-01');
    }

    public function test_reports_export_to_excel_and_pdf(): void
    {
        // Dışa aktarım kuyruğa alınır (testte kuyruk senkron çalışır) ve dosya depolanır.
        Storage::fake('local');
        Excel::fake();

        Livewire::test('pages::reports.movements')->call('export', 'xlsx')->assertSee('Rapor hazırlanıyor');
        Excel::assertExportedInRaw(TableExport::class, fn (TableExport $export) => $export->collection()->count() === 5 && $export->headings()[0] === 'Tarih');

        Livewire::test('pages::reports.usage')->set('categoryId', (string) $this->category->id)->call('export', 'xlsx');
        Excel::assertExportedInRaw(TableExport::class, fn (TableExport $export) => $export->collection()->first()[0] === 'Kompozit A' && $export->collection()->last()[0] === 'Toplam');

        Livewire::test('pages::reports.purchasing')->call('export', 'xlsx');
        Excel::assertExportedInRaw(TableExport::class, fn (TableExport $export) => $export->collection()->first()[0] === 'Dental Tedarik');

        Livewire::test('pages::reports.usage')->call('export', 'pdf');
        Livewire::test('pages::reports.stock')->call('export', 'pdf');

        $exports = ReportExport::orderBy('id')->get();
        $this->assertCount(5, $exports);
        $this->assertTrue($exports->every(fn (ReportExport $export) => $export->status === ReportExportStatus::Completed));
        $this->assertSame(['stok-hareket-raporu', 'kullanim-maliyet-raporu', 'satin-alma-iade-raporu', 'kullanim-maliyet-raporu', 'stok-raporu'], $exports->map(fn ($export) => $export->report->fileBaseName())->all());

        foreach ($exports->where('format', 'pdf') as $pdf) {
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($pdf->file_path));
        }
    }

    public function test_branch_scoped_viewer_only_sees_own_branch_and_a_crafted_branch_filter_returns_nothing(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $viewer->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => 'own_branch']);
        $this->actingAs($viewer->fresh());

        Livewire::test('pages::reports.movements')
            ->assertSee('Nitril Eldiven')->assertDontSee('Kompozit A')
            ->assertViewHas('options', fn (array $options) => $options['branches']->pluck('id')->all() === [$this->north->id])
            ->set('branchId', (string) $this->central->id)
            ->assertViewHas('totals', fn (array $totals) => $totals['count'] === 0);

        Livewire::test('pages::reports.purchasing')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);

        // Raporlar yetkisi olmayan personel giremez.
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']));
        $this->get('/raporlar/hareketler')->assertForbidden();
        $this->get('/raporlar/kullanim')->assertForbidden();
        $this->get('/raporlar/satin-alma')->assertForbidden();
    }
}
