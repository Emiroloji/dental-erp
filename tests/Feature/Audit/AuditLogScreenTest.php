<?php

namespace Tests\Feature\Audit;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_admin_sees_before_and_after_values_of_an_edited_product(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']);
        $product->update(['name' => 'Kompozit B']);

        $this->get('/denetim-kayitlari')
            ->assertOk()
            ->assertSee('Denetim Kayıtları')
            ->assertSee('Kompozit A')
            ->assertSee('Kompozit B')
            ->assertSee('Güncellendi');
    }

    public function test_array_values_are_shown_readably_instead_of_raw_json(): void
    {
        $this->assertSame('Kompozit A / LOT001: 100', AuditLog::formatValue(['Kompozit A / LOT001' => 100]));
        $this->assertSame('unit: Kutu, factor: 50; unit: Paket, factor: 10', AuditLog::formatValue([['unit' => 'Kutu', 'factor' => 50], ['unit' => 'Paket', 'factor' => 10]]));
        $this->assertSame('Ayşe, Mehmet', AuditLog::formatValue(['Ayşe', 'Mehmet']));
        $this->assertSame('—', AuditLog::formatValue([]));
    }

    public function test_staff_cannot_view_audit_logs_even_with_all_module_permissions(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        foreach (Module::cases() as $module) {
            Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'all']);
        }

        $this->actingAs($staff)->get('/denetim-kayitlari')->assertForbidden();
    }

    public function test_entity_type_filter_narrows_the_list(): void
    {
        $this->actingAs($this->admin);

        Product::create(['name' => 'Eldiven Lateks', 'base_unit' => 'Adet', 'status' => 'active']);
        Category::create(['name' => 'Sarf Malzemeleri', 'status' => 'active']);

        Livewire::test('pages::audit.index')
            ->set('entityFilter', Category::class)
            ->assertSee('Sarf Malzemeleri')
            ->assertDontSee('Eldiven Lateks');
    }

    public function test_other_organization_logs_are_never_shown(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        Product::create(['name' => 'Gizli Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($this->admin)
            ->get('/denetim-kayitlari')
            ->assertOk()
            ->assertDontSee('Gizli Ürün');
    }
}
