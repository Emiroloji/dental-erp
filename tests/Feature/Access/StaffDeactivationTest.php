<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_admin_deactivates_staff_and_change_is_audited(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->admin);

        Livewire::test('pages::access.staff')
            ->call('deactivate', $staff->id)
            ->assertHasNoErrors();

        $this->assertSame('passive', $staff->fresh()->status);

        $log = AuditLog::where('entity_type', $staff->getMorphClass())->where('entity_id', $staff->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('active', $log->before['status']);
        $this->assertSame('passive', $log->after['status']);
    }

    public function test_passive_staff_cannot_log_in(): void
    {
        $staff = $this->staff(['email' => 'pasif@klinik.test', 'password' => bcrypt('password123'), 'status' => 'passive']);

        Livewire::test('pages::access.login')
            ->set('email', 'pasif@klinik.test')
            ->set('password', 'password123')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_open_session_of_deactivated_staff_is_closed_on_next_request(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/dashboard')->assertOk();

        $staff->forceFill(['status' => 'passive'])->saveQuietly();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_manager_cannot_deactivate_own_account(): void
    {
        $manager = $this->staff();
        Permission::create([
            'user_id' => $manager->id,
            'module' => Module::StaffManagement->value,
            'can_read' => true,
            'can_write' => true,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);

        $this->actingAs($manager);

        Livewire::test('pages::access.staff')
            ->call('deactivate', $manager->id);

        $this->assertSame('active', $manager->fresh()->status);
    }

    public function test_staff_with_only_read_permission_cannot_deactivate(): void
    {
        $viewer = $this->staff();
        Permission::create([
            'user_id' => $viewer->id,
            'module' => Module::StaffManagement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);
        $target = $this->staff();

        $this->actingAs($viewer);

        Livewire::test('pages::access.staff')
            ->call('deactivate', $target->id)
            ->assertForbidden();

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_manager_cannot_deactivate_staff_outside_own_branch_scope(): void
    {
        $otherBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy', 'status' => 'active']);
        $manager = $this->staff();
        Permission::create([
            'user_id' => $manager->id,
            'module' => Module::StaffManagement->value,
            'can_read' => true,
            'can_write' => true,
            'can_delete' => false,
            'scope' => PermissionScope::OwnBranch->value,
        ]);
        $outsider = $this->staff(['branch_id' => $otherBranch->id]);

        $this->actingAs($manager);

        Livewire::test('pages::access.staff')
            ->call('deactivate', $outsider->id)
            ->assertNotFound();

        $this->assertSame('active', $outsider->fresh()->status);
    }

    public function test_admin_cannot_deactivate_staff_of_another_organization(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreign = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($this->admin);

        Livewire::test('pages::access.staff')
            ->call('deactivate', $foreign->id)
            ->assertNotFound();

        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_reactivation_respects_plan_user_limit(): void
    {
        $this->organization->update(['max_users' => 2]);
        $passive = $this->staff(['status' => 'passive']);
        $this->staff();

        $this->actingAs($this->admin);

        // Admin + 1 aktif personel = 2/2; pasif personel aktifleşemez.
        Livewire::test('pages::access.staff')
            ->call('activate', $passive->id);

        $this->assertSame('passive', $passive->fresh()->status);

        $this->organization->update(['max_users' => 3]);

        Livewire::test('pages::access.staff')
            ->call('activate', $passive->id);

        $this->assertSame('active', $passive->fresh()->status);
    }
}
