<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockLevelService;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->warehouse->branch->organization_id,
            'name' => 'Kompozit A',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function lot(Product $product, float $quantity, ?string $expiryDate = null): StockLot
    {
        return StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'lot_no' => 'LOT-'.$product->id.'-'.$quantity,
            'expiry_date' => $expiryDate,
            'unit_cost' => 1,
            'quantity' => $quantity,
        ]);
    }

    public function test_stock_above_min_stock_and_thresholds_is_normal(): void
    {
        $product = $this->product(['min_stock' => 5]);
        $this->lot($product, 100);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Normal, $result['level']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_stock_at_or_below_min_stock_is_low(): void
    {
        $product = $this->product(['min_stock' => 20]);
        $this->lot($product, 15);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Low, $result['level']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_stock_at_or_below_the_fixed_low_threshold_is_low_even_without_min_stock(): void
    {
        $product = $this->product(['min_stock' => 0]);
        $this->lot($product, 10);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Low, $result['level']);
    }

    public function test_stock_at_or_below_the_critical_threshold_is_critical(): void
    {
        $product = $this->product(['min_stock' => 0]);
        $this->lot($product, 5);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
    }

    public function test_zero_stock_is_critical(): void
    {
        $product = $this->product();
        $this->lot($product, 0);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
        $this->assertSame(0.0, $result['quantity']);
    }

    public function test_a_lot_expiring_within_the_warning_window_makes_the_product_low_even_with_ample_stock(): void
    {
        $product = $this->product(['min_stock' => 0]);
        $this->lot($product, 500, now()->addDays(10)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Low, $result['level']);
        $this->assertStringContainsString('gün kaldı', implode(' ', $result['reasons']));
    }

    public function test_an_expired_lot_still_in_stock_makes_the_product_critical(): void
    {
        $product = $this->product(['min_stock' => 0]);
        $this->lot($product, 500, now()->subDay()->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
    }

    public function test_quantity_is_summed_across_all_lots_of_the_product(): void
    {
        // Neither lot alone crosses the critical threshold (5), but together
        // they add up to 7 — still low (<= the low threshold of 10), which is
        // only true if the two lots are actually summed rather than checked
        // individually.
        $product = $this->product(['min_stock' => 0]);
        $this->lot($product, 3);
        $this->lot($product, 4);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(7.0, $result['quantity']);
        $this->assertSame(StockLevel::Low, $result['level']);
    }
}
