<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockCountServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Product $product;

    private User $admin;

    private User $counter;

    private StockCountService $counts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->counter = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $this->counter->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'own_branch']);
        $this->counter = $this->counter->fresh();

        $this->actingAs($this->admin);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT001', 'unit_cost' => 500], $this->admin);

        $this->counts = app(StockCountService::class);
    }

    private function lot(): StockLot
    {
        return StockLot::where('lot_no', 'LOT001')->sole();
    }

    private function countedAndSubmitted(float $counted, ?string $reason = 'record_error', ?string $note = 'kullanım kaydı eksik girilmiş'): StockCount
    {
        $count = $this->counts->start($this->warehouse, $this->counter);
        $line = $count->lines()->sole();
        $this->counts->record($count, [$line->id => ['counted_quantity' => $counted, 'reason' => $reason, 'note' => $note]], $this->counter);
        $this->counts->submit($count, $this->counter);

        return $count->refresh();
    }

    public function test_start_snapshots_every_lot_with_its_system_quantity(): void
    {
        $this->actingAs($this->admin);
        $second = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($second, $this->warehouse, 40, ['lot_no' => 'ELD-1'], $this->admin);

        $count = $this->counts->start($this->warehouse, $this->counter, 'Ay sonu sayımı');

        $this->assertSame(StockCountStatus::Counting, $count->status);
        $this->assertEqualsCanonicalizing([100.0, 40.0], $count->lines->pluck('system_quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertTrue($count->lines->every(fn ($line) => $line->counted_quantity === null));
    }

    public function test_documented_example_100_counted_97_is_corrected_to_97_with_an_audit_trail(): void
    {
        $count = $this->countedAndSubmitted(97);
        $this->assertSame(-3.0, $count->lines()->sole()->difference());
        $this->assertSame(100.0, (float) $this->lot()->quantity, 'onaydan önce stok değişmez');

        $this->counts->approve($count, $this->admin);

        $this->assertSame(StockCountStatus::Approved, $count->fresh()->status);
        $this->assertSame(97.0, (float) $this->lot()->quantity);

        $movement = StockMovement::where('type', StockMovementType::CountAdjust->value)->sole();
        $this->assertSame(-3.0, (float) $movement->quantity);
        $this->assertSame(StockCount::class, $movement->related_entity_type);
        $this->assertStringContainsString('Kayıt hatası — kullanım kaydı eksik girilmiş', $movement->reason);
        $this->assertSame($this->admin->id, $movement->actor_id);
        $this->assertSame($movement->id, $count->lines()->sole()->stock_movement_id);

        $audit = AuditLog::where('entity_type', StockCount::class)->where('entity_id', $count->id)->whereNotNull('before->stok')->sole();
        $this->assertEquals(['Kompozit A / LOT001' => 100], $audit->before['stok']);
        $this->assertEquals(['Kompozit A / LOT001' => 97], $audit->after['stok']);
        $this->assertSame($this->admin->id, $audit->actor_id);
    }

    public function test_a_surplus_is_booked_as_a_positive_correction(): void
    {
        $this->counts->approve($this->countedAndSubmitted(104), $this->admin);

        $this->assertSame(104.0, (float) $this->lot()->quantity);
        $this->assertSame(4.0, (float) StockMovement::where('type', 'count_adjust')->sole()->quantity);
    }

    public function test_lines_without_difference_create_no_movement(): void
    {
        $this->counts->approve($this->countedAndSubmitted(100, null, null), $this->admin);

        $this->assertSame(0, StockMovement::where('type', 'count_adjust')->count());
        $this->assertSame(100.0, (float) $this->lot()->quantity);
    }

    public function test_submission_requires_every_line_counted_and_a_reason_for_each_difference(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);
        $line = $count->lines()->sole();

        foreach ([
            [null, null, null],               // sayılmamış
            [97, null, null],                 // fark var, neden yok
            [97, 'other', null],              // "Diğer" için açıklama yok
        ] as [$counted, $reason, $note]) {
            $this->counts->record($count, [$line->id => ['counted_quantity' => $counted, 'reason' => $reason, 'note' => $note]], $this->counter);

            try {
                $this->counts->submit($count, $this->counter);
                $this->fail('Eksik sayım onaya gönderilebildi.');
            } catch (StockCountException) {
            }
        }

        $this->assertSame(StockCountStatus::Counting, $count->fresh()->status);
    }

    public function test_only_admin_approves_or_sends_back(): void
    {
        $count = $this->countedAndSubmitted(97);

        foreach (['approve', 'sendBack'] as $action) {
            try {
                $this->counts->{$action}($count, $this->counter);
                $this->fail("Personel {$action} yapabildi.");
            } catch (AuthorizationException) {
            }
        }

        $this->counts->sendBack($count, $this->admin, 'Rafları tekrar say');
        $this->assertSame(StockCountStatus::Counting, $count->fresh()->status);
        $this->assertSame(100.0, (float) $this->lot()->quantity);
    }

    public function test_approval_is_refused_if_stock_moved_during_the_count_and_refresh_recovers(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);
        $line = $count->lines()->sole();

        // Sayım sürerken klinik 10 adet kullandı: sistem artık 90.
        app(StockMovementService::class)->out($this->product, $this->warehouse, 10, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse);

        $this->counts->record($count, [$line->id => ['counted_quantity' => 87, 'reason' => 'loss']], $this->counter);
        $this->counts->submit($count, $this->counter);

        try {
            $this->counts->approve($count, $this->admin);
            $this->fail('Arada hareket olmasına rağmen onaylandı.');
        } catch (StockCountException $e) {
            $this->assertStringContainsString('Kompozit A / LOT001', $e->getMessage());
        }
        $this->assertSame(90.0, (float) $this->lot()->quantity, 'araya giren kullanım ezilmedi');

        $this->counts->sendBack($count, $this->admin);
        $this->counts->refreshSystemQuantities($count, $this->counter);
        $this->assertSame(-3.0, $line->fresh()->difference(), 'sayılan korunur, fark yeni sisteme göre');

        $this->counts->submit($count, $this->counter);
        $this->counts->approve($count, $this->admin);

        $this->assertSame(87.0, (float) $this->lot()->quantity);
    }

    public function test_one_open_count_per_warehouse_and_no_edits_after_submission(): void
    {
        $count = $this->counts->start($this->warehouse, $this->counter);

        try {
            $this->counts->start($this->warehouse, $this->counter);
            $this->fail('Aynı depoda ikinci açık sayım başlatıldı.');
        } catch (StockCountException) {
        }

        $line = $count->lines()->sole();
        $this->counts->record($count, [$line->id => ['counted_quantity' => 100]], $this->counter);
        $this->counts->submit($count, $this->counter);

        $this->expectException(StockCountException::class);
        $this->counts->record($count, [$line->id => ['counted_quantity' => 50]], $this->counter);
    }

    public function test_cancelled_count_has_no_stock_effect_and_frees_the_warehouse(): void
    {
        $count = $this->countedAndSubmitted(97);
        $this->counts->cancel($count, $this->counter, 'Yanlış depo');

        $this->assertSame(StockCountStatus::Cancelled, $count->fresh()->status);
        $this->assertSame(100.0, (float) $this->lot()->quantity);
        $this->assertSame(StockCountStatus::Counting, $this->counts->start($this->warehouse, $this->counter)->status);
    }

    public function test_count_corrections_cannot_be_cancelled_from_the_movements_screen(): void
    {
        $this->counts->approve($this->countedAndSubmitted(97), $this->admin);

        Livewire::test('pages::stock.movements')->call('cancel', StockMovement::where('type', 'count_adjust')->sole()->id);

        $this->assertSame(97.0, (float) $this->lot()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'cancel')->count());
    }

    public function test_counting_needs_stock_write_permission_in_scope(): void
    {
        $reader = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->warehouse->branch_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $reader->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => 'own_branch']);

        $this->expectException(AuthorizationException::class);
        $this->counts->start($this->warehouse, $reader->fresh());
    }
}
