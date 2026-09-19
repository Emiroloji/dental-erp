<?php

namespace Tests\Feature\Medical;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\ExpiredLotBlockedException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Transfer\Models\TransferRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aşama 26 — birim bazında seri takibi, stok motoru düzeyinde. Her testin
 * sonunda değişmez doğrulanır: lot miktarı = o lotta stoktaki seri sayısı.
 */
class SerialTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $central;

    private Warehouse $north;

    private Product $implant;

    private User $admin;

    private StockMovementService $stock;

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

        $this->stock = app(StockMovementService::class);
        $this->implant = Product::create(['name' => 'İmplant 4.1x10', 'base_unit' => 'Adet', 'status' => 'active', 'product_type' => 'equipment', 'tracks_serials' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (StockLot::withoutGlobalScopes()->get() as $lot) {
                $inStock = StockSerial::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', SerialStatus::InStock->value)->count();
                if ($lot->product?->tracks_serials) {
                    $this->assertEquals((float) $lot->quantity, $inStock, "Lot {$lot->lot_no}: miktar ≠ stoktaki seri sayısı");
                }
            }
        }

        parent::tearDown();
    }

    private function receive(array $serials, string $lot = 'IMP-1', ?Warehouse $warehouse = null): void
    {
        $this->stock->in($this->implant, $warehouse ?? $this->central, count($serials), ['lot_no' => $lot], $this->admin, 'Giriş', tracking: ['serials' => $serials]);
    }

    private function serialStatus(string $serial): SerialStatus
    {
        return StockSerial::where('serial_no', $serial)->sole()->status;
    }

    public function test_stock_in_registers_each_serial_on_the_lot(): void
    {
        $this->receive(['SN1', ' SN2 ', 'SN3']);

        $this->assertSame(['SN1', 'SN2', 'SN3'], StockSerial::orderBy('serial_no')->pluck('serial_no')->all());
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN2'));
        $this->assertEquals(3, StockLot::sole()->quantity);
    }

    public function test_serial_count_must_match_quantity_and_be_unique(): void
    {
        foreach ([
            fn () => $this->stock->in($this->implant, $this->central, 3, ['lot_no' => 'L'], $this->admin, tracking: ['serials' => ['A', 'B']]),
            fn () => $this->stock->in($this->implant, $this->central, 2, ['lot_no' => 'L'], $this->admin, tracking: ['serials' => ['A', 'A']]),
            fn () => $this->stock->in($this->implant, $this->central, 1.5, ['lot_no' => 'L'], $this->admin, tracking: ['serials' => ['A']]),
            fn () => $this->stock->in($this->implant, $this->central, 1, ['lot_no' => 'L'], $this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Geçersiz seri girişi kabul edilmemeliydi.');
            } catch (SerialException) {
            }
        }

        $this->assertSame(0, StockSerial::count());
    }

    public function test_a_serial_already_in_stock_cannot_be_received_again(): void
    {
        $this->receive(['SN1']);

        $this->expectException(SerialException::class);
        $this->receive(['SN1'], 'IMP-2', $this->north);
    }

    public function test_stock_out_uses_the_selected_serials_and_records_history(): void
    {
        $this->receive(['SN1', 'SN2'], 'IMP-1');
        $this->receive(['SN3'], 'IMP-2');

        $movements = $this->stock->out($this->implant, $this->central, 2, null, $this->admin, 'Hastaya uygulandı', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN1', 'SN3']]);

        // Seriler iki ayrı lottan: lot başına bir hareket.
        $this->assertCount(2, $movements);
        $this->assertSame(SerialStatus::Out, $this->serialStatus('SN1'));
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN2'));
        $this->assertSame(['in', 'out'], StockSerial::where('serial_no', 'SN1')->sole()->movements()->orderBy('stock_movements.id')->pluck('type')->map->value->all());
    }

    public function test_stock_out_rejects_serials_not_in_that_warehouse_or_lot(): void
    {
        $this->receive(['SN1'], 'IMP-1');
        $this->receive(['SN9'], 'IMP-K', $this->north);

        foreach ([
            fn () => $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN9']]),
            fn () => $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['YOK']]),
            fn () => $this->stock->out($this->implant, $this->central, 1, StockLot::where('lot_no', 'IMP-K')->sole(), $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN1']]),
            fn () => $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Geçersiz seri çıkışı kabul edilmemeliydi.');
            } catch (SerialException) {
            }
        }

        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN1'));
    }

    public function test_expired_policy_applies_to_serial_outs(): void
    {
        $this->organization->update(['settings' => ['expired_lot_policy' => 'block']]);
        $this->stock->in($this->implant, $this->central, 1, ['lot_no' => 'ESKI', 'expiry_date' => now()->subDay()->toDateString()], $this->admin, tracking: ['serials' => ['OLD1']]);

        try {
            $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['OLD1']]);
            $this->fail('SKT geçmiş seri kullanılmamalıydı.');
        } catch (ExpiredLotBlockedException) {
        }

        // İmha serbest.
        $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'İmha', StockOutReason::Expired, tracking: ['serials' => ['OLD1']]);
        $this->assertSame(SerialStatus::Out, $this->serialStatus('OLD1'));
    }

    public function test_cancelling_an_out_returns_serials_and_cancelling_an_in_voids_them(): void
    {
        $this->receive(['SN1', 'SN2']);
        $out = $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN1']])->first();

        $this->stock->cancel($out, $this->admin);
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN1'));

        $entry = $this->stock->in($this->implant, $this->central, 1, ['lot_no' => 'IMP-3'], $this->admin, tracking: ['serials' => ['SN5']]);
        $this->stock->cancel($entry, $this->admin);
        $this->assertSame(SerialStatus::Void, $this->serialStatus('SN5'));

        // İptal edilen giriş serisi yeniden girilebilir.
        $this->receive(['SN5'], 'IMP-3');
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN5'));
    }

    public function test_an_entry_cannot_be_cancelled_once_its_serials_were_used(): void
    {
        $this->receive(['SN1']);
        $entry = StockSerial::where('serial_no', 'SN1')->sole()->movements()->sole();
        $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN1']]);

        try {
            $this->stock->cancel($entry, $this->admin);
            $this->fail('Serisi kullanılmış giriş iptal edilmemeliydi.');
        } catch (SerialException|InsufficientStockException) {
        }

        $this->assertSame(SerialStatus::Out, $this->serialStatus('SN1'));
    }

    public function test_transfer_moves_serials_in_transit_then_to_destination_lot(): void
    {
        $this->receive(['SN1', 'SN2', 'SN3'], 'IMP-1');
        $transfer = TransferRequest::create(['organization_id' => $this->organization->id, 'product_id' => $this->implant->id, 'from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->north->id, 'quantity' => 2, 'status' => 'shipped', 'requested_by' => $this->admin->id]);

        $shipped = $this->stock->transferOut($this->implant, $this->central, 2, $transfer, $this->admin);
        $this->assertSame([SerialStatus::InTransit, SerialStatus::InTransit, SerialStatus::InStock], [$this->serialStatus('SN1'), $this->serialStatus('SN2'), $this->serialStatus('SN3')]);

        $received = $this->stock->transferIn($shipped->first(), $this->north, $transfer, $this->admin);
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN1'));
        $this->assertSame($received->lot_id, StockSerial::where('serial_no', 'SN1')->sole()->lot_id);
        $this->assertSame('IMP-1', $received->lot->lot_no);

        // Kuzey'de çıkış artık mümkün, Merkez'de değil.
        $this->stock->out($this->implant, $this->north, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN2']]);
        $this->assertSame(SerialStatus::Out, $this->serialStatus('SN2'));
    }

    public function test_cancelling_a_shipped_transfer_returns_serials_to_source(): void
    {
        $this->receive(['SN1', 'SN2'], 'IMP-1');
        $transfer = TransferRequest::create(['organization_id' => $this->organization->id, 'product_id' => $this->implant->id, 'from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->north->id, 'quantity' => 1, 'status' => 'shipped', 'requested_by' => $this->admin->id]);

        $shipped = $this->stock->transferOut($this->implant, $this->central, 1, $transfer, $this->admin)->first();
        $this->stock->cancel($shipped, $this->admin);

        $this->assertSame(0, StockSerial::where('status', SerialStatus::InTransit->value)->count());
        $this->assertEquals(2, StockLot::sole()->quantity);
    }

    public function test_count_reconciles_missing_and_found_serials(): void
    {
        $this->receive(['SN1', 'SN2', 'SN3'], 'IMP-1');
        $lot = StockLot::sole();

        // Sayımda SN2 yok, raftan kayıtsız SN7 çıktı: 3 → 3 (1 kayıp, 1 bulunan).
        $movement = $this->stock->adjust($lot, 3, 'Sayım', $this->admin, tracking: ['serials' => ['SN1', 'SN3', 'SN7']]);

        $this->assertEquals(0, (float) $movement->quantity);
        $this->assertSame(SerialStatus::Lost, $this->serialStatus('SN2'));
        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN7'));
        $this->assertEqualsCanonicalizing(['SN2', 'SN7'], $movement->serials()->pluck('serial_no')->all());

        // Hepsi kayıp: 0.
        $this->stock->adjust($lot, 0, 'Sayım', $this->admin, tracking: ['serials' => []]);
        $this->assertSame(0, StockSerial::where('status', SerialStatus::InStock->value)->count());
    }

    public function test_count_list_must_match_counted_quantity(): void
    {
        $this->receive(['SN1', 'SN2'], 'IMP-1');

        $this->expectException(SerialException::class);
        $this->stock->adjust(StockLot::sole(), 2, 'Sayım', $this->admin, tracking: ['serials' => ['SN1']]);
    }

    public function test_a_serial_that_left_stock_can_come_back(): void
    {
        $this->receive(['SN1']);
        $this->stock->out($this->implant, $this->central, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse, tracking: ['serials' => ['SN1']]);

        $this->receive(['SN1'], 'IMP-1');

        $this->assertSame(SerialStatus::InStock, $this->serialStatus('SN1'));
        $this->assertSame(3, StockSerial::where('serial_no', 'SN1')->sole()->movements()->count());
    }

    public function test_serial_numbers_are_isolated_per_organization(): void
    {
        $this->receive(['SN1']);

        $rival = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $rivalBranch = Branch::create(['organization_id' => $rival->id, 'name' => 'Merkez', 'status' => 'active']);
        $rivalWarehouse = Warehouse::create(['branch_id' => $rivalBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $rivalAdmin = User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($rivalAdmin);
        $rivalImplant = Product::create(['name' => 'Rakip İmplant', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);

        // Aynı seri numarası başka organizasyonun (başka ürünün) serisiyle çakışmaz ve görünmez.
        $this->stock->in($rivalImplant, $rivalWarehouse, 1, ['lot_no' => 'R'], $rivalAdmin, tracking: ['serials' => ['SN1']]);
        $this->assertSame(1, StockSerial::count());
        $this->assertSame(2, StockSerial::withoutGlobalScopes()->count());
    }
}
