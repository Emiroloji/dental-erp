<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\ScanResolver;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 40: sayım ekranında barkod okutarak sayma. Her okutma ilgili satırın
 * sayılan miktarını 1 artırır; seri takipli üründe okutulan seri eklenir.
 */
class StockCountScanTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $admin;

    private StockCountService $counts;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->counts = app(StockCountService::class);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::create(['name' => $name, 'base_unit' => 'Adet', 'status' => 'active', ...$attributes]);
    }

    private function stockIn(Product $product, float $quantity, ?string $lotNo, array $serials = []): StockLot
    {
        app(StockMovementService::class)->in($product, $this->warehouse, $quantity, ['lot_no' => $lotNo], $this->admin, tracking: ['serials' => $serials]);

        return StockLot::where('product_id', $product->id)->where('lot_no', $lotNo)->sole();
    }

    private function start(): StockCount
    {
        return $this->counts->start($this->warehouse, $this->admin);
    }

    private function counted(StockCount $count, StockLot $lot): ?float
    {
        $value = $count->lines()->where('stock_lot_id', $lot->id)->sole()->counted_quantity;

        return $value === null ? null : (float) $value;
    }

    public function test_each_scan_of_a_product_barcode_adds_one(): void
    {
        $lot = $this->stockIn($this->product('Enjektör', ['barcode' => '8690000000017']), 40, 'L1');
        $count = $this->start();

        $this->counts->scan($count, '8690000000017', $this->admin);
        $result = $this->counts->scan($count, '8690000000017', $this->admin);

        $this->assertSame(2.0, $result['counted']);
        $this->assertSame(2.0, $this->counted($count, $lot));
    }

    public function test_lot_label_picks_its_own_line_when_a_product_has_several_lots(): void
    {
        $product = $this->product('Kompozit', ['code' => 'KMP']);
        $first = $this->stockIn($product, 5, 'A');
        $second = $this->stockIn($product, 5, 'B');
        $count = $this->start();

        $this->counts->scan($count, ScanResolver::lotPayload($second), $this->admin);

        $this->assertNull($this->counted($count, $first));
        $this->assertSame(1.0, $this->counted($count, $second));

        $this->expectException(StockCountException::class);
        $this->expectExceptionMessage('sayımda 2 lotu var');
        $this->counts->scan($count, 'KMP', $this->admin);
    }

    public function test_gs1_lot_number_picks_the_matching_line(): void
    {
        $product = $this->product('Lidokain', ['gtin' => '08699999999994']);
        $this->stockIn($product, 5, 'A');
        $lotB = $this->stockIn($product, 5, 'B7');
        $count = $this->start();

        $this->counts->scan($count, '0108699999999994'.'10B7', $this->admin);

        $this->assertSame(1.0, $this->counted($count, $lotB));
    }

    public function test_serial_tracked_scan_adds_the_serial_once(): void
    {
        $product = $this->product('İmplant', ['gtin' => '08699999999994', 'tracks_serials' => true]);
        $lot = $this->stockIn($product, 2, 'L1', ['S1', 'S2']);
        $count = $this->start();

        $this->counts->scan($count, '0108699999999994'.'21S1', $this->admin);
        $this->assertSame(1.0, $this->counted($count, $lot));
        $this->assertSame(['S1'], $count->lines()->sole()->counted_serials);

        try {
            $this->counts->scan($count, '0108699999999994'.'21S1', $this->admin);
            $this->fail('Aynı seri ikinci kez sayılmamalı.');
        } catch (StockCountException $e) {
            $this->assertStringContainsString('S1 zaten sayıldı', $e->getMessage());
        }

        $this->expectException(StockCountException::class);
        $this->expectExceptionMessage('seri numarası');
        $this->counts->scan($count, ScanResolver::productPayload($product), $this->admin);
    }

    public function test_products_without_a_line_and_unknown_codes_are_explained(): void
    {
        $this->stockIn($this->product('Enjektör'), 5, 'L1');
        $empty = $this->product('Eldiven', ['barcode' => '111']);
        $count = $this->start();

        try {
            $this->counts->scan($count, '111', $this->admin);
            $this->fail('Satırı olmayan ürün reddedilmeli.');
        } catch (StockCountException $e) {
            $this->assertStringContainsString('Eldiven bu sayımda yok', $e->getMessage());
        }

        $this->expectException(StockCountException::class);
        $this->expectExceptionMessage('"999" barkodu/koduyla kayıtlı aktif bir ürün yok');
        $this->counts->scan($count, '999', $this->admin);
    }

    public function test_undo_removes_the_last_scan(): void
    {
        $lot = $this->stockIn($this->product('Enjektör', ['barcode' => '111']), 5, 'L1');
        $count = $this->start();
        $line = $this->counts->scan($count, '111', $this->admin)['line'];
        $this->counts->scan($count, '111', $this->admin);

        $this->counts->undoScan($count, $line->id, null, $this->admin);
        $this->assertSame(1.0, $this->counted($count, $lot));

        $this->counts->undoScan($count, $line->id, null, $this->admin);
        $this->assertNull($this->counted($count, $lot));
    }

    public function test_scan_needs_counting_status_and_permission(): void
    {
        $this->stockIn($this->product('Enjektör', ['barcode' => '111']), 5, 'L1');
        $count = $this->start();
        $reader = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $reader->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => 'all']);

        try {
            $this->counts->scan($count, '111', $reader->fresh());
            $this->fail('Okuma yetkisi sayım için yetmez.');
        } catch (AuthorizationException) {
        }

        $this->counts->scan($count, '111', $this->admin);
        $this->counts->cancel($count, $this->admin, 'Vazgeçildi');

        $this->expectException(StockCountException::class);
        $this->counts->scan($count->fresh(), '111', $this->admin);
    }

    public function test_screen_scans_keeps_manual_entries_and_offers_undo(): void
    {
        $product = $this->product('Enjektör', ['barcode' => '111']);
        $lot = $this->stockIn($product, 5, 'L1');
        $other = $this->stockIn($this->product('Kompozit'), 5, 'K1');
        $count = $this->start();
        $otherLine = $count->lines()->where('stock_lot_id', $other->id)->sole();

        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('Barkod okutarak say')
            ->set("entries.{$otherLine->id}.counted_quantity", '4')
            ->set('scanCode', '111')
            ->call('scan')
            ->assertSet('scanCode', '')
            ->assertSee('Enjektör · L1: 1 sayıldı')
            ->assertSet("entries.{$otherLine->id}.counted_quantity", '4')
            ->set('scanCode', '111')
            ->call('scan')
            ->call('undoScan')
            ->assertSee('Son okutma geri alındı')
            ->set('scanCode', '999')
            ->call('scan')
            ->assertSee('"999" barkodu/koduyla kayıtlı aktif bir ürün yok');

        $this->assertSame(1.0, $this->counted($count, $lot));
        $this->assertSame(4.0, $this->counted($count, $other));
    }
}
