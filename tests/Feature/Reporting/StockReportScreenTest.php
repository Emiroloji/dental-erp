<?php

namespace Tests\Feature\Reporting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Exports\StockReportExport;
use App\Domain\Reporting\Services\StockReportService;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StockReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private User $admin;

    private Category $category;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->category = Category::create(['organization_id' => $this->organization->id, 'name' => 'Dolgu Malzemeleri', 'status' => 'active']);
        $this->supplier = Supplier::create(['organization_id' => $this->organization->id, 'name' => 'Acme Dental', 'status' => 'active']);
    }

    private function productWithStock(string $name, float $quantity, ?int $categoryId = null): Product
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'category_id' => $categoryId,
            'name' => $name,
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'purchase_price' => 10,
            'status' => 'active',
        ]);

        StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'lot_no' => 'LOT-'.$product->id,
            'unit_cost' => 2,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    public function test_report_page_requires_reports_permission(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/raporlar')->assertForbidden();
    }

    public function test_admin_sees_products_on_the_report_page(): void
    {
        $this->productWithStock('Kompozit A', 100);

        $this->actingAs($this->admin);

        Livewire::test('pages::reports.stock')->assertSee('Kompozit A');
    }

    public function test_category_filter_narrows_the_results(): void
    {
        $this->productWithStock('Kompozit A', 100, $this->category->id);
        $this->productWithStock('Eldiven', 100);

        $this->actingAs($this->admin);

        Livewire::test('pages::reports.stock')
            ->set('categoryId', (string) $this->category->id)
            ->assertSee('Kompozit A')
            ->assertDontSee('Eldiven');
    }

    public function test_search_filter_narrows_the_results(): void
    {
        $this->productWithStock('Kompozit A', 100);
        $this->productWithStock('Eldiven', 100);

        $this->actingAs($this->admin);

        Livewire::test('pages::reports.stock')
            ->set('search', 'kompozit')
            ->assertSee('Kompozit A')
            ->assertDontSee('Eldiven');
    }

    public function test_report_rows_are_isolated_per_organization(): void
    {
        $this->productWithStock('Kendi Ürünüm', 10);

        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $otherOrganization->id, 'name' => 'Merkez', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        Product::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Başka Ürün',
            'base_unit' => 'Adet',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);

        Livewire::test('pages::reports.stock')->assertDontSee('Başka Ürün');
    }

    public function test_excel_export_downloads_the_filtered_rows(): void
    {
        Storage::fake('local');
        Excel::fake();

        $this->productWithStock('Kompozit A', 100, $this->category->id);
        $this->productWithStock('Eldiven', 50);

        $this->actingAs($this->admin);

        Livewire::test('pages::reports.stock')
            ->set('categoryId', (string) $this->category->id)
            ->call('export', 'xlsx');

        Excel::assertExportedInRaw(StockReportExport::class, function (StockReportExport $export) {
            return $export->collection()->count() === 1
                && $export->collection()->first()['product']->name === 'Kompozit A';
        });
    }

    public function test_pdf_export_content_is_a_pdf_for_the_filtered_rows(): void
    {
        $this->productWithStock('Kompozit A', 100);

        $this->actingAs($this->admin);

        $this->assertStringStartsWith('%PDF', app(StockReportService::class)->pdfContent(['search' => 'Kompozit']));
    }

    public function test_stock_report_screen_shows_staff_with_read_only_stock_permission(): void
    {
        // reports.viewAny yalnızca Reports modülüne bağlı — stok modülü izni
        // rapor ekranına erişim sağlamaz, bu da izin sisteminin modül bazında
        // gerçekten ayrıştığını doğrular.
        $staffWithStockOnly = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create([
            'user_id' => $staffWithStockOnly->id,
            'module' => Module::StockMovement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);

        $this->actingAs($staffWithStockOnly)->get('/raporlar')->assertForbidden();

        Permission::create([
            'user_id' => $staffWithStockOnly->id,
            'module' => Module::Reports->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);

        // The first request already lazy-loaded and cached the (then incomplete)
        // permissions relation on this in-memory instance — fetch a fresh one so
        // the newly created Reports permission is actually seen.
        $this->actingAs($staffWithStockOnly->fresh())->get('/raporlar')->assertOk();
    }
}
