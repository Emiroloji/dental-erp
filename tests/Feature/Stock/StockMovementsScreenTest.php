<?php

namespace Tests\Feature\Stock;

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

class StockMovementsScreenTest extends TestCase
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

    public function test_a_full_stock_in_then_partial_out_calculates_current_stock_correctly(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '50')
            ->set('lot_no', 'LOT-1')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '20')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(30, StockLot::firstOrFail()->quantity);
    }

    public function test_cancelling_an_in_movement_via_the_movements_page_drops_stock_back(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '40')
            ->set('lot_no', 'LOT-1')
            ->call('save')
            ->assertHasNoErrors();

        $lot = StockLot::firstOrFail();
        $this->assertEquals(40, $lot->quantity);

        $inMovement = StockMovement::where('type', 'in')->firstOrFail();

        Livewire::test('pages::stock.movements')
            ->call('cancel', $inMovement->id);

        $this->assertEquals(0, $lot->fresh()->quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_a_movement_cannot_be_cancelled_twice(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '40')
            ->set('lot_no', 'LOT-1')
            ->call('save');

        // Add surplus to the SAME lot so it has enough stock to survive a second
        // (wrongly-permitted) reversal — this is what makes the guard's effect
        // observable: without this surplus, the service's own negative-stock check
        // would mask a missing guard (as the original version of this test did).
        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '100')
            ->set('lot_no', 'LOT-1')
            ->call('save');

        $inMovement = StockMovement::where('type', 'in')->orderBy('id')->first();

        $component = Livewire::test('pages::stock.movements');
        $component->call('cancel', $inMovement->id);
        $component->call('cancel', $inMovement->id);

        $this->assertSame(3, StockMovement::count(), 'ikinci iptal denemesi yeni bir hareket oluşturmamalı (2 giriş + 1 iptal olmalı)');
    }

    public function test_movements_are_isolated_per_organization(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $otherOrganization->id, 'name' => 'Merkez', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $otherProduct = Product::create(['organization_id' => $otherOrganization->id, 'name' => 'Başka Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $otherProduct->id)
            ->set('warehouse_id', (string) $otherWarehouse->id)
            ->set('quantity', '10')
            ->call('save');

        $this->actingAs($this->admin);

        Livewire::test('pages::stock.movements')
            ->assertDontSee('Başka Ürün');
    }

    public function test_staff_without_permission_cannot_view_movements_page(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/stok-hareketleri')->assertForbidden();
    }
}
