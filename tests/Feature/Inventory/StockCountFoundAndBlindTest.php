<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\StockCountReportService;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 58 — sayımda listede olmayan lotu ekleme ve kör sayım.
 */
class StockCountFoundAndBlindTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private User $admin;

    private User $counter;

    private StockCountService $counts;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->counter = User::factory()->create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $this->counter->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'own_branch']);
        $this->counter = $this->counter->fresh();

        $this->actingAs($this->admin);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active', 'purchase_price' => 420]);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT001', 'unit_cost' => 500], $this->admin);

        $this->counts = app(StockCountService::class);
    }

    private function countAll(StockCount $count, float $quantity, array $extra = []): void
    {
        $entries = $count->lines()->get()->mapWithKeys(fn ($line) => [$line->id => ['counted_quantity' => $quantity] + $extra])->all();
        $this->counts->record($count, $entries, $this->counter);
    }

    private function expectCountError(\Closure $attempt, string $contains): void
    {
        try {
            $attempt();
            $this->fail('sayım hatası bekleniyordu');
        } catch (StockCountException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function test_a_lot_found_on_the_shelf_is_added_and_booked_on_approval(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);

        $line = $this->counts->addLine($count, $this->product, ' LOT-YENI ', '2027-05-31', $this->counter);

        $this->assertTrue($line->added_during_count);
        $this->assertSame(0.0, (float) $line->system_quantity);
        $lot = $line->lot;
        $this->assertSame('LOT-YENI', $lot->lot_no);
        $this->assertSame('2027-05-31', $lot->expiry_date->format('Y-m-d'));
        $this->assertSame(420.0, (float) $lot->unit_cost);
        $this->assertSame(0.0, (float) $lot->quantity);
        $this->assertSame(0, StockMovement::where('lot_id', $lot->id)->count());

        $this->counts->record($count, [
            $count->lines()->where('stock_lot_id', '!=', $lot->id)->sole()->id => ['counted_quantity' => 100],
            $line->id => ['counted_quantity' => 6, 'reason' => 'record_error', 'note' => 'girişi yapılmamış koli'],
        ], $this->counter);
        $this->counts->submit($count, $this->counter);
        $this->counts->approve($count, $this->admin);

        $this->assertSame(6.0, (float) $lot->fresh()->quantity);
        $movement = StockMovement::where('lot_id', $lot->id)->sole();
        $this->assertSame(StockMovementType::CountAdjust, $movement->type);
        $this->assertSame(6.0, (float) $movement->quantity);
    }

    public function test_a_lot_that_was_empty_at_start_reuses_its_own_record(): void
    {
        $empty = app(StockMovementService::class)->in($this->product, $this->warehouse, 5, ['lot_no' => 'LOT-BOS'], $this->admin)->lot;
        app(StockMovementService::class)->out($this->product, $this->warehouse, 5, $empty, $this->admin, reasonCode: StockOutReason::Consumption);

        $count = $this->counts->start($this->warehouse, $this->counter);
        $this->assertSame(1, $count->lines()->count());

        $line = $this->counts->addLine($count, $this->product, 'LOT-BOS', null, $this->counter);

        $this->assertSame($empty->id, $line->stock_lot_id);
        $this->assertSame(2, StockLot::where('product_id', $this->product->id)->count());
    }

    public function test_adding_is_refused_for_duplicates_passive_products_and_closed_counts(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);

        $this->expectCountError(fn () => $this->counts->addLine($count, $this->product, 'LOT001', null, $this->counter), 'zaten var');

        $passive = Product::create(['name' => 'Eski Ürün', 'base_unit' => 'Adet', 'status' => 'passive']);
        $this->expectCountError(fn () => $this->counts->addLine($count, $passive, 'X1', null, $this->counter), 'pasif');

        $this->countAll($count, 100);
        $this->counts->submit($count, $this->counter);
        $this->expectCountError(fn () => $this->counts->addLine($count, $this->product, 'LOT-2', null, $this->counter), '"Sayılıyor"');
    }

    public function test_found_serial_tracked_lot_brings_its_serials_into_stock(): void
    {
        $implant = Product::create(['name' => 'İmplant', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);
        $count = $this->counts->start($this->warehouse, $this->counter);
        $line = $this->counts->addLine($count, $implant, 'IMP-9', null, $this->counter);

        $this->counts->record($count, [
            $count->lines()->where('id', '!=', $line->id)->sole()->id => ['counted_quantity' => 100],
            $line->id => ['counted_serials' => "S-1\nS-2", 'reason' => 'record_error'],
        ], $this->counter);
        $this->counts->submit($count, $this->counter);
        $this->counts->approve($count, $this->admin);

        $this->assertSame(2.0, (float) $line->lot->fresh()->quantity);
        $this->assertEqualsCanonicalizing(['S-1', 'S-2'], StockSerial::where('lot_id', $line->stock_lot_id)->where('status', SerialStatus::InStock->value)->pluck('serial_no')->all());
    }

    public function test_blind_count_hides_quantities_until_finished_then_locks_them(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter, blind: true);
        $this->assertTrue($count->hidesSystemQuantities());

        // Sayım bitmeden onaya gönderilemez, bitirmek için her satır sayılmalı.
        $this->expectCountError(fn () => $this->counts->submit($count, $this->counter), 'Sayımı Bitir');
        $this->expectCountError(fn () => $this->counts->reveal($count, $this->counter), 'henüz sayılmadı');

        $this->countAll($count, 97);
        $this->counts->reveal($count, $this->counter);
        $count->refresh();
        $this->assertFalse($count->hidesSystemQuantities());
        $this->assertTrue($count->countsLocked());

        // Farklar görüldükten sonra miktar değişmez, okutma ve satır ekleme de yok; neden girilir.
        $line = $count->lines()->sole();
        $this->expectCountError(fn () => $this->counts->record($count, [$line->id => ['counted_quantity' => 100]], $this->counter), 'değiştirilemez');
        $this->expectCountError(fn () => $this->counts->addLine($count, $this->product, 'LOT-2', null, $this->counter), 'değiştirilemez');
        $this->counts->record($count, [$line->id => ['counted_quantity' => '97', 'reason' => 'loss', 'note' => 'kırık']], $this->counter);
        $this->assertSame(97.0, (float) $line->fresh()->counted_quantity);

        $this->counts->submit($count, $this->counter);
        $this->counts->sendBack($count, $this->admin, 'tekrar sayın');
        $count->refresh();

        // Geri gönderilen kör sayım yeniden gizlenir ve miktarlar açılır.
        $this->assertSame(StockCountStatus::Counting, $count->status);
        $this->assertTrue($count->hidesSystemQuantities());
        $this->countAll($count, 98, ['reason' => 'loss', 'note' => 'kırık']);
        $this->counts->reveal($count, $this->counter);
        $this->counts->submit($count, $this->counter);
        $this->counts->approve($count, $this->admin);

        $this->assertSame(98.0, (float) StockLot::where('lot_no', 'LOT001')->sole()->quantity);
    }

    public function test_reveal_is_only_for_blind_counts(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);
        $this->countAll($count, 100);

        $this->expectCountError(fn () => $this->counts->reveal($count, $this->counter), 'kör sayım değil');
    }

    public function test_blind_count_screens_and_export_do_not_show_system_quantities(): void
    {
        $this->actingAs($this->counter);
        Livewire::test('pages::inventory.index')
            ->call('openStart')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('blind', true)
            ->call('start');
        $count = StockCount::sole();
        $this->assertTrue($count->blind);

        $this->get(route('inventory.index'))->assertOk()->assertSee('kör sayım')->assertSee('Gizli');

        $screen = Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->assertSee('Kör sayım.')->assertSee('Sayımı Bitir')->assertDontSee('Onaya Gönder')
            ->assertDontSee('100,00');

        $table = app(StockCountReportService::class)->table($count);
        $this->assertNull($table['rows']->sole()[3]);
        $this->assertSame('', $table['totals'][3]);

        $line = $count->lines()->sole();
        $screen->set("entries.{$line->id}.counted_quantity", '97')
            ->call('reveal')
            ->assertSee('farklar görünüyor')->assertSee('100,00')->assertSee('Onaya Gönder')
            ->assertSee('Kör sayım bitti.');
    }

    public function test_screen_adds_a_found_lot(): void
    {
        $this->actingAs($this->counter);
        $count = $this->counts->start($this->warehouse, $this->counter);

        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->set('showAddLine', true)
            ->assertSee('Kompozit A')
            ->call('addLine')->assertHasErrors('addProductId')
            ->set('addProductId', (string) $this->product->id)
            ->set('addLotNo', 'LOT-RAF')
            ->call('addLine')
            ->assertHasNoErrors()
            ->assertSee('LOT-RAF sayıma eklendi')
            ->assertSee('Sayımda eklendi');

        $this->assertSame(2, $count->lines()->count());
    }
}
