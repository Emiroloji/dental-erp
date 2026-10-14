<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Exceptions\StaffRuleException;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Services\StaffService;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FinancePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
    }

    private function staff(array $abilities = []): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        if ($abilities !== []) {
            Permission::create([
                'user_id' => $staff->id, 'module' => Module::Finance->value,
                'can_read' => $abilities['read'] ?? false, 'can_write' => $abilities['write'] ?? false, 'can_delete' => false,
                'scope' => PermissionScope::All->value,
            ]);
        }

        return $staff;
    }

    public function test_admin_has_finance_abilities(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->assertTrue(Gate::forUser($admin)->allows('finance.viewAny'));
        $this->assertTrue(Gate::forUser($admin)->allows('finance.create'));
    }

    public function test_staff_without_finance_permission_is_denied(): void
    {
        $this->assertFalse(Gate::forUser($this->staff())->allows('finance.viewAny'));
    }

    public function test_staff_with_read_only_finance_cannot_write(): void
    {
        $staff = $this->staff(['read' => true]);

        $this->assertTrue(Gate::forUser($staff)->allows('finance.viewAny'));
        $this->assertFalse(Gate::forUser($staff)->allows('finance.create'));
        $this->assertFalse(Gate::forUser($staff)->allows('finance.update'));
    }

    public function test_finance_cannot_be_granted_with_branch_limited_scope(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $this->expectException(StaffRuleException::class);
        $this->expectExceptionMessage('Finans yetkisi yalnızca "Tüm şubeler" kapsamıyla verilebilir.');

        app(StaffService::class)->createStaff(
            ['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'gizli-sifre', 'branch_id' => $this->branch->id],
            [Module::Finance->value => ['read' => true, 'write' => false, 'delete' => false]],
            PermissionScope::OwnBranch,
        );
    }

    public function test_finance_can_be_granted_with_all_branches_scope(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $staff = app(StaffService::class)->createStaff(
            ['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'gizli-sifre', 'branch_id' => $this->branch->id],
            [Module::Finance->value => ['read' => true, 'write' => true, 'delete' => false]],
            PermissionScope::All,
        );

        $this->assertTrue(Gate::forUser($staff->fresh())->allows('finance.create'));
    }
}
