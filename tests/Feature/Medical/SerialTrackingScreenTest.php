<?php

namespace Tests\Feature\Medical;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 26 — seri takibi ekranlarda: Stok Girişi, Stok Çıkışı, Hızlı İşlem
 * (GS1 ile art arda okutma) ve satın alma teslimi.
 */
class SerialTrackingScreenTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $implant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->implant = Product::create(['name' => 'İmplant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '04006381333931']);
    }

    private function serialStatus(string $serial): SerialStatus
    {
        return StockSerial::where('serial_no', $serial)->sole()->status;
    }

    public function test_stock_in_screen_takes_one_serial_per_line_and_derives_quantity(): void
    {
        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->implant->id)
            ->assertSee('her satıra bir seri')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('lot_no', 'IMP-1')
            ->set('serialsText', "SN1\nSN2\n\nSN3")
            ->assertSet('quantity', '3')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(3, StockLot::sole()->quantity);
        $this->assertSame(3, StockSerial::where('status', 'in_stock')->count());

        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->implant->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('serialsText', "SN1\nSN9")
            ->call('save')
            ->assertHasErrors(['serialsText']);
    }

    public function test_stock_out_screen_lists_serials_in_stock_and_takes_the_selected_ones(): void
    {
        app(StockMovementService::class)->in($this->implant, $this->warehouse, 3, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['SN1', 'SN2', 'SN3']]);

        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->implant->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->assertSee('SN1')->assertSee('SN3')
            ->set('selectedSerials', ['SN2', 'SN3'])
            ->assertSet('quantity', '2')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN1'));
        $this->assertSame(SerialStatus::Out, $this->serialStatus('SN2'));
        $this->assertEquals(1, StockLot::sole()->quantity);
    }

    public function test_quick_screen_collects_serials_from_consecutive_gs1_scans(): void
    {
        $component = Livewire::test('pages::stock.quick')
            ->set('mode', 'in')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('scanCode', "010400638133393117270331\x1D10IMP-9\x1D21SN-A")
            ->call('scan')
            ->set('scanCode', "010400638133393117270331\x1D10IMP-9\x1D21SN-B")
            ->call('scan')
            ->assertSet('serialsText', "SN-A\nSN-B")
            ->assertSet('quantity', '2')
            ->assertSet('lot_no', 'IMP-9')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(2, StockLot::where('lot_no', 'IMP-9')->sole()->quantity);

        // Çıkış: kutudaki kodu okutmak o seriyi seçer.
        $component->set('mode', 'out')
            ->set('scanCode', "010400638133393117270331\x1D10IMP-9\x1D21SN-B")
            ->call('scan')
            ->assertSet('serialsText', 'SN-B')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(SerialStatus::Out, $this->serialStatus('SN-B'));
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN-A'));
    }

    public function test_purchase_receipt_requires_one_serial_per_received_unit(): void
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(Supplier::create(['name' => 'İmplant A.Ş.', 'status' => 'active']), $this->warehouse, [['product_id' => $this->implant->id, 'quantity' => 2, 'unit_price' => 900]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $lineId = $order->lines()->sole()->id;

        $component = Livewire::test('pages::purchasing.index')
            ->call('openReceipt', $order->id)
            ->assertSee('her satıra bir seri')
            ->set("receiptLines.{$lineId}.quantity", '2')
            ->set("receiptLines.{$lineId}.lot_no", 'IMP-P')
            ->set("receiptLines.{$lineId}.serials", 'P-1')
            ->call('receive')
            ->assertHasErrors(["receiptLines.{$lineId}.serials"]);

        $component->set("receiptLines.{$lineId}.serials", "P-1\nP-2")->call('receive')->assertHasNoErrors();

        $this->assertSame(['P-1', 'P-2'], StockSerial::orderBy('serial_no')->pluck('serial_no')->all());
        $this->assertEquals(2, StockLot::sole()->quantity);
    }
}
