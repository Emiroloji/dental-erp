<?php

namespace Tests\Feature\Transfer;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Transfer\Exceptions\TransferException;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Services\TransferService;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * kurallar.md Bölüm 1 — transfer stok zamanlaması, birebir.
 */
class TransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;

    private Warehouse $destination;

    private Product $product;

    private User $admin;

    private TransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $kadikoy = Branch::create(['organization_id' => $organization->id, 'name' => 'Kadıköy', 'status' => 'active']);
        $besiktas = Branch::create(['organization_id' => $organization->id, 'name' => 'Beşiktaş', 'status' => 'active']);
        $this->source = Warehouse::create(['branch_id' => $kadikoy->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $this->destination = Warehouse::create(['branch_id' => $besiktas->id, 'name' => 'Beşiktaş Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->product = Product::create(['name' => 'Kompozit', 'base_unit' => 'Adet', 'status' => 'active']);
        $stock = app(StockMovementService::class);
        $stock->in($this->product, $this->source, 30, ['lot_no' => 'LOT-ERKEN', 'expiry_date' => now()->addMonths(3)->toDateString(), 'unit_cost' => 4], $this->admin);
        $stock->in($this->product, $this->source, 30, ['lot_no' => 'LOT-GEC', 'expiry_date' => now()->addYear()->toDateString(), 'unit_cost' => 5], $this->admin);

        $this->transfers = app(TransferService::class);
    }

    private function quantityIn(Warehouse $warehouse): float
    {
        return (float) StockLot::where('warehouse_id', $warehouse->id)->where('product_id', $this->product->id)->sum('quantity');
    }

    private function assertStock(float $source, float $destination, string $moment): void
    {
        $this->assertSame($source, $this->quantityIn($this->source), "{$moment}: kaynak stok");
        $this->assertSame($destination, $this->quantityIn($this->destination), "{$moment}: hedef stok");
    }

    private function newRequest(float $quantity = 40): TransferRequest
    {
        return $this->transfers->request($this->product, $this->source, $this->destination, $quantity, 'Beşiktaş\'ta kompozit bitti', $this->admin);
    }

    public function test_stock_changes_only_on_ship_and_receive(): void
    {
        $transfer = $this->newRequest();
        $this->assertSame(TransferStatus::Pending, $transfer->status);
        $this->assertStock(60.0, 0.0, 'talep');

        $this->transfers->approve($transfer, $this->admin);
        $this->assertStock(60.0, 0.0, 'onay');

        $this->transfers->prepare($transfer, $this->admin);
        $this->assertStock(60.0, 0.0, 'hazırlanıyor');

        $this->transfers->ship($transfer, $this->admin);
        $this->assertStock(20.0, 0.0, 'gönderildi');

        $this->transfers->receive($transfer, $this->admin);
        $this->assertStock(20.0, 40.0, 'teslim alındı');

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(
            ['pending', 'approved', 'preparing', 'shipped', 'received'],
            $transfer->events()->orderBy('id')->pluck('status')->map->value->all(),
        );
    }

    public function test_lots_expiry_and_cost_travel_with_the_goods_using_fefo(): void
    {
        $transfer = $this->newRequest(40);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);
        $this->transfers->receive($transfer, $this->admin);

        $received = StockLot::where('warehouse_id', $this->destination->id)->orderBy('lot_no')->get();

        // FEFO: önce SKT'si yakın lotun tamamı (30), sonra geç lottan 10.
        $this->assertSame(['LOT-ERKEN', 'LOT-GEC'], $received->pluck('lot_no')->all());
        $this->assertSame([30.0, 10.0], $received->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(now()->addMonths(3)->toDateString(), $received[0]->expiry_date->toDateString());
        $this->assertSame(['4.00', '5.00'], $received->pluck('unit_cost')->all());

        $types = StockMovement::where('related_entity_type', TransferRequest::class)->where('related_entity_id', $transfer->id)->pluck('type')->map->value;
        $this->assertSame(['transfer_out', 'transfer_out', 'transfer_in', 'transfer_in'], $types->all());
    }

    public function test_shipping_with_insufficient_source_stock_is_rejected(): void
    {
        $transfer = $this->newRequest(100);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);

        try {
            $this->transfers->ship($transfer, $this->admin);
            $this->fail('Yetersiz stokla gönderim engellenmedi.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(TransferStatus::Preparing, $transfer->fresh()->status);
        $this->assertStock(60.0, 0.0, 'reddedilen gönderim');
    }

    public function test_cancelling_before_shipment_has_no_stock_effect(): void
    {
        foreach (['pending' => [], 'approved' => ['approve'], 'preparing' => ['approve', 'prepare']] as $state => $steps) {
            $transfer = $this->newRequest(10);
            foreach ($steps as $step) {
                $this->transfers->{$step}($transfer, $this->admin);
            }

            $this->transfers->cancel($transfer, $this->admin, 'Vazgeçildi');

            $this->assertSame(TransferStatus::Cancelled, $transfer->fresh()->status, $state);
            $this->assertStock(60.0, 0.0, "{$state} durumunda iptal");
        }
    }

    public function test_cancelling_after_shipment_returns_the_quantity_to_the_source(): void
    {
        $transfer = $this->newRequest(40);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);
        $this->assertStock(20.0, 0.0, 'gönderildi');

        $this->transfers->cancel($transfer, $this->admin, 'Yolda hasar gördü, geri döndü');

        $this->assertSame(TransferStatus::Cancelled, $transfer->fresh()->status);
        $this->assertStock(60.0, 0.0, 'gönderim sonrası iptal');
        // Lotlar da aynen geri döner.
        $this->assertSame(30.0, (float) StockLot::where('lot_no', 'LOT-ERKEN')->where('warehouse_id', $this->source->id)->value('quantity'));
        $this->assertSame('Yolda hasar gördü, geri döndü', $transfer->events()->latest('id')->value('note'));
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $transfer = $this->newRequest(10);

        foreach (['prepare', 'ship', 'receive'] as $step) {
            try {
                $this->transfers->{$step}($transfer, $this->admin);
                $this->fail("Bekleyen talepte {$step} engellenmedi.");
            } catch (TransferException) {
            }
        }

        $this->transfers->reject($transfer, $this->admin, 'Stok ayrılamaz');
        $this->assertSame(TransferStatus::Rejected, $transfer->fresh()->status);

        $this->expectException(TransferException::class);
        $this->transfers->approve($transfer, $this->admin);
    }

    public function test_a_received_transfer_cannot_be_cancelled_or_shipped_twice(): void
    {
        $transfer = $this->newRequest(10);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);

        try {
            $this->transfers->ship($transfer, $this->admin);
            $this->fail('İkinci gönderim engellenmedi.');
        } catch (TransferException) {
        }
        $this->assertStock(50.0, 0.0, 'çift gönderim denemesi');

        $this->transfers->receive($transfer, $this->admin);

        $this->expectException(TransferException::class);
        $this->transfers->cancel($transfer, $this->admin);
    }

    public function test_source_and_destination_must_differ_and_quantity_must_be_positive(): void
    {
        try {
            $this->transfers->request($this->product, $this->source, $this->source, 5, null, $this->admin);
            $this->fail('Aynı depoya transfer engellenmedi.');
        } catch (TransferException) {
        }

        $this->expectException(TransferException::class);
        $this->transfers->request($this->product, $this->source, $this->destination, 0, null, $this->admin);
    }

    public function test_warehouses_in_the_same_branch_use_the_same_flow(): void
    {
        $surgical = Warehouse::create(['branch_id' => $this->source->branch_id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);

        $transfer = $this->transfers->request($this->product, $this->source, $surgical, 5, null, $this->admin);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);
        $this->transfers->receive($transfer, $this->admin);

        $this->assertSame(55.0, $this->quantityIn($this->source));
        $this->assertSame(5.0, (float) StockLot::where('warehouse_id', $surgical->id)->sum('quantity'));
    }

    public function test_transfers_are_neither_usage_nor_other_outs_on_the_dashboard(): void
    {
        $transfer = $this->newRequest(10);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);
        $this->transfers->receive($transfer, $this->admin);

        $summary = app(DashboardMetricsService::class)->summaryFor($this->admin->organization_id);

        $this->assertSame(60.0, $summary['totalStockQuantity']);
        $this->assertSame(0.0, $summary['monthlyUsage']);
        $this->assertSame(0.0, $summary['monthlyOtherOut']);
        $this->assertSame(0.0, $summary['todayOut']);
    }

    public function test_manual_stock_out_cannot_use_transfer_as_a_reason(): void
    {
        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->source->id)
            ->set('quantity', '5')
            ->set('reasonCategory', 'transfer')
            ->call('save')
            ->assertHasErrors('reasonCategory');

        $this->assertStock(60.0, 0.0, 'transfer nedenli elle çıkış denemesi');
    }

    public function test_transfer_movements_cannot_be_cancelled_from_the_movements_screen(): void
    {
        $transfer = $this->newRequest(10);
        $this->transfers->approve($transfer, $this->admin);
        $this->transfers->prepare($transfer, $this->admin);
        $this->transfers->ship($transfer, $this->admin);

        $shipped = StockMovement::where('type', StockMovementType::TransferOut->value)->firstOrFail();

        Livewire::test('pages::stock.movements')->call('cancel', $shipped->id);

        $this->assertSame(0, StockMovement::where('type', StockMovementType::Cancel->value)->count());
        $this->assertStock(50.0, 0.0, 'hareketler ekranından iptal denemesi');
    }
}
