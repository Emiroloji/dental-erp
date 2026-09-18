<?php

namespace Tests\Feature\Organization;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $mainBranch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->mainBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez Şube', 'status' => 'active']);
        Warehouse::create(['branch_id' => $this->mainBranch->id, 'name' => 'Varsayılan Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    public function test_creating_a_branch_opens_its_default_warehouse(): void
    {
        Livewire::test('pages::organization.branches')
            ->call('create')
            ->set('name', 'Kadıköy Şubesi')
            ->set('address', 'Caferağa Mah. No:1')
            ->set('phone', '0216 000 00 00')
            ->call('save')
            ->assertHasNoErrors();

        $branch = Branch::where('name', 'Kadıköy Şubesi')->sole();

        $this->assertSame($this->organization->id, $branch->organization_id);
        $this->assertSame('active', $branch->status);
        $this->assertSame('Caferağa Mah. No:1', $branch->address);

        $warehouse = $branch->warehouses()->sole();
        $this->assertTrue($warehouse->is_default);
        $this->assertTrue($warehouse->isOperational());
    }

    public function test_admin_can_edit_a_branch(): void
    {
        Livewire::test('pages::organization.branches')
            ->call('edit', $this->mainBranch->id)
            ->assertSet('name', 'Merkez Şube')
            ->set('name', 'Merkez (Nişantaşı)')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Merkez (Nişantaşı)', $this->mainBranch->fresh()->name);
        $this->assertSame(1, Branch::count());
    }

    public function test_a_branch_holding_stock_cannot_be_deactivated(): void
    {
        $second = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $second->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $warehouse, 5, [], $this->admin);

        Livewire::test('pages::organization.branches')->call('deactivate', $second->id);
        $this->assertSame('active', $second->fresh()->status);

        app(StockMovementService::class)->out($product, $warehouse, 5, null, $this->admin, 'Sarf', StockOutReason::Consumption);

        Livewire::test('pages::organization.branches')->call('deactivate', $second->id);
        $this->assertSame('passive', $second->fresh()->status);
        $this->assertFalse($warehouse->fresh()->isOperational());

        Livewire::test('pages::organization.branches')->call('activate', $second->id);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_the_last_active_branch_cannot_be_deactivated(): void
    {
        Livewire::test('pages::organization.branches')
            ->call('deactivate', $this->mainBranch->id)
            ->assertSee('En az bir aktif şube');

        $this->assertSame('active', $this->mainBranch->fresh()->status);
    }

    public function test_staff_cannot_manage_branches_even_with_every_module_permission(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->mainBranch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        foreach (Module::cases() as $module) {
            Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'all']);
        }

        $this->actingAs($staff)->get('/subeler')->assertForbidden();
    }

    public function test_branches_of_other_organizations_are_not_listed_or_editable(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreign = Branch::create(['organization_id' => $other->id, 'name' => 'Yabancı Şube', 'status' => 'active']);

        $this->get('/subeler')->assertOk()->assertSee('Merkez Şube')->assertDontSee('Yabancı Şube');

        Livewire::test('pages::organization.branches')->call('edit', $foreign->id)->assertNotFound();
    }
}
