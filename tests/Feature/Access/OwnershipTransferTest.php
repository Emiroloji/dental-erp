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

/**
 * Ana Klinik Sahibi devri (proje.md Bölüm 4).
 */
class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Eski Sahip', 'password' => bcrypt('sahip-sifre'), 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'name' => 'Yeni Sahip', 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $this->staff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
    }

    public function test_owner_transfers_ownership_and_becomes_full_access_staff(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::access.staff')
            ->call('askTransfer', $this->staff->id)
            ->assertSee('organizasyonun yeni sahibi (Admin) olacak')
            ->set('transferPassword', 'sahip-sifre')
            ->call('transferOwnership')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $newOwner = $this->staff->fresh();
        $formerOwner = $this->owner->fresh();

        $this->assertTrue($newOwner->isAdmin());
        $this->assertSame(0, $newOwner->permissions()->count());
        $this->assertSame(User::ROLE_STAFF, $formerOwner->role);
        $this->assertTrue($formerOwner->isActive());

        foreach (Module::cases() as $module) {
            $this->assertTrue($formerOwner->canModule($module, 'delete'), $module->value);
        }
        $this->assertNull($formerOwner->accessibleBranchIds(Module::StockMovement));

        // Organizasyonda hâlâ tam bir aktif Admin var.
        $this->assertSame(1, User::where('organization_id', $this->organization->id)->where('role', User::ROLE_ADMIN)->where('status', 'active')->count());

        $this->assertSame('Ana Klinik Sahibi oldunuz', $newOwner->notifications()->sole()->data['title']);
        $this->assertTrue(AuditLog::where('entity_type', $newOwner->getMorphClass())->where('entity_id', $newOwner->id)->where('action', 'updated')->exists());
        $this->assertTrue(AuditLog::where('entity_type', (new Permission)->getMorphClass())->where('action', 'deleted')->exists());

        // Eski sahip artık paket ekranına giremez; yeni sahip girer.
        $this->get('/paket')->assertForbidden();
        $this->actingAs($newOwner)->get('/paket')->assertOk();
    }

    public function test_wrong_password_changes_nothing(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::access.staff')
            ->call('askTransfer', $this->staff->id)
            ->set('transferPassword', 'yanlis')
            ->call('transferOwnership')
            ->assertHasErrors(['transferPassword']);

        $this->assertTrue($this->owner->fresh()->isAdmin());
        $this->assertFalse($this->staff->fresh()->isAdmin());
    }

    public function test_cannot_transfer_to_passive_staff_or_another_organization(): void
    {
        $this->actingAs($this->owner);

        $this->staff->update(['status' => 'passive']);
        Livewire::test('pages::access.staff')
            ->call('askTransfer', $this->staff->id)
            ->set('transferPassword', 'sahip-sifre')
            ->call('transferOwnership')
            ->assertHasErrors(['transferPassword']);
        $this->assertTrue($this->owner->fresh()->isAdmin());

        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter'])->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Livewire::test('pages::access.staff')->call('askTransfer', $foreign->id)->assertNotFound();
        $this->assertFalse($foreign->fresh()->isAdmin());
    }

    public function test_staff_manager_cannot_transfer_ownership(): void
    {
        $manager = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $manager->id, 'module' => Module::StaffManagement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => PermissionScope::All->value]);

        $this->actingAs($manager);

        Livewire::test('pages::access.staff')
            ->assertDontSee('Sahipliği Devret')
            ->call('askTransfer', $this->staff->id)
            ->assertForbidden();
    }

    public function test_read_only_organization_cannot_transfer_ownership(): void
    {
        $this->organization->update(['status' => 'read_only']);
        $this->actingAs($this->owner);

        Livewire::test('pages::access.staff')
            ->call('askTransfer', $this->staff->id)
            ->assertForbidden();

        $this->assertTrue($this->owner->fresh()->isAdmin());
    }
}
