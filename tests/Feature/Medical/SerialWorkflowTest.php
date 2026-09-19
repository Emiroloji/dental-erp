<?php

namespace Tests\Feature\Medical;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Transfer\Services\TransferService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 26 — seri takibi iş akışlarında: transfer (otomatik seri seçimi),
 * tedarikçiye iade (seri seçimi) ve stok sayımı (bulunan seri listesi).
 */
class SerialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $central;

    private Warehouse $north;

    private Product $implant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $centralBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $northBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->central = Warehouse::create(['branch_id' => $centralBranch->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $this->north = Warehouse::create(['branch_id' => $northBranch->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->implant = Product::create(['name' => 'İmplant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);
        app(StockMovementService::class)->in($this->implant, $this->central, 4, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['S1', 'S2', 'S3', 'S4']]);
    }

    private function serialStatus(string $serial): SerialStatus
    {
        return StockSerial::where('serial_no', $serial)->sole()->status;
    }

    public function test_transfer_workflow_carries_serials_to_the_destination_and_shows_them(): void
    {
        $transfers = app(TransferService::class);
        $transfer = $transfers->request($this->implant, $this->central, $this->north, 2, 'Kuzey implant bitti', $this->admin);
        $transfers->approve($transfer, $this->admin);
        $transfers->prepare($transfer->fresh(), $this->admin);
        $transfers->ship($transfer->fresh(), $this->admin);

        $this->assertSame(2, StockSerial::where('status', SerialStatus::InTransit->value)->count());

        $transfers->receive($transfer->fresh(), $this->admin);

        $northLot = StockLot::where('warehouse_id', $this->north->id)->sole();
        $this->assertEqualsCanonicalizing(['S1', 'S2'], StockSerial::where('lot_id', $northLot->id)->where('status', 'in_stock')->pluck('serial_no')->all());
        $this->assertEquals(2, $northLot->quantity);

        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Seri: S1, S2');
    }

    public function test_return_ships_exactly_the_selected_serials_and_supplier_reject_restores_them(): void
    {
        $supplier = Supplier::create(['name' => 'İmplant A.Ş.', 'status' => 'active']);
        $lot = StockLot::sole();
        $returns = app(ReturnService::class);

        try {
            $returns->request($lot, 1, ReturnReason::Defective, $supplier, $this->admin);
            $this->fail('Seri seçilmeden iade açılmamalıydı.');
        } catch (ReturnException) {
        }

        Livewire::test('pages::returns.index')
            ->call('openForm')
            ->set('warehouse_id', (string) $this->central->id)
            ->set('product_id', (string) $this->implant->id)
            ->set('lot_id', (string) $lot->id)
            ->assertSee('S3')
            ->set('selectedSerials', ['S3'])
            ->assertSet('quantity', '1')
            ->set('reason', ReturnReason::Defective->value)
            ->set('supplier_id', (string) $supplier->id)
            ->call('save')
            ->assertHasNoErrors();

        $return = SupplierReturn::sole();
        $this->assertSame(['S3'], $return->serial_numbers);

        $returns->approve($return, $this->admin);
        $returns->ship($return->fresh(), $this->admin);
        $this->assertSame(SerialStatus::Out, $this->serialStatus('S3'));
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('S1'));

        $returns->supplierReject($return->fresh(), $this->admin, 'Kusur bulunamadı');
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('S3'));
        $this->assertEquals(4, $lot->fresh()->quantity);
    }

    public function test_stock_count_with_serial_list_marks_missing_and_found(): void
    {
        $counts = app(StockCountService::class);
        $count = $counts->start($this->central, $this->admin);
        $line = $count->lines()->sole();

        $component = Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('Bulunan seriler')
            ->set("entries.{$line->id}.counted_serials", "S1\nS2\nS4\nS9")
            ->assertSee('4 seri')
            ->call('save');

        $line->refresh();
        $this->assertEquals(4, $line->counted_quantity);
        $this->assertSame(['S1', 'S2', 'S4', 'S9'], $line->counted_serials);
        // Miktar aynı (4) ama seriler farklı: fark sayılır, neden gerekir.
        $this->assertTrue($line->hasDifference());

        try {
            $counts->submit($count, $this->admin);
            $this->fail('Nedensiz fark onaya gitmemeliydi.');
        } catch (StockCountException) {
        }

        $component->set("entries.{$line->id}.reason", 'loss')->call('submit');
        $counts->approve($count->fresh(), $this->admin);

        $this->assertSame(SerialStatus::Lost, $this->serialStatus('S3'));
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('S9'));
        $this->assertEquals(4, StockLot::sole()->quantity);
    }

    public function test_serial_tracked_count_line_requires_the_serial_list(): void
    {
        $counts = app(StockCountService::class);
        $count = $counts->start($this->central, $this->admin);
        $line = $count->lines()->sole();

        $counts->record($count, [$line->id => ['counted_quantity' => 4]], $this->admin);

        $this->expectException(StockCountException::class);
        $counts->submit($count->fresh(), $this->admin);
    }
}
