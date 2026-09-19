<?php

namespace Tests\Feature\Medical;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Stock\Exceptions\ColdChainException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 26 — soğuk zincir: girişte ölçülen sıcaklık zorunlu; aralık dışı giriş
 * reddedilir ya da gerekçeyle kabul edilir, kayda geçer ve Admin'e bildirilir.
 */
class ColdChainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Product $vaccine;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->vaccine = Product::create(['name' => 'Anestezik Kartuş', 'base_unit' => 'Adet', 'status' => 'active', 'product_type' => 'medicine', 'cold_chain' => true, 'storage_min_temp' => 2, 'storage_max_temp' => 8]);
    }

    private function stockIn(array $tracking): StockMovement
    {
        return app(StockMovementService::class)->in($this->vaccine, $this->warehouse, 10, ['lot_no' => 'SZ-1'], $this->admin, 'Giriş', tracking: $tracking);
    }

    public function test_temperature_is_required_for_cold_chain_products(): void
    {
        $this->expectException(ColdChainException::class);

        $this->stockIn([]);
    }

    public function test_in_range_temperature_is_recorded_on_the_movement(): void
    {
        $movement = $this->stockIn(['temperature' => '4.5']);

        $this->assertSame(4.5, $movement->fresh()->temperature);
        $this->assertNull($movement->fresh()->temperature_note);
        $this->assertEquals(10, StockLot::sole()->quantity);
    }

    public function test_out_of_range_is_rejected_without_explanation(): void
    {
        try {
            $this->stockIn(['temperature' => 12]);
            $this->fail('Aralık dışı giriş gerekçesiz kabul edilmemeliydi.');
        } catch (ColdChainException $e) {
            $this->assertStringContainsString('2–8 °C', $e->getMessage());
        }

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, (int) StockLot::sum('quantity'));
    }

    public function test_out_of_range_with_explanation_is_accepted_recorded_and_admins_are_notified(): void
    {
        $colleague = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $movement = $this->stockIn(['temperature' => 9.2, 'temperature_note' => 'Kargo 20 dk gecikti, üretici onayı alındı']);

        $this->assertSame('Kargo 20 dk gecikti, üretici onayı alındı', $movement->fresh()->temperature_note);
        $notification = $colleague->notifications()->sole();
        $this->assertSame('Soğuk zincir: aralık dışı giriş kabul edildi', $notification->data['title']);
        $this->assertStringContainsString('9,2 °C', $notification->data['message']);
    }

    public function test_non_cold_chain_products_need_no_temperature(): void
    {
        $gloves = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);

        $movement = app(StockMovementService::class)->in($gloves, $this->warehouse, 5, [], $this->admin);

        $this->assertNull($movement->temperature);
    }

    public function test_stock_in_screen_requires_and_records_temperature(): void
    {
        $component = Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->vaccine->id)
            ->assertSee('saklama aralığı 2–8 °C')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '3')
            ->call('save')
            ->assertHasErrors(['temperature']);

        $component->set('temperature', '11')
            ->assertSee('Ölçüm saklama aralığının dışında')
            ->call('save')
            ->assertHasErrors(['temperature_note']);

        $component->set('temperature', '5')->call('save')->assertHasNoErrors();

        $this->assertSame(5.0, StockMovement::sole()->temperature);
        $this->get('/stok-girisleri')->assertSee('❄ 5 °C');
    }

    public function test_quick_screen_requires_temperature_for_cold_chain_entry(): void
    {
        $this->vaccine->update(['barcode' => 'SZ-BARKOD']);

        Livewire::test('pages::stock.quick')
            ->set('mode', 'in')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('scanCode', 'SZ-BARKOD')
            ->call('scan')
            ->set('quantity', '2')
            ->call('submit')
            ->assertHasErrors(['temperature'])
            ->set('temperature', '3')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(3.0, StockMovement::sole()->temperature);
    }

    public function test_purchase_receipt_line_requires_temperature(): void
    {
        $orders = app(PurchaseOrderService::class);
        $supplier = Supplier::create(['name' => 'Soğuk Tedarik', 'status' => 'active']);
        $order = $orders->create($supplier, $this->warehouse, [['product_id' => $this->vaccine->id, 'quantity' => 20, 'unit_price' => 15]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $lineId = $order->lines()->sole()->id;

        $component = Livewire::test('pages::purchasing.index')
            ->call('openReceipt', $order->id)
            ->set("receiptLines.{$lineId}.quantity", '20')
            ->set("receiptLines.{$lineId}.lot_no", 'SZ-9')
            ->call('receive')
            ->assertHasErrors(["receiptLines.{$lineId}.temperature"]);

        $component->set("receiptLines.{$lineId}.temperature", '15')
            ->call('receive')
            ->assertHasErrors(["receiptLines.{$lineId}.temperature_note"]);

        $component->set("receiptLines.{$lineId}.temperature", '6')->call('receive')->assertHasNoErrors();

        $this->assertSame(6.0, StockMovement::sole()->temperature);
        $this->assertEquals(20, StockLot::sole()->quantity);
    }
}
