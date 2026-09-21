<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockLevelService;
use App\Domain\Stock\Support\AlertMode;
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

    /*
     * Aşama 29.1 — ürün bazlı uyarı eşiği. Eşik girilmemiş üründe yukarıdaki
     * sabit varsayılanlar geçerli kalır; eşik girilen ürün kendi kuralıyla
     * değerlendirilir. Her iki modda da "stok tükendi" ve "SKT geçmiş lot"
     * kuralı mutlaktır.
     */

    public function test_a_product_with_its_own_quantity_thresholds_uses_them_instead_of_the_defaults(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Quantity,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
        ]);
        $this->lot($product, 45);

        $result = app(StockLevelService::class)->assess($product);

        // 45 adet varsayılan eşiklerde (10/5) Normal olurdu; ürünün kendi
        // eşiğinde (60/30) Sarı.
        $this->assertSame(StockLevel::Low, $result['level']);
    }

    public function test_a_product_with_its_own_quantity_thresholds_goes_critical_at_its_own_red_threshold(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Quantity,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
        ]);
        $this->lot($product, 28);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
    }

    public function test_a_product_with_its_own_quantity_thresholds_is_normal_above_the_yellow_threshold(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Quantity,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
        ]);
        $this->lot($product, 120);

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Normal, $result['level']);
    }

    public function test_day_based_product_turns_yellow_at_its_own_expiry_day_threshold(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        // 40 gün kalmış lot: varsayılan 30 günlük pencerede Normal olurdu.
        $this->lot($product, 500, now()->addDays(40)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Low, $result['level']);
        $this->assertStringContainsString('gün kaldı', implode(' ', $result['reasons']));
    }

    public function test_day_based_product_turns_red_at_its_own_critical_day_threshold(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        $this->lot($product, 500, now()->addDays(20)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
    }

    public function test_day_based_product_ignores_the_fixed_quantity_defaults(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        // 3 adet: miktar bazlı varsayılanda (5) Kritik olurdu; gün bazlı üründe
        // SKT uzak olduğu için Normal.
        $this->lot($product, 3, now()->addDays(400)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Normal, $result['level']);
    }

    public function test_day_based_product_still_respects_its_own_min_stock(): void
    {
        $product = $this->product([
            'min_stock' => 10,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        $this->lot($product, 8, now()->addDays(400)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Low, $result['level']);
    }

    public function test_depleted_stock_and_expired_lots_stay_critical_in_day_based_mode(): void
    {
        $depleted = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        $this->lot($depleted, 0, now()->addDays(400)->toDateString());

        $expired = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Days,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        $this->lot($expired, 500, now()->subDay()->toDateString());

        $levels = app(StockLevelService::class);

        $this->assertSame(StockLevel::Critical, $levels->assess($depleted)['level']);
        $this->assertSame(StockLevel::Critical, $levels->assess($expired)['level']);
    }

    public function test_both_mode_warns_on_whichever_axis_reaches_its_threshold_first(): void
    {
        $attributes = [
            'min_stock' => 0,
            'alert_mode' => AlertMode::Both,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ];

        // Miktar bol, SKT yaklaşıyor: SKT ekseni sarıya düşürür.
        $byExpiry = $this->product($attributes);
        $this->lot($byExpiry, 500, now()->addDays(40)->toDateString());

        // SKT uzak, miktar azalmış: miktar ekseni kırmızıya düşürür.
        $byQuantity = $this->product($attributes);
        $this->lot($byQuantity, 25, now()->addDays(400)->toDateString());

        // İkisi de rahat: Normal.
        $healthy = $this->product($attributes);
        $this->lot($healthy, 500, now()->addDays(400)->toDateString());

        $levels = app(StockLevelService::class);

        $this->assertSame(StockLevel::Low, $levels->assess($byExpiry)['level']);
        $this->assertSame(StockLevel::Critical, $levels->assess($byQuantity)['level']);
        $this->assertSame(StockLevel::Normal, $levels->assess($healthy)['level']);
    }

    public function test_both_mode_takes_the_worse_of_the_two_axes(): void
    {
        $product = $this->product([
            'min_stock' => 0,
            'alert_mode' => AlertMode::Both,
            'alert_quantity_low' => 60,
            'alert_quantity_critical' => 30,
            'alert_expiry_low_days' => 50,
            'alert_expiry_critical_days' => 30,
        ]);
        // Miktar sarı bölgede (45), SKT kırmızı bölgede (20 gün): sonuç kırmızı.
        $this->lot($product, 45, now()->addDays(20)->toDateString());

        $result = app(StockLevelService::class)->assess($product);

        $this->assertSame(StockLevel::Critical, $result['level']);
    }
}
