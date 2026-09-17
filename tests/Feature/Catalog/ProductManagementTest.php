<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
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
}
