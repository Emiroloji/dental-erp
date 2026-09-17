<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_staff_page(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $this->actingAs($admin)->get('/personel')->assertOk();
    }

    public function test_staff_without_staff_management_permission_is_forbidden(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ]);

        Permission::create([
            'user_id' => $staff->id,
            'module' => Module::Reports->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::OwnBranch->value,
        ]);

        $this->actingAs($staff)->get('/personel')->assertForbidden();
    }

    public function test_admin_can_create_staff_with_module_permissions(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        Livewire::test('pages::access.staff')
            ->set('name', 'Yeni Personel')
            ->set('email', 'personel@klinik.test')
            ->set('password', 'password123')
            ->set('modules.reports.read', true)
            ->set('scope', 'own_branch')
            ->call('save')
            ->assertHasNoErrors();

        $newStaff = User::where('email', 'personel@klinik.test')->firstOrFail();

        $this->assertSame(User::ROLE_STAFF, $newStaff->role);
        $this->assertSame($organization->id, $newStaff->organization_id);

        $permission = Permission::where('user_id', $newStaff->id)->where('module', Module::Reports->value)->first();
        $this->assertNotNull($permission);
        $this->assertTrue($permission->can_read);
        $this->assertFalse($permission->can_write);

        $this->assertNull(
            Permission::where('user_id', $newStaff->id)->where('module', Module::ProductManagement->value)->first()
        );
    }

    public function test_selected_branches_scope_persists_chosen_branches(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $branchA = Branch::create(['organization_id' => $organization->id, 'name' => 'Şube A', 'status' => 'active']);
        Branch::create(['organization_id' => $organization->id, 'name' => 'Şube B', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::access.staff')
            ->set('name', 'Şube Personeli')
            ->set('email', 'sube-personeli@klinik.test')
            ->set('password', 'password123')
            ->set('modules.stock_movement.read', true)
            ->set('modules.stock_movement.write', true)
            ->set('scope', 'selected_branches')
            ->set('selectedBranches', [$branchA->id])
            ->call('save')
            ->assertHasNoErrors();

        $newStaff = User::where('email', 'sube-personeli@klinik.test')->firstOrFail();
        $permission = Permission::where('user_id', $newStaff->id)->where('module', Module::StockMovement->value)->firstOrFail();

        $this->assertSame(PermissionScope::SelectedBranches, $permission->scope);
        $this->assertCount(1, $permission->branches);
        $this->assertSame($branchA->id, $permission->branches->first()->id);
    }
}
