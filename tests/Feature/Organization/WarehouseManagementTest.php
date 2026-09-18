<?php

namespace Tests\Feature\Organization;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Branch $otherBranch;

    private Warehouse $defaultWarehouse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->otherBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $this->defaultWarehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function staffIn(Branch $branch, string $name): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'name' => $name, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        return $staff->fresh();
    }

    private function createWarehouse(string $name, ?Branch $branch = null): Warehouse
    {
        Livewire::test('pages::organization.warehouses')
            ->call('create')
            ->set('branch_id', (string) ($branch ?? $this->branch)->id)
            ->set('name', $name)
            ->set('description', 'Ameliyathane yanı')
            ->call('save')
            ->assertHasNoErrors();

        return Warehouse::where('name', $name)->sole();
    }

    public function test_admin_adds_a_second_warehouse_to_a_branch(): void
    {
        $warehouse = $this->createWarehouse('Cerrahi Depo');

        $this->assertSame($this->branch->id, $warehouse->branch_id);
        $this->assertFalse($warehouse->is_default);
        $this->assertTrue($warehouse->isOperational());
        $this->assertSame('Ameliyathane yanı', $warehouse->description);
        $this->assertSame(2, $this->branch->warehouses()->count());
    }

    public function test_a_warehouse_cannot_be_added_to_a_passive_branch(): void
    {
        $this->otherBranch->update(['status' => 'passive']);

        Livewire::test('pages::organization.warehouses')
            ->call('create')
            ->set('branch_id', (string) $this->otherBranch->id)
            ->set('name', 'Yeni Depo')
            ->call('save')
            ->assertHasErrors('branch_id');

        $this->assertSame(0, Warehouse::where('name', 'Yeni Depo')->count());
    }

    public function test_admin_can_rename_a_warehouse_and_make_it_the_default(): void
    {
        $warehouse = $this->createWarehouse('Cerrahi Depo');

        Livewire::test('pages::organization.warehouses')
            ->call('edit', $warehouse->id)
            ->assertSet('name', 'Cerrahi Depo')
            ->set('name', 'Cerrahi Depo (Kat 2)')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::organization.warehouses')->call('makeDefault', $warehouse->id);

        $this->assertSame('Cerrahi Depo (Kat 2)', $warehouse->fresh()->name);
        $this->assertTrue($warehouse->fresh()->is_default);
        $this->assertFalse($this->defaultWarehouse->fresh()->is_default);
    }

    public function test_default_warehouse_and_warehouses_holding_stock_cannot_be_deactivated(): void
    {
        $warehouse = $this->createWarehouse('Cerrahi Depo');
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $warehouse, 5, [], $this->admin);

        Livewire::test('pages::organization.warehouses')->call('deactivate', $this->defaultWarehouse->id)->assertSee('varsayılan');
        Livewire::test('pages::organization.warehouses')->call('deactivate', $warehouse->id)->assertSee('stok var');

        $this->assertSame('active', $this->defaultWarehouse->fresh()->status);
        $this->assertSame('active', $warehouse->fresh()->status);

        $empty = $this->createWarehouse('Boş Depo');
        Livewire::test('pages::organization.warehouses')->call('deactivate', $empty->id);
        $this->assertFalse($empty->fresh()->isOperational());

        Livewire::test('pages::organization.warehouses')->call('activate', $empty->id);
        $this->assertTrue($empty->fresh()->isOperational());
    }

    public function test_managers_are_assigned_and_one_person_can_manage_several_warehouses(): void
    {
        $ayse = $this->staffIn($this->branch, 'Ayşe Depocu');
        $surgical = $this->createWarehouse('Cerrahi Depo');

        foreach ([$this->defaultWarehouse, $surgical] as $warehouse) {
            Livewire::test('pages::organization.warehouses')
                ->call('edit', $warehouse->id)
                ->set('managerIds', [(string) $ayse->id])
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertEqualsCanonicalizing([$this->defaultWarehouse->id, $surgical->id], $ayse->managedWarehouses()->pluck('warehouses.id')->all());
        $this->get('/depolar')->assertOk()->assertSee('Ayşe Depocu');

        $log = AuditLog::where('entity_type', Warehouse::class)->where('entity_id', $surgical->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame([], $log->before['managers']);
        $this->assertSame(['Ayşe Depocu'], $log->after['managers']);
    }

    public function test_a_manager_must_have_stock_access_to_the_warehouse_branch(): void
    {
        $outsider = $this->staffIn($this->otherBranch, 'Beşiktaşlı Personel');

        Livewire::test('pages::organization.warehouses')
            ->call('edit', $this->defaultWarehouse->id)
            ->set('managerIds', [(string) $outsider->id])
            ->call('save')
            ->assertHasErrors('managerIds');

        $this->assertSame(0, $this->defaultWarehouse->managers()->count());
    }

    public function test_staff_cannot_manage_warehouses(): void
    {
        $this->actingAs($this->staffIn($this->branch, 'Personel'))->get('/depolar')->assertForbidden();
    }

    public function test_warehouses_of_other_organizations_are_invisible(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreignBranch = Branch::create(['organization_id' => $other->id, 'name' => 'Yabancı', 'status' => 'active']);
        $foreign = Warehouse::create(['branch_id' => $foreignBranch->id, 'name' => 'Yabancı Depo', 'is_default' => true, 'status' => 'active']);

        $this->get('/depolar')->assertOk()->assertSee('Merkezi Depo')->assertDontSee('Yabancı Depo');
        Livewire::test('pages::organization.warehouses')->call('edit', $foreign->id)->assertNotFound();

        Livewire::test('pages::organization.warehouses')
            ->call('create')
            ->set('branch_id', (string) $foreignBranch->id)
            ->set('name', 'Sızma Denemesi')
            ->call('save')
            ->assertHasErrors('branch_id');
    }
}
