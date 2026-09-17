<?php

namespace Tests\Feature\Stock;

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
}
