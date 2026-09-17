<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Category;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_category(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.categories')
            ->set('name', 'Kompozitler')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'organization_id' => $organization->id,
            'name' => 'Kompozitler',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_deactivate_a_category_instead_of_deleting_it(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $category = Category::create(['organization_id' => $organization->id, 'name' => 'Anestezikler', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.categories')
            ->call('deactivate', $category->id);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'status' => 'passive']);
    }

    public function test_staff_without_permission_cannot_view_categories_page(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/kategoriler')->assertForbidden();
    }

    public function test_staff_with_read_only_permission_cannot_create_category(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        Permission::create([
            'user_id' => $staff->id,
            'module' => Module::CategoryManagement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::OwnBranch->value,
        ]);

        $this->actingAs($staff)->get('/kategoriler')->assertOk();

        Livewire::test('pages::catalog.categories')
            ->set('name', 'Yeni Kategori')
            ->call('save')
            ->assertForbidden();
    }

    public function test_categories_are_isolated_per_organization(): void
    {
        $organizationA = Organization::create(['name' => 'A', 'status' => 'active', 'plan' => 'starter']);
        $organizationB = Organization::create(['name' => 'B', 'status' => 'active', 'plan' => 'starter']);

        Category::create(['organization_id' => $organizationA->id, 'name' => 'A Kategorisi', 'status' => 'active']);
        Category::create(['organization_id' => $organizationB->id, 'name' => 'B Kategorisi', 'status' => 'active']);

        $adminB = User::factory()->create(['organization_id' => $organizationB->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($adminB);

        $visible = Category::all();

        $this->assertCount(1, $visible);
        $this->assertSame('B Kategorisi', $visible->first()->name);
    }
}
