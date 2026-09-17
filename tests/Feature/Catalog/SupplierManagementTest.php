<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_supplier(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.suppliers')
            ->set('name', 'Medikal Tedarik A.Ş.')
            ->set('contact_person', 'Ayşe Yılmaz')
            ->set('phone', '05001112233')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $organization->id,
            'name' => 'Medikal Tedarik A.Ş.',
            'contact_person' => 'Ayşe Yılmaz',
        ]);
    }

    public function test_admin_can_deactivate_a_supplier(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'name' => 'Tedarikçi', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.suppliers')->call('deactivate', $supplier->id);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'status' => 'passive']);
    }

    public function test_staff_without_permission_cannot_view_suppliers_page(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/tedarikciler')->assertForbidden();
    }
}
