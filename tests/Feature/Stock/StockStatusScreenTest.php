<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockStatusScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_lots_scoped_to_their_organization(): void
    {
        $organizationA = Organization::create(['name' => 'A', 'status' => 'active', 'plan' => 'starter']);
        $branchA = Branch::create(['organization_id' => $organizationA->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouseA = Warehouse::create(['branch_id' => $branchA->id, 'name' => 'Depo A', 'is_default' => true, 'status' => 'active']);
        $productA = Product::create(['organization_id' => $organizationA->id, 'name' => 'Ürün A', 'base_unit' => 'Adet', 'status' => 'active']);
        StockLot::create(['product_id' => $productA->id, 'warehouse_id' => $warehouseA->id, 'lot_no' => 'LOT-A', 'quantity' => 40]);

        $organizationB = Organization::create(['name' => 'B', 'status' => 'active', 'plan' => 'starter']);
        $branchB = Branch::create(['organization_id' => $organizationB->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouseB = Warehouse::create(['branch_id' => $branchB->id, 'name' => 'Depo B', 'is_default' => true, 'status' => 'active']);
        $productB = Product::create(['organization_id' => $organizationB->id, 'name' => 'Ürün B', 'base_unit' => 'Adet', 'status' => 'active']);
        StockLot::create(['product_id' => $productB->id, 'warehouse_id' => $warehouseB->id, 'lot_no' => 'LOT-B', 'quantity' => 15]);

        $adminA = User::factory()->create(['organization_id' => $organizationA->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($adminA);

        Livewire::test('pages::stock.status')
            ->assertSee('LOT-A')
            ->assertDontSee('LOT-B');
    }

    public function test_can_filter_lots_by_warehouse(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouseOne = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $warehouseTwo = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']);
        StockLot::create(['product_id' => $product->id, 'warehouse_id' => $warehouseOne->id, 'lot_no' => 'LOT-1', 'quantity' => 10]);
        StockLot::create(['product_id' => $product->id, 'warehouse_id' => $warehouseTwo->id, 'lot_no' => 'LOT-2', 'quantity' => 20]);

        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($admin);

        Livewire::test('pages::stock.status')
            ->set('warehouseFilter', (string) $warehouseTwo->id)
            ->assertSee('LOT-2')
            ->assertDontSee('LOT-1');
    }

    public function test_staff_without_permission_cannot_view_stock_status_page(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $staff = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/stok-durumu')->assertForbidden();
    }
}
