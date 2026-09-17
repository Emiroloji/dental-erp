<?php

namespace Tests\Feature\Stock;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Product $product;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create(['organization_id' => $this->organization->id, 'name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_admin_can_record_a_stock_in_movement(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '50')
            ->set('unit_cost', '12.50')
            ->set('lot_no', 'LOT-1')
            ->set('expiry_date', '2027-01-01')
            ->set('reason', 'İlk stok girişi')
            ->call('save')
            ->assertHasNoErrors();

        $lot = StockLot::firstOrFail();
        $this->assertSame('LOT-1', $lot->lot_no);
        $this->assertEquals(50, $lot->quantity);

        $movement = StockMovement::firstOrFail();
        $this->assertSame($this->admin->id, $movement->actor_id);
        $this->assertSame('İlk stok girişi', $movement->reason);
    }

    public function test_stock_in_requires_a_positive_quantity(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '0')
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertSame(0, StockLot::count());
    }

    public function test_stock_in_returns_not_found_for_another_organizations_resources(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $otherOrganization->id, 'name' => 'Merkez', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $otherProduct = Product::create(['organization_id' => $otherOrganization->id, 'name' => 'Başka Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $otherProduct->id)
            ->set('warehouse_id', (string) $otherWarehouse->id)
            ->set('quantity', '10')
            ->call('save')
            ->assertNotFound();
    }

    public function test_staff_without_permission_cannot_view_stock_in_page(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/stok-girisleri')->assertForbidden();
    }

    public function test_staff_with_read_only_permission_cannot_save_stock_in(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create([
            'user_id' => $staff->id,
            'module' => Module::StockMovement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::OwnBranch->value,
        ]);

        $this->actingAs($staff);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '10')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, StockLot::count());
    }
}
