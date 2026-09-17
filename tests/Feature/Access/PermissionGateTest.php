<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bypasses_every_module_gate(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $this->assertTrue($admin->can('product_management.viewAny'));
        $this->assertTrue($admin->can('product_management.create'));
        $this->assertTrue($admin->can('product_management.delete'));
        $this->assertTrue($admin->can('staff_management.create'));
    }

    public function test_staff_with_only_reports_read_is_restricted_to_that(): void
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
        $staff->load('permissions');

        $this->assertTrue($staff->can('reports.viewAny'));
        $this->assertFalse($staff->can('reports.create'));
        $this->assertFalse($staff->can('reports.delete'));

        foreach (Module::cases() as $module) {
            if ($module === Module::Reports) {
                continue;
            }

            $this->assertFalse($staff->can("{$module->value}.viewAny"), "{$module->value}.viewAny should be denied");
            $this->assertFalse($staff->can("{$module->value}.create"), "{$module->value}.create should be denied");
            $this->assertFalse($staff->can("{$module->value}.delete"), "{$module->value}.delete should be denied");
        }
    }

    public function test_staff_without_any_permission_row_is_denied_everywhere(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ]);

        foreach (Module::cases() as $module) {
            $this->assertFalse($staff->can("{$module->value}.viewAny"));
        }
    }
}
