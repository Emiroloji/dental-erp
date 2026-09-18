<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * kurallar.md Bölüm 4: "Pasif şube/depo üzerinde hiçbir işlem yapılamaz."
 */
class InactiveLocationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Warehouse $warehouse;

    private Product $product;

    private User $admin;

    private StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Cerrahi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->product = Product::create(['name' => 'Kompozit', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->service = app(StockMovementService::class);
    }

    public function test_every_stock_operation_is_blocked_on_a_passive_warehouse(): void
    {
        $in = $this->service->in($this->product, $this->warehouse, 10, [], $this->admin);
        $lot = StockLot::sole();

        $this->warehouse->update(['status' => 'passive']);

        $operations = [
            'in' => fn () => $this->service->in($this->product, $this->warehouse->fresh(), 1, [], $this->admin),
            'out' => fn () => $this->service->out($this->product, $this->warehouse->fresh(), 1, null, $this->admin),
            'adjust' => fn () => $this->service->adjust($lot, 8, 'Sayım farkı', $this->admin),
            'cancel' => fn () => $this->service->cancel($in, $this->admin),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail("{$name} pasif depoda engellenmedi.");
            } catch (InactiveLocationException) {
                // beklenen
            }
        }

        $this->assertSame(10.0, (float) $lot->fresh()->quantity);
    }

    public function test_stock_operations_are_blocked_when_the_branch_is_passive(): void
    {
        $this->branch->update(['status' => 'passive']);

        $this->expectException(InactiveLocationException::class);

        $this->service->in($this->product, $this->warehouse->fresh(), 5, [], $this->admin);
    }

    public function test_stock_in_screen_rejects_a_passive_warehouse_with_a_message(): void
    {
        $this->warehouse->update(['status' => 'passive']);

        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '5')
            ->call('save')
            ->assertHasErrors('warehouse_id');

        $this->assertSame(0, StockLot::count());
    }

    public function test_passive_warehouses_are_not_offered_for_new_movements(): void
    {
        Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Kapalı Depo', 'is_default' => false, 'status' => 'passive']);

        Livewire::test('pages::stock.in')->call('openForm')->assertSee('Cerrahi Depo')->assertDontSee('Kapalı Depo');
        Livewire::test('pages::stock.out')->call('openForm')->assertSee('Cerrahi Depo')->assertDontSee('Kapalı Depo');
    }
}
