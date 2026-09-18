<?php

namespace Tests\Feature\Transfer;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Services\TransferService;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransferScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $kadikoy;

    private Branch $besiktas;

    private Warehouse $kadikoyDepot;

    private Warehouse $besiktasDepot;

    private Product $product;

    private User $admin;

    private User $requester;

    private User $sourceStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->kadikoy = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->besiktas = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $this->kadikoyDepot = Warehouse::create(['branch_id' => $this->kadikoy->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $this->besiktasDepot = Warehouse::create(['branch_id' => $this->besiktas->id, 'name' => 'Beşiktaş Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $this->product = Product::create(['name' => 'Lateks Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($this->product, $this->kadikoyDepot, 50, ['lot_no' => 'LOT-1'], $this->admin);

        $this->requester = $this->staff('Beşiktaş Hemşiresi', $this->besiktas);
        $this->sourceStaff = $this->staff('Kadıköy Depocusu', $this->kadikoy);
    }

    private function staff(string $name, Branch $branch, bool $write = true): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'name' => $name, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Transfer->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        return $staff->fresh();
    }

    private function requestAs(User $user, string $quantity = '20'): TransferRequest
    {
        $this->actingAs($user);

        Livewire::test('pages::transfer.index')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('from_warehouse_id', (string) $this->kadikoyDepot->id)
            ->set('to_warehouse_id', (string) $this->besiktasDepot->id)
            ->set('quantity', $quantity)
            ->set('reason', 'Eldiven bitmek üzere')
            ->call('save')
            ->assertHasNoErrors();

        return TransferRequest::latest('id')->firstOrFail();
    }

    private function stockIn(Warehouse $warehouse): float
    {
        return (float) StockLot::where('warehouse_id', $warehouse->id)->sum('quantity');
    }

    public function test_the_full_flow_runs_through_the_screen_with_each_side_doing_its_part(): void
    {
        $transfer = $this->requestAs($this->requester);
        $this->assertSame(TransferStatus::Pending, $transfer->status);
        $this->assertSame($this->requester->id, $transfer->requested_by);

        // Talep eden kendi talebini onaylayamaz; kaynak tarafı işlemleri de yapamaz.
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id)->assertForbidden();

        $this->actingAs($this->sourceStaff);
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id)->assertHasNoErrors();
        Livewire::test('pages::transfer.index')->call('prepare', $transfer->id);
        Livewire::test('pages::transfer.index')->call('receive', $transfer->id)->assertForbidden();
        Livewire::test('pages::transfer.index')->call('ship', $transfer->id);

        $this->assertSame(TransferStatus::Shipped, $transfer->fresh()->status);
        $this->assertSame(30.0, $this->stockIn($this->kadikoyDepot));
        $this->assertSame(0.0, $this->stockIn($this->besiktasDepot));

        $this->actingAs($this->requester);
        Livewire::test('pages::transfer.index')->call('receive', $transfer->id);

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(20.0, $this->stockIn($this->besiktasDepot));

        Livewire::test('pages::transfer.index')
            ->call('showDetail', $transfer->id)
            ->assertSee('Beşiktaş Hemşiresi')
            ->assertSee('Kadıköy Depocusu')
            ->assertSee('Teslim Alındı')
            ->assertSee('LOT-1');
    }

    public function test_staff_can_only_request_into_their_own_branch(): void
    {
        $this->actingAs($this->requester);

        Livewire::test('pages::transfer.index')
            ->call('openForm')
            ->assertSee('Beşiktaş Deposu')
            ->set('product_id', (string) $this->product->id)
            ->set('from_warehouse_id', (string) $this->besiktasDepot->id)
            ->set('to_warehouse_id', (string) $this->kadikoyDepot->id)
            ->set('quantity', '5')
            ->call('save')
            ->assertHasErrors('to_warehouse_id');

        $this->assertSame(0, TransferRequest::count());
    }

    public function test_shipping_without_enough_stock_shows_an_error_instead_of_failing(): void
    {
        $transfer = $this->requestAs($this->requester, '80');

        $this->actingAs($this->sourceStaff);
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id);
        Livewire::test('pages::transfer.index')->call('prepare', $transfer->id);
        Livewire::test('pages::transfer.index')->call('ship', $transfer->id)->assertSee('Yeterli stok yok');

        $this->assertSame(TransferStatus::Preparing, $transfer->fresh()->status);
        $this->assertSame(50.0, $this->stockIn($this->kadikoyDepot));
    }

    public function test_reject_and_cancel_record_the_reason(): void
    {
        $rejected = $this->requestAs($this->requester, '5');
        $cancelled = $this->requestAs($this->requester, '6');

        $this->actingAs($this->sourceStaff);
        Livewire::test('pages::transfer.index')
            ->call('askNote', $rejected->id, 'reject')
            ->set('note', 'Kadıköy de az stokta')
            ->call('confirmNote');

        $this->actingAs($this->requester);
        Livewire::test('pages::transfer.index')
            ->call('askNote', $cancelled->id, 'cancel')
            ->set('note', 'Tedarikçiden geldi, gerek kalmadı')
            ->call('confirmNote');

        $this->assertSame(TransferStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame('Kadıköy de az stokta', $rejected->events()->latest('id')->value('note'));
        $this->assertSame(TransferStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Tedarikçiden geldi, gerek kalmadı', $cancelled->events()->latest('id')->value('note'));
    }

    public function test_transfers_are_only_visible_to_the_branches_involved(): void
    {
        $transfer = app(TransferService::class)->request($this->product, $this->kadikoyDepot, $this->besiktasDepot, 5, null, $this->admin);

        $sisli = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Şişli Şubesi', 'status' => 'active']);
        $bystander = $this->staff('Şişli Personeli', $sisli);

        $this->actingAs($bystander)->get('/transferler')->assertOk()->assertDontSee('Lateks Eldiven');
        Livewire::test('pages::transfer.index')->call('showDetail', $transfer->id)->assertNotFound();
        Livewire::test('pages::transfer.index')->call('cancel', $transfer->id)->assertNotFound();

        $this->actingAs($this->requester)->get('/transferler')->assertOk()->assertSee('Lateks Eldiven');
    }

    public function test_staff_without_transfer_permission_cannot_open_the_screen(): void
    {
        $noTransfer = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->kadikoy->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $noTransfer->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => 'own_branch']);

        $this->actingAs($noTransfer)->get('/transferler')->assertForbidden();
    }

    public function test_read_only_transfer_staff_sees_but_cannot_act(): void
    {
        $transfer = app(TransferService::class)->request($this->product, $this->kadikoyDepot, $this->besiktasDepot, 5, null, $this->admin);
        $viewer = $this->staff('Sadece Okur', $this->kadikoy, write: false);

        $this->actingAs($viewer);
        Livewire::test('pages::transfer.index')->assertSee('Lateks Eldiven')->assertDontSee('Yeni Talep')->call('approve', $transfer->id)->assertForbidden();
    }

    public function test_other_organizations_transfers_are_invisible(): void
    {
        $other = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        app(TransferService::class)->request($this->product, $this->kadikoyDepot, $this->besiktasDepot, 5, null, $this->admin);

        $this->actingAs($otherAdmin)->get('/transferler')->assertOk()->assertDontSee('Lateks Eldiven');
    }
}
