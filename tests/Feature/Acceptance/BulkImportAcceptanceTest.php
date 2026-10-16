<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Import\BuildsSpreadsheets;
use Tests\TestCase;

/**
 * Aşama 38 kabul senaryosu: yeni klinik şablonları doldurur, önce ürünleri
 * sonra açılış stoğunu içe aktarır; hatalı dosya hiçbir şey yazmaz; stok
 * durumu ve diğer organizasyon etkilenmez.
 */
class BulkImportAcceptanceTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    public function test_new_clinic_moves_catalog_and_stock_in_with_templates(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        Product::create(['organization_id' => $other->id, 'name' => 'Onların', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($admin);
        $products = app(ProductImporter::class)->headings();
        $stock = app(OpeningStockImporter::class)->headings();
        $productRow = fn (array $values) => array_values([...array_fill_keys($products, ''), ...$values]);
        $stockRow = fn (array $values) => array_values([...array_fill_keys($stock, ''), ...$values]);
        $upload = fn (array $rows) => UploadedFile::fake()->createWithContent('dosya.xlsx', file_get_contents($this->spreadsheet($rows)));

        // 1. Şablonlar indirilebilir.
        Excel::fake();
        Livewire::test('pages::import.index')->call('downloadTemplate');
        Excel::assertDownloaded('urun-sablonu.xlsx');

        // 2. Hatalı ürün dosyası: önizleme hatayı gösterir, hiçbir şey yazılmaz.
        Livewire::test('pages::import.index')
            ->set('file', $upload([$products,
                $productRow(['Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', 'Kategori' => 'Sarf']),
                $productRow(['Ürün Adı' => 'Aşı', 'Ürün Kodu' => 'ASI', 'Ana Birim' => 'Adet', 'Soğuk Zincir' => 'E']),
            ]))
            ->assertSet('preview.errorCount', 1)
            ->call('import');
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());

        // 3. Düzeltilmiş dosya içe aktarılır.
        Livewire::test('pages::import.index')
            ->set('file', $upload([$products,
                $productRow(['Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', 'Kategori' => 'Sarf', 'Tedarikçi' => 'Medikal']),
                $productRow(['Ürün Adı' => 'Aşı', 'Ürün Kodu' => 'ASI', 'Ana Birim' => 'Adet', 'Soğuk Zincir' => 'E', 'Min Sıcaklık' => '2', 'Max Sıcaklık' => '8', 'Ürün Türü' => 'İlaç']),
                $productRow(['Ürün Adı' => 'İmplant', 'Ürün Kodu' => 'IMP', 'Ana Birim' => 'Adet', 'Seri Takipli' => 'E', 'Ürün Türü' => 'Ekipman']),
            ]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertSee('3 ürün içe aktarıldı.');
        $this->assertSame(3, Product::count());
        $this->assertSame(1, Supplier::count());

        // 4. Açılış stoğu.
        Livewire::test('pages::import.index')
            ->call('selectTab', 'opening-stock')
            ->set('file', $upload([$stock,
                $stockRow(['Ürün Kodu' => 'ENJ-01', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '40', 'Lot No' => 'L1', 'SKT' => '31.03.2027', 'Birim Maliyet' => '12,50']),
                $stockRow(['Ürün Kodu' => 'ASI', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '10', 'Sıcaklık' => '4']),
                $stockRow(['Ürün Kodu' => 'IMP', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '2', 'Seri Numaraları' => 'S-1; S-2']),
            ]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertSee('3 satır açılış stoğu içe aktarıldı.');

        $this->assertSame(3, StockMovement::where('reason', OpeningStockImporter::REASON)->count());
        $this->assertEquals(40, StockLot::whereHas('product', fn ($q) => $q->where('code', 'ENJ-01'))->sole()->quantity);
        $this->assertSame(2, StockSerial::count());

        // 5. Diğer organizasyonun ürünü değişmedi.
        $this->assertSame(1, Product::withoutGlobalScopes()->where('organization_id', $other->id)->count());
    }

    public function test_downloaded_templates_read_back_with_a_valid_example_row(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        foreach ([ProductImporter::class, OpeningStockImporter::class] as $class) {
            $importer = app($class);
            $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
            file_put_contents($path, Excel::raw($importer->template($admin), \Maatwebsite\Excel\Excel::XLSX));

            $result = $importer->validate((new SpreadsheetReader)->read($path, 'xlsx', $importer->headings()), $admin);

            $this->assertTrue($result->isValid(), $class.': '.implode("\n", $result->errorMessages()));
            $importer->import($result, $admin);
        }

        $this->assertSame(1, StockMovement::count());
    }
}
