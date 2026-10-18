<?php

namespace Tests\Feature\Patients;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 43 — ürün kartında "Hastaya kullanımda ÜTS'ye bildirilir" işareti.
 */
class ProductUsageReportFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    public function test_flag_is_saved_with_a_gtin(): void
    {
        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'Implant X')
            ->set('product_type', 'consumable')
            ->set('gtin', '08699999999994')
            ->set('uts_usage_report', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Product::sole()->uts_usage_report);
    }

    public function test_flag_requires_a_gtin(): void
    {
        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'Implant X')
            ->set('product_type', 'consumable')
            ->set('uts_usage_report', true)
            ->call('save')
            ->assertHasErrors(['uts_usage_report']);

        $this->assertSame(0, Product::count());
    }

    public function test_flag_is_loaded_when_editing(): void
    {
        $product = Product::create(['name' => 'Implant X', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994', 'uts_usage_report' => true]);

        Livewire::test('pages::catalog.products')
            ->call('edit', $product->id)
            ->assertSet('uts_usage_report', true);
    }
}
