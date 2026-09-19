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
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockOutScreenTest extends TestCase
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

        app(StockMovementService::class)->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT-1']);
    }

    public function test_admin_can_record_a_stock_out_movement_using_fefo_when_no_lot_selected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '20')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(30, StockLot::firstOrFail()->quantity);

        $movement = StockMovement::where('type', 'out')->firstOrFail();
        $this->assertEquals(-20, $movement->quantity);
        $this->assertSame('Klinik içi kullanım', $movement->reason);
    }

    public function test_stock_out_form_does_not_accept_return_to_supplier_reason(): void
    {
        // Tedarikçiye iade yalnızca İadeler akışından yapılır (iade kaydı ve durum takibi atlanmasın).
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '5')
            ->set('reasonCategory', 'return_to_supplier')
            ->call('save')
            ->assertHasErrors(['reasonCategory']);

        $this->assertEquals(50, StockLot::firstOrFail()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'out')->count());
    }

    public function test_stock_out_rejects_insufficient_stock_and_shows_an_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '999')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertEquals(50, StockLot::firstOrFail()->quantity);
    }

    public function test_staff_without_permission_cannot_view_stock_out_page(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/stok-cikislari')->assertForbidden();
    }

    public function test_stock_out_rejects_mismatched_product_and_lot(): void
    {
        $this->actingAs($this->admin);

        // Create a second product with stock
        $product2 = Product::create(['organization_id' => $this->organization->id, 'name' => 'Kompozit B', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product2, $this->warehouse, 30, ['lot_no' => 'LOT-2']);

        // Get the lot from the first product
        $lot1 = StockLot::where('product_id', $this->product->id)->firstOrFail();

        // Try to submit with product_id of product2 but lot_id of product1's lot
        // This simulates a stale/manipulated lot_id selection
        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $product2->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('lot_id', (string) $lot1->id)
            ->set('quantity', '10')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasErrors(['lot_id']);

        // Both products should have original quantities
        $this->assertEquals(50, StockLot::where('product_id', $this->product->id)->firstOrFail()->quantity);
        $this->assertEquals(30, StockLot::where('product_id', $product2->id)->firstOrFail()->quantity);
    }

    public function test_stock_out_returns_not_found_for_another_organizations_resources(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $otherOrganization->id, 'name' => 'Merkez', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $otherProduct = Product::create(['organization_id' => $otherOrganization->id, 'name' => 'Başka Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $otherProduct->id)
            ->set('warehouse_id', (string) $otherWarehouse->id)
            ->set('quantity', '5')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertNotFound();
    }

    public function test_staff_with_read_only_permission_cannot_save_stock_out(): void
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

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '10')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertForbidden();

        $this->assertEquals(50, StockLot::firstOrFail()->quantity);
    }
}
