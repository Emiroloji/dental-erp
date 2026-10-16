<?php

namespace Tests\Feature\Import;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImporterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private ProductImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->importer = app(ProductImporter::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [...array_fill_keys($this->importer->headings(), ''), 'Ürün Adı' => 'Enjektör 2ml', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', ...$overrides];
    }

    public function test_valid_rows_are_imported_with_new_categories_and_suppliers(): void
    {
        Category::create(['name' => 'Sarf', 'status' => 'active']);

        $result = $this->importer->validate([
            2 => $this->row(['Kategori' => ' sarf ', 'Tedarikçi' => 'Medikal A.Ş.', 'Alış Fiyatı' => '1.250,50', 'Min Stok' => '5', 'Ürün Türü' => 'İlaç', 'Kontrollü' => 'E']),
            3 => $this->row(['Ürün Adı' => 'Kompozit', 'Ürün Kodu' => 'KMP-01', 'Kategori' => 'Dolgu', 'Tedarikçi' => 'medikal a.ş.']),
        ], $this->admin);

        $this->assertTrue($result->isValid(), implode("\n", $result->errorMessages()));
        $this->assertSame(['Kategori' => ['Dolgu'], 'Tedarikçi' => ['Medikal A.Ş.']], $result->creates);

        $this->assertSame(2, $this->importer->import($result, $this->admin));

        $product = Product::where('code', 'ENJ-01')->sole();
        $this->assertSame('Sarf', $product->category->name);
        $this->assertSame('Medikal A.Ş.', $product->supplier->name);
        $this->assertEquals(1250.50, $product->purchase_price);
        $this->assertSame(5, $product->min_stock);
        $this->assertSame('medicine', $product->product_type->value);
        $this->assertTrue($product->is_controlled);
        $this->assertSame('active', $product->status);
        $this->assertSame(1, Supplier::count());
        $this->assertSame(2, Category::count());
        $this->assertSame($this->organization->id, $product->organization_id);
    }

    public function test_required_fields_and_formats_are_reported_per_row(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Ürün Adı' => '', 'Ürün Kodu' => '', 'Ana Birim' => '']),
            3 => $this->row(['Ürün Kodu' => 'X-1', 'Alış Fiyatı' => 'on lira', 'Min Stok' => '2,5', 'Ürün Türü' => 'Cihaz', 'Soğuk Zincir' => 'belki', 'GTIN' => '123']),
        ], $this->admin);

        $this->assertFalse($result->isValid());
        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Ürün Adı', $messages);
        $this->assertStringContainsString('Satır 2 · Ürün Kodu', $messages);
        $this->assertStringContainsString('Satır 2 · Ana Birim', $messages);
        $this->assertStringContainsString('Satır 3 · Alış Fiyatı', $messages);
        $this->assertStringContainsString('Satır 3 · Min Stok', $messages);
        $this->assertStringContainsString('Satır 3 · Ürün Türü', $messages);
        $this->assertStringContainsString('Satır 3 · Soğuk Zincir', $messages);
        $this->assertStringContainsString('Satır 3 · GTIN', $messages);
    }

    public function test_duplicate_codes_in_file_and_in_system_are_errors(): void
    {
        Product::create(['name' => 'Eski', 'code' => 'ESK-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'A-1']),
            3 => $this->row(['Ürün Kodu' => ' a-1 ']),
            4 => $this->row(['Ürün Kodu' => 'esk-01']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 3 · Ürün Kodu: Dosyada satır 2 ile aynı kod.', $messages);
        $this->assertStringContainsString('Satır 4 · Ürün Kodu: Bu kodla kayıtlı bir ürün zaten var.', $messages);
        $this->assertStringNotContainsString('Satır 2', $messages);
    }

    public function test_another_organizations_code_does_not_conflict(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        Product::create(['organization_id' => $other->id, 'name' => 'Onların', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->assertTrue($this->importer->validate([2 => $this->row()], $this->admin)->isValid());
    }

    public function test_cold_chain_requires_a_temperature_range(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Soğuk Zincir' => 'E']),
            3 => $this->row(['Ürün Kodu' => 'B-1', 'Soğuk Zincir' => 'Evet', 'Min Sıcaklık' => '8', 'Max Sıcaklık' => '2']),
            4 => $this->row(['Ürün Kodu' => 'C-1', 'Soğuk Zincir' => 'E', 'Min Sıcaklık' => '2', 'Max Sıcaklık' => '8']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Min Sıcaklık', $messages);
        $this->assertStringContainsString('Satır 3 · Max Sıcaklık', $messages);
        $this->assertStringNotContainsString('Satır 4', $messages);
    }

    public function test_duplicate_barcode_and_gtin_in_file_are_errors(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'A', 'Barkod' => '111', 'GTIN' => '08699999999994']),
            3 => $this->row(['Ürün Kodu' => 'B', 'Barkod' => '111', 'GTIN' => '8699999999994']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 3 · Barkod: Dosyada satır 2 ile aynı barkod.', $messages);
        $this->assertStringContainsString('Satır 3 · GTIN: Dosyada satır 2 ile aynı GTIN.', $messages);
    }

    public function test_nothing_is_written_when_any_row_is_invalid(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Kategori' => 'Yeni']),
            3 => $this->row(['Ürün Kodu' => 'B', 'Ana Birim' => '']),
        ], $this->admin);

        $this->expectException(\LogicException::class);

        try {
            $this->importer->import($result, $this->admin);
        } finally {
            $this->assertSame(0, Product::count());
            $this->assertSame(0, Category::count());
        }
    }

    public function test_template_has_all_headings_and_an_example(): void
    {
        $sheets = $this->importer->template($this->admin)->sheets();

        $this->assertSame($this->importer->headings(), $sheets[0]->headings());
        $this->assertCount(1, $sheets[0]->collection());
        $this->assertSame('Açıklama', $sheets[1]->title());
    }
}
