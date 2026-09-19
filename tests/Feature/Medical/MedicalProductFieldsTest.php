<?php

namespace Tests\Feature\Medical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 26 — ürün kartındaki ilaç/medikal alanları ve GS1 okutma.
 */
class MedicalProductFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    public function test_medicine_is_created_with_regulatory_fields_and_gtin_is_normalized(): void
    {
        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'Artikain Anestezik')
            ->set('product_type', 'medicine')
            ->set('gtin', '4006381333931')
            ->set('uts_number', 'UTS-123')
            ->set('license_number', 'RUH-2024/15')
            ->set('manufacturer', 'Örnek İlaç A.Ş.')
            ->set('storage_condition', 'Işıktan uzak')
            ->set('cold_chain', true)
            ->set('storage_min_temp', '2')
            ->set('storage_max_temp', '8')
            ->set('is_controlled', true)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::sole();
        $this->assertSame('04006381333931', $product->gtin);
        $this->assertSame('RUH-2024/15', $product->license_number);
        $this->assertTrue($product->cold_chain);
        $this->assertSame(2.0, $product->storage_min_temp);
        $this->assertSame('2–8 °C', $product->storageRangeLabel());
        $this->assertTrue($product->is_controlled);

        $this->get('/urunler')->assertSee('Kontrollü')->assertSee('2–8 °C');
    }

    public function test_invalid_gtin_and_missing_cold_chain_range_are_rejected(): void
    {
        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'X')
            ->set('gtin', '4006381333932')
            ->call('save')
            ->assertHasErrors(['gtin']);

        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'X')
            ->set('cold_chain', true)
            ->set('storage_min_temp', '8')
            ->set('storage_max_temp', '2')
            ->call('save')
            ->assertHasErrors(['storage_max_temp']);

        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'X')
            ->set('cold_chain', true)
            ->call('save')
            ->assertHasErrors(['storage_min_temp', 'storage_max_temp']);

        $this->assertSame(0, Product::count());
    }

    public function test_duplicate_gtin_is_rejected(): void
    {
        Product::create(['name' => 'A', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04006381333931']);

        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'B')
            ->set('gtin', '04006381333931')
            ->call('save')
            ->assertHasErrors(['gtin']);
    }

    public function test_product_can_be_edited_and_change_is_audited(): void
    {
        $product = Product::create(['name' => 'İmplant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'product_type' => 'equipment']);

        Livewire::test('pages::catalog.products')
            ->call('edit', $product->id)
            ->assertSet('name', 'İmplant 4.1')
            ->set('tracks_serials', true)
            ->set('uts_number', 'UTS-IMP')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertTrue($product->tracks_serials);
        $this->assertSame('UTS-IMP', $product->uts_number);

        $log = AuditLog::where('entity_type', $product->getMorphClass())->where('entity_id', $product->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertTrue((bool) $log->after['tracks_serials']);
    }

    public function test_serial_tracking_cannot_be_toggled_while_product_has_stock(): void
    {
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $this->warehouse, 10, ['lot_no' => 'L1']);

        Livewire::test('pages::catalog.products')
            ->call('edit', $product->id)
            ->set('tracks_serials', true)
            ->call('save')
            ->assertHasErrors(['tracks_serials']);

        $this->assertFalse($product->fresh()->tracks_serials);
    }

    public function test_serial_tracked_product_cannot_have_unit_conversions(): void
    {
        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'İmplant')
            ->set('tracks_serials', true)
            ->set('conversionRules', [['unit' => 'Kutu', 'factor' => '10']])
            ->call('save')
            ->assertHasErrors(['tracks_serials']);
    }

    public function test_scanning_gs1_datamatrix_finds_product_by_gtin_and_prefills_lot_and_expiry(): void
    {
        $product = Product::create(['name' => 'Artikain', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04006381333931']);

        Livewire::test('pages::stock.quick')
            ->set('mode', 'in')
            ->set('scanCode', "010400638133393117270331\x1D10LOT42\x1D21SN-9")
            ->call('scan')
            ->assertSet('productId', $product->id)
            ->assertSet('lot_no', 'LOT42')
            ->assertSet('expiry_date', '2027-03-31');

        // Kutudaki EAN-13 (GTIN'in kısa hâli) de ürünü bulur.
        Livewire::test('pages::stock.quick')
            ->set('scanCode', '4006381333931')
            ->call('scan')
            ->assertSet('productId', $product->id);

        Livewire::test('pages::stock.quick')
            ->set('scanCode', '(01)09506000134352(17)270101')
            ->call('scan')
            ->assertSet('productId', null)
            ->assertSee('ile kayıtlı bir ürün yok');
    }
}
