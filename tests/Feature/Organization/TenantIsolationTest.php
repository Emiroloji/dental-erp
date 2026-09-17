<?php

namespace Tests\Feature\Organization;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_never_sees_another_organizations_branches(): void
    {
        $organizationA = Organization::create(['name' => 'Organizasyon A', 'status' => 'active', 'plan' => 'starter']);
        $branchA = Branch::create(['organization_id' => $organizationA->id, 'name' => 'A Şubesi', 'status' => 'active']);

        $organizationB = Organization::create(['name' => 'Organizasyon B', 'status' => 'active', 'plan' => 'starter']);
        Branch::create(['organization_id' => $organizationB->id, 'name' => 'B Şubesi', 'status' => 'active']);

        $userB = User::factory()->create([
            'organization_id' => $organizationB->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $this->actingAs($userB);

        $visibleBranches = Branch::all();

        $this->assertCount(1, $visibleBranches);
        $this->assertSame('B Şubesi', $visibleBranches->first()->name);
        $this->assertFalse($visibleBranches->contains('id', $branchA->id));
    }

    public function test_creating_a_branch_while_authenticated_auto_assigns_current_organization(): void
    {
        $organization = Organization::create(['name' => 'Organizasyon', 'status' => 'active', 'plan' => 'starter']);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $branch = Branch::create(['name' => 'Yeni Şube', 'status' => 'active']);

        $this->assertSame($organization->id, $branch->organization_id);
    }
}
