<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Stock\Support\AlertMode;
use App\Models\User;
use App\Support\UnitConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_product_with_unit_conversion_rules(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $category = Category::create(['organization_id' => $organization->id, 'name' => 'Kompozitler', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Kompozit A')
            ->set('category_id', (string) $category->id)
            ->set('base_unit', 'Adet')
            ->set('purchase_price', '25.50')
            ->set('min_stock', '10')
            ->call('addConversionRule')
            ->set('conversionRules.0.unit', 'Kutu')
            ->set('conversionRules.0.factor', '50')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Kompozit A')->firstOrFail();

        $this->assertSame($organization->id, $product->organization_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Kutu', $product->conversion_rules[0]['unit']);
        $this->assertEquals(50, $product->conversion_rules[0]['factor']);

        $converter = new UnitConverter;
        $this->assertSame(150.0, $converter->toBaseUnit($product, 3, 'Kutu'));
    }

    public function test_admin_can_deactivate_a_product(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')->call('deactivate', $product->id);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'passive']);
    }

    public function test_search_filters_products_by_name_or_code(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        Product::create(['organization_id' => $organization->id, 'name' => 'Eldiven Lateks', 'code' => 'ELD-001', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['organization_id' => $organization->id, 'name' => 'Maske Cerrahi', 'code' => 'MSK-001', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('search', 'Eldiven')
            ->assertSee('Eldiven Lateks')
            ->assertDontSee('Maske Cerrahi');
    }

    public function test_products_are_isolated_per_organization(): void
    {
        $organizationA = Organization::create(['name' => 'A', 'status' => 'active', 'plan' => 'starter']);
        $organizationB = Organization::create(['name' => 'B', 'status' => 'active', 'plan' => 'starter']);

        Product::create(['organization_id' => $organizationA->id, 'name' => 'A Ürünü', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['organization_id' => $organizationB->id, 'name' => 'B Ürünü', 'base_unit' => 'Adet', 'status' => 'active']);

        $adminB = User::factory()->create(['organization_id' => $organizationB->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($adminB);

        $visible = Product::all();

        $this->assertCount(1, $visible);
        $this->assertSame('B Ürünü', $visible->first()->name);
    }

    public function test_staff_without_permission_cannot_view_products_page(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/urunler')->assertForbidden();
    }

    public function test_admin_can_set_a_product_specific_alert_threshold(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Anestezik')
            ->set('base_unit', 'Adet')
            ->set('alert_mode', AlertMode::Days->value)
            ->set('alert_expiry_low_days', '50')
            ->set('alert_expiry_critical_days', '30')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Anestezik')->firstOrFail();

        $this->assertSame(AlertMode::Days, $product->alert_mode);
        $this->assertSame(50, $product->alert_expiry_low_days);
        $this->assertSame(30, $product->alert_expiry_critical_days);
        $this->assertNull($product->alert_quantity_low);
    }

    public function test_alert_threshold_requires_the_red_value_to_be_stricter_than_the_yellow_one(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Eldiven')
            ->set('base_unit', 'Adet')
            ->set('alert_mode', AlertMode::Quantity->value)
            ->set('alert_quantity_low', '30')
            ->set('alert_quantity_critical', '60')
            ->call('save')
            ->assertHasErrors('alert_quantity_critical');

        $this->assertDatabaseMissing('products', ['name' => 'Eldiven']);
    }

    public function test_alert_mode_requires_both_thresholds(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Maske')
            ->set('base_unit', 'Adet')
            ->set('alert_mode', AlertMode::Quantity->value)
            ->call('save')
            ->assertHasErrors(['alert_quantity_low', 'alert_quantity_critical']);
    }

    public function test_both_mode_asks_for_the_thresholds_of_both_axes_and_stores_them(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Lokal Anestezik')
            ->set('base_unit', 'Adet')
            ->set('alert_mode', AlertMode::Both->value)
            ->call('save')
            ->assertHasErrors(['alert_quantity_low', 'alert_quantity_critical', 'alert_expiry_low_days', 'alert_expiry_critical_days'])
            ->set('alert_quantity_low', '60')
            ->set('alert_quantity_critical', '30')
            ->set('alert_expiry_low_days', '50')
            ->set('alert_expiry_critical_days', '30')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Lokal Anestezik')->firstOrFail();

        $this->assertSame(AlertMode::Both, $product->alert_mode);
        $this->assertSame(60.0, $product->alert_quantity_low);
        $this->assertSame(30.0, $product->alert_quantity_critical);
        $this->assertSame(50, $product->alert_expiry_low_days);
        $this->assertSame(30, $product->alert_expiry_critical_days);
        $this->assertStringContainsString('SKT', $product->alertRuleLabel());
        $this->assertStringContainsString('Adet altında sarı', $product->alertRuleLabel());
    }

    public function test_switching_a_product_back_to_the_default_clears_every_threshold(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sütur',
            'base_unit' => 'Adet',
            'status' => 'active',
            'alert_mode' => AlertMode::Both,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->call('edit', $product->id)
            ->assertSet('alert_mode', AlertMode::Both->value)
            ->set('alert_mode', '')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();

        $this->assertNull($product->alert_mode);
        $this->assertNull($product->alert_quantity_low);
        $this->assertNull($product->alert_expiry_low_days);
    }

    public function test_a_product_saved_without_an_alert_mode_keeps_the_default_thresholds(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')
            ->set('name', 'Gazlı Bez')
            ->set('base_unit', 'Adet')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Gazlı Bez')->firstOrFail();

        $this->assertNull($product->alert_mode);
        $this->assertFalse($product->hasCustomAlertRule());
        $this->assertStringContainsString('Varsayılan eşik', $product->alertRuleLabel());
    }
}
