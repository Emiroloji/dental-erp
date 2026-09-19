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

/**
 * Klasik Stok Girişi ve Stok Çıkışı formlarında birim seçimi (kurallar.md
 * Bölüm 3): alternatif birimle girilen miktar ana birime çevrilerek kaydedilir.
 */
class UnitSelectionScreenTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Eldiven',
            'base_unit' => 'Adet',
            'conversion_rules' => [['unit' => 'Kutu', 'factor' => 50]],
            'status' => 'active',
        ]);

        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    public function test_stock_in_converts_boxes_to_base_unit(): void
    {
        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->assertSet('unit', 'Adet')
            ->assertSee('(= 50 Adet)')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '3')
            ->set('unit', 'Kutu')
            ->set('reason', 'İrsaliye 12')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(150, StockLot::sole()->quantity);
        $this->assertSame('İrsaliye 12 (3 Kutu)', StockMovement::sole()->reason);
    }

    public function test_stock_out_converts_boxes_to_base_unit(): void
    {
        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '120')
            ->call('save');

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '2')
            ->set('unit', 'Kutu')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(20, StockLot::sole()->quantity);
        $this->assertSame('Klinik içi kullanım (2 Kutu)', StockMovement::where('type', 'out')->sole()->reason);
    }

    public function test_unknown_unit_is_rejected(): void
    {
        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '1')
            ->set('unit', 'Koli')
            ->call('save')
            ->assertHasErrors(['unit']);

        $this->assertSame(0, StockLot::count());
    }

    public function test_stock_out_over_available_in_boxes_is_rejected(): void
    {
        Livewire::test('pages::stock.in')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '60')
            ->call('save');

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '2')
            ->set('unit', 'Kutu')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertEquals(60, StockLot::sole()->quantity);
    }
}
