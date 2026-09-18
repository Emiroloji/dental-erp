<?php

namespace Tests\Feature\Reporting;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reflects_a_stock_movement_immediately_despite_caching(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Kompozit A',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        // İlk yüklemede toplam stok 0 görünüp cache'e yazılır.
        Livewire::test('pages::dashboard')->assertSeeInOrder(['Toplam Stok Miktarı', '0']);

        app(StockMovementService::class)->in($product, $warehouse, 75, [], $admin);

        // Cache hâlâ geçerli süresinde olsa da, stok hareketi cache'i geçersiz
        // kıldığı için bir sonraki yüklemede rakam güncel gelmelidir.
        Livewire::test('pages::dashboard')->assertSee('75');
    }

    public function test_dashboard_shows_critical_level_count_from_stock_alerts(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Kritik Ürün',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        app(StockMovementService::class)->in($product, $warehouse, 2, [], $admin);

        $component = Livewire::test('pages::dashboard');

        $this->assertSame(1, $component->viewData('summary')['levelCounts']['critical']);
    }
}
