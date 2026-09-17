<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;

    private Product $product;

    private Warehouse $warehouse;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StockMovementService;

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create(['organization_id' => $organization->id, 'name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_in_creates_a_new_lot_and_positive_movement(): void
    {
        $movement = $this->service->in(
            $this->product,
            $this->warehouse,
            50,
            ['lot_no' => 'LOT-1', 'expiry_date' => '2027-01-01', 'unit_cost' => 10],
            $this->actor,
            'İlk stok girişi',
        );

        $lot = StockLot::firstOrFail();

        $this->assertSame('LOT-1', $lot->lot_no);
        $this->assertEquals(50, $lot->quantity);
        $this->assertSame(StockMovementType::In, $movement->type);
        $this->assertEquals(50, $movement->quantity);
        $this->assertSame($this->actor->id, $movement->actor_id);
    }

    public function test_in_adds_to_existing_lot_when_lot_no_matches(): void
    {
        $this->service->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
        $this->service->in($this->product, $this->warehouse, 30, ['lot_no' => 'LOT-1']);

        $this->assertSame(1, StockLot::count());
        $this->assertEquals(80, StockLot::firstOrFail()->quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_in_creates_separate_lots_when_lot_no_is_null(): void
    {
        $this->service->in($this->product, $this->warehouse, 10);
        $this->service->in($this->product, $this->warehouse, 20);

        $this->assertSame(2, StockLot::count());
    }

    public function test_in_rejects_zero_or_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->in($this->product, $this->warehouse, 0);
    }

    public function test_out_decrements_specified_lot_and_creates_negative_movement(): void
    {
        $this->service->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $movements = $this->service->out($this->product, $this->warehouse, 20, $lot, $this->actor, 'Klinik içi kullanım');

        $this->assertCount(1, $movements);
        $this->assertSame(StockMovementType::Out, $movements->first()->type);
        $this->assertEquals(-20, $movements->first()->quantity);
        $this->assertEquals(30, $lot->fresh()->quantity);
    }

    public function test_out_rejects_when_specified_lot_has_insufficient_stock_and_leaves_state_unchanged(): void
    {
        $this->service->in($this->product, $this->warehouse, 10, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        try {
            $this->service->out($this->product, $this->warehouse, 15, $lot);
            $this->fail('InsufficientStockException bekleniyordu.');
        } catch (InsufficientStockException) {
            // beklenen davranış
        }

        $this->assertEquals(10, $lot->fresh()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_out_without_lot_uses_fefo_earliest_expiry_first(): void
    {
        $this->service->in($this->product, $this->warehouse, 10, ['lot_no' => 'LOT-LATE', 'expiry_date' => '2028-01-01']);
        $this->service->in($this->product, $this->warehouse, 10, ['lot_no' => 'LOT-EARLY', 'expiry_date' => '2027-01-01']);

        $movements = $this->service->out($this->product, $this->warehouse, 5, actor: $this->actor, reason: 'FEFO testi');

        $this->assertCount(1, $movements);

        $earlyLot = StockLot::where('lot_no', 'LOT-EARLY')->firstOrFail();
        $lateLot = StockLot::where('lot_no', 'LOT-LATE')->firstOrFail();

        $this->assertEquals(5, $earlyLot->quantity);
        $this->assertEquals(10, $lateLot->quantity);
        $this->assertSame($earlyLot->id, $movements->first()->lot_id);
    }

    public function test_out_splits_across_multiple_lots_in_fefo_order_when_needed(): void
    {
        $this->service->in($this->product, $this->warehouse, 5, ['lot_no' => 'LOT-EARLY', 'expiry_date' => '2027-01-01']);
        $this->service->in($this->product, $this->warehouse, 10, ['lot_no' => 'LOT-LATE', 'expiry_date' => '2028-01-01']);

        $movements = $this->service->out($this->product, $this->warehouse, 8, actor: $this->actor, reason: 'Bölünmüş çıkış');

        $this->assertCount(2, $movements);

        $earlyLot = StockLot::where('lot_no', 'LOT-EARLY')->firstOrFail();
        $lateLot = StockLot::where('lot_no', 'LOT-LATE')->firstOrFail();

        $this->assertEquals(0, $earlyLot->quantity);
        $this->assertEquals(7, $lateLot->quantity);

        $this->assertEquals(-5, $movements[0]->quantity);
        $this->assertEquals(-3, $movements[1]->quantity);
    }

    public function test_out_rejects_when_total_stock_across_all_lots_is_insufficient_and_leaves_state_unchanged(): void
    {
        $this->service->in($this->product, $this->warehouse, 5, ['lot_no' => 'LOT-A']);
        $this->service->in($this->product, $this->warehouse, 3, ['lot_no' => 'LOT-B']);

        try {
            $this->service->out($this->product, $this->warehouse, 20);
            $this->fail('InsufficientStockException bekleniyordu.');
        } catch (InsufficientStockException) {
            // beklenen davranış
        }

        $this->assertEquals(5, StockLot::where('lot_no', 'LOT-A')->firstOrFail()->quantity);
        $this->assertEquals(3, StockLot::where('lot_no', 'LOT-B')->firstOrFail()->quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_out_rejects_zero_or_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->out($this->product, $this->warehouse, 0);
    }

    public function test_adjust_requires_a_non_empty_reason(): void
    {
        $this->service->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        $this->service->adjust($lot, 97, '');
    }

    public function test_adjust_creates_a_signed_delta_movement_and_updates_lot_quantity(): void
    {
        $this->service->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $movement = $this->service->adjust($lot, 97, 'Sayımda kayıt eksik girilmiş', $this->actor);

        $this->assertEquals(97, $lot->fresh()->quantity);
        $this->assertSame(StockMovementType::CountAdjust, $movement->type);
        $this->assertEquals(-3, $movement->quantity);
        $this->assertSame('Sayımda kayıt eksik girilmiş', $movement->reason);
    }

    public function test_adjust_rejects_negative_counted_quantity(): void
    {
        $this->service->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        $this->service->adjust($lot, -5, 'Geçersiz sayım');
    }

    public function test_adjust_can_increase_stock_with_a_positive_delta(): void
    {
        $this->service->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $movement = $this->service->adjust($lot, 105, 'Fazla ürün bulundu');

        $this->assertEquals(105, $lot->fresh()->quantity);
        $this->assertEquals(5, $movement->quantity);
    }

    public function test_cancel_of_an_in_movement_reverses_the_lot_quantity_and_links_to_original(): void
    {
        $inMovement = $this->service->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $cancelMovement = $this->service->cancel($inMovement, $this->actor);

        $this->assertEquals(0, $lot->fresh()->quantity);
        $this->assertSame(StockMovementType::Cancel, $cancelMovement->type);
        $this->assertEquals(-50, $cancelMovement->quantity);
        $this->assertSame(StockMovement::class, $cancelMovement->related_entity_type);
        $this->assertSame($inMovement->id, $cancelMovement->related_entity_id);

        $this->assertNotNull(StockMovement::find($inMovement->id), 'orijinal hareket silinmemeli');
    }

    public function test_cancel_of_an_out_movement_adds_stock_back(): void
    {
        $this->service->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();
        $outMovement = $this->service->out($this->product, $this->warehouse, 20, $lot)->first();

        $this->assertEquals(30, $lot->fresh()->quantity);

        $this->service->cancel($outMovement, $this->actor);

        $this->assertEquals(50, $lot->fresh()->quantity);
    }

    public function test_cancel_rejects_when_it_would_push_stock_negative(): void
    {
        $inMovement = $this->service->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
        $lot = StockLot::firstOrFail();

        $this->service->out($this->product, $this->warehouse, 40, $lot);
        $this->assertEquals(10, $lot->fresh()->quantity);

        $this->expectException(InsufficientStockException::class);

        $this->service->cancel($inMovement);
    }
}
