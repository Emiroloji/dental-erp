<?php

namespace Tests\Feature\Import;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningStockImporterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private User $admin;

    private OpeningStockImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        Product::create(['name' => 'Enjektör', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->importer = app(OpeningStockImporter::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [...array_fill_keys($this->importer->headings(), ''), 'Ürün Kodu' => 'ENJ-01', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '40', ...$overrides];
    }

    public function test_rows_become_stock_in_movements_with_opening_reason(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Lot No' => 'L1', 'SKT' => '31.03.2027', 'Birim Maliyet' => '12,50', 'Ürün Kodu' => ' enj-01 ', 'Şube' => 'merkez']),
            3 => $this->row(['Lot No' => 'L1', 'Miktar' => '10']),
        ], $this->admin);

        $this->assertTrue($result->isValid(), implode("\n", $result->errorMessages()));
        $this->assertSame(2, $this->importer->import($result, $this->admin));

        $lot = StockLot::sole();
        $this->assertEquals(50, $lot->quantity);
        $this->assertSame('2027-03-31', $lot->expiry_date->toDateString());
        $this->assertEquals(12.50, $lot->unit_cost);
        $this->assertSame(2, StockMovement::where('reason', 'Açılış stoğu')->count());
    }

    public function test_product_warehouse_and_values_are_validated(): void
    {
        Product::create(['name' => 'Pasif', 'code' => 'PSF', 'base_unit' => 'Adet', 'status' => 'passive']);
        Product::create(['name' => 'Çift 1', 'code' => 'CFT', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['name' => 'Çift 2', 'code' => 'cft', 'base_unit' => 'Adet', 'status' => 'active']);
        Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Kapalı Depo', 'is_default' => false, 'status' => 'passive']);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'YOK']),
            3 => $this->row(['Ürün Kodu' => 'PSF']),
            4 => $this->row(['Ürün Kodu' => 'CFT']),
            5 => $this->row(['Depo' => 'Kapalı Depo']),
            6 => $this->row(['Miktar' => '0', 'SKT' => '31.02.2027', 'Birim Maliyet' => '-1']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Ürün Kodu: Bu kodla aktif bir ürün yok.', $messages);
        $this->assertStringContainsString('Satır 3 · Ürün Kodu: Bu kodla aktif bir ürün yok.', $messages);
        $this->assertStringContainsString('Satır 4 · Ürün Kodu: Bu kod birden çok üründe kayıtlı', $messages);
        $this->assertStringContainsString('Satır 5 · Depo:', $messages);
        $this->assertStringContainsString('Satır 6 · Miktar', $messages);
        $this->assertStringContainsString('Satır 6 · SKT', $messages);
        $this->assertStringContainsString('Satır 6 · Birim Maliyet', $messages);
    }

    public function test_staff_cannot_load_into_a_branch_outside_their_scope(): void
    {
        $other = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Şube 2', 'status' => 'active']);
        Warehouse::create(['branch_id' => $other->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
        $this->actingAs($staff);

        $result = $this->importer->validate([2 => $this->row(['Şube' => 'Şube 2']), 3 => $this->row()], $staff->fresh());

        $this->assertSame(['Satır 2 · Depo: Bu şube ve depo bulunamadı ya da bu depoda stok girişi yetkiniz yok.'], $result->errorMessages());
    }

    public function test_cold_chain_rows_need_an_in_range_temperature(): void
    {
        Product::create(['name' => 'Aşı', 'code' => 'ASI', 'base_unit' => 'Adet', 'status' => 'active', 'cold_chain' => true, 'storage_min_temp' => 2, 'storage_max_temp' => 8]);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'ASI']),
            3 => $this->row(['Ürün Kodu' => 'ASI', 'Sıcaklık' => '12']),
            4 => $this->row(['Ürün Kodu' => 'ASI', 'Sıcaklık' => '4,5']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Sıcaklık: Soğuk zincir ürününde ölçülen sıcaklık zorunlu.', $messages);
        $this->assertStringContainsString('Satır 3 · Sıcaklık: Saklama aralığı (2–8 °C) dışında.', $messages);
        $this->assertStringNotContainsString('Satır 4', $messages);
    }

    public function test_serial_tracked_rows_need_matching_unique_serials(): void
    {
        Product::create(['name' => 'İmplant', 'code' => 'IMP', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S1']),
            3 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S2; S3']),
            4 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '1', 'Seri Numaraları' => 'S3']),
            5 => $this->row(['Seri Numaraları' => 'X1']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Seri Numaraları', $messages);
        $this->assertStringNotContainsString('Satır 3', $messages);
        $this->assertStringContainsString('Satır 4 · Seri Numaraları: Dosyada satır 3 ile aynı seri: S3', $messages);
        $this->assertStringContainsString('Satır 5 · Seri Numaraları: Bu ürün seri takipli değil', $messages);
    }

    public function test_valid_serial_rows_register_serials(): void
    {
        Product::create(['name' => 'İmplant', 'code' => 'IMP', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);

        $result = $this->importer->validate([2 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S1, S2'])], $this->admin);
        $this->importer->import($result, $this->admin);

        $this->assertSame(['S1', 'S2'], StockSerial::orderBy('serial_no')->pluck('serial_no')->all());
    }

    public function test_service_failure_rolls_back_every_row(): void
    {
        $result = $this->importer->validate([2 => $this->row(), 3 => $this->row(['Miktar' => '5'])], $this->admin);
        $this->assertTrue($result->isValid());

        // İlk satır yazıldıktan sonra ikinci satırda servis reddeder: ilk satır da geri alınmalı.
        $row = $result->rows[3];
        $row->values['quantity'] = 0;

        try {
            $this->importer->import($result, $this->admin);
            $this->fail('İstisna bekleniyordu.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, (int) StockLot::sum('quantity'));
    }
}
