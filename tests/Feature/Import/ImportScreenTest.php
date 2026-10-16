<?php

namespace Tests\Feature\Import;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportScreenTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    private function upload(array $rows, string $name = 'dosya.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents($this->spreadsheet($rows)));
    }

    private function staff(Module $module, bool $write): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    public function test_page_requires_a_write_permission(): void
    {
        $this->actingAs($this->staff(Module::ProductManagement, false))->get('/veri-aktarimi')->assertForbidden();
        $this->actingAs($this->admin)->get('/veri-aktarimi')->assertOk()->assertSee('Veri Aktarımı');
    }

    public function test_tabs_follow_their_own_permission(): void
    {
        $this->actingAs($this->staff(Module::StockMovement, true));

        Livewire::test('pages::import.index')
            ->assertSet('tab', 'opening-stock')
            ->call('selectTab', 'products')
            ->assertForbidden();
    }

    public function test_template_download(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::import.index')
            ->call('downloadTemplate')
            ->assertFileDownloaded('urun-sablonu.xlsx');
    }

    public function test_preview_lists_errors_and_blocks_import(): void
    {
        $this->actingAs($this->admin);
        $headings = app(ProductImporter::class)->headings();

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([$headings, array_replace(array_fill(0, count($headings), ''), [0 => 'Enjektör'])]))
            ->assertSet('preview.errorCount', 1)
            ->assertSee('Ürün Kodu alanı zorunludur.')
            ->call('import')
            ->assertHasErrors('file');

        $this->assertSame(0, Product::count());
    }

    public function test_missing_columns_are_shown_as_a_file_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([['Ürün Adı'], ['Enjektör']]))
            ->assertHasErrors('file')
            ->assertSet('preview', null);
    }

    public function test_products_then_opening_stock_are_imported(): void
    {
        $this->actingAs($this->admin);
        $productHeadings = app(ProductImporter::class)->headings();
        $product = array_combine($productHeadings, array_fill(0, count($productHeadings), ''));
        $product = [...$product, 'Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet'];

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([$productHeadings, array_values($product)]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('preview', null)
            ->assertSee('1 ürün içe aktarıldı.')
            ->call('selectTab', 'opening-stock')
            ->set('file', $this->upload([
                ['Ürün Kodu', 'Şube', 'Depo', 'Miktar', 'Lot No', 'SKT', 'Birim Maliyet', 'Sıcaklık', 'Seri Numaraları'],
                ['ENJ-01', 'Merkez', 'Ana Depo', '40', 'L1', '31.03.2027', '12,50', '', ''],
            ]))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('1 satır açılış stoğu içe aktarıldı.');

        $this->assertEquals(40, StockLot::sole()->quantity);
    }
}
