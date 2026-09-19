<?php

namespace Tests\Feature\Forecasting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Forecasting\Services\ConsumptionForecastService;
use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Forecasting\Support\ForecastTrend;
use App\Domain\Forecasting\Support\ProductForecast;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 24 — stok tüketim tahmini. Doğrulama: bilinen bir kullanım geçmişinde
 * tahmin, elle hesaplanan Holt değerine; tükenme ve min. seviye tarihleri
 * bu hıza eşit çıkar. Kullanım dışı çıkışlar ve iptaller sayılmaz; şube
 * kapsamı ve organizasyon izolasyonu korunur.
 */
class ConsumptionForecastTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    private Organization $organization;

    private Branch $central;

    private Branch $north;

    private Warehouse $centralWarehouse;

    private Warehouse $northWarehouse;

    private User $admin;

    private Product $gloves;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = Carbon::parse('2026-09-18 12:00:00');
        $this->travelTo($this->today);

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $this->northWarehouse = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->stock = app(StockMovementService::class);
        $this->gloves = Product::create(['name' => 'Nitril Eldiven', 'base_unit' => 'Adet', 'status' => 'active', 'min_stock' => 20]);

        // Son dört haftada haftalık kullanım 10, 12, 14, 16 (artan).
        $this->at(30, fn () => $this->stock->in($this->gloves, $this->centralWarehouse, 100, ['lot_no' => 'E-1', 'expiry_date' => '2027-12-31'], $this->admin));
        foreach ([24 => 10, 17 => 12, 10 => 14, 3 => 16] as $daysAgo => $quantity) {
            $this->at($daysAgo, fn () => $this->stock->out($this->gloves, $this->centralWarehouse, $quantity, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse));
        }
    }

    private function at(int $daysAgo, callable $action): mixed
    {
        $this->travelTo($this->today->copy()->subDays($daysAgo));
        $result = $action();
        $this->travelTo($this->today);

        return $result;
    }

    private function forecastFor(Product $product, ?User $viewer = null, array $filters = []): ?ProductForecast
    {
        $viewer ??= $this->admin;

        return app(ConsumptionForecastService::class)
            ->forecast($this->organization->id, ReportFilters::fromArray($filters), $viewer->accessibleBranchIds(Module::Reports))
            ->first(fn (ProductForecast $forecast) => $forecast->product->is($product));
    }

    public function test_forecast_matches_hand_computed_holt_value_and_dates(): void
    {
        $forecast = $this->forecastFor($this->gloves);

        // Holt([10,12,14,16], α=0.5, β=0.3) = 18/hafta → 18/7 ≈ 2.5714/gün.
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 10.0, 12.0, 14.0, 16.0], $forecast->weeklyUsage);
        $this->assertEqualsWithDelta(18 / 7, $forecast->dailyRate, 0.0001);
        $this->assertEqualsWithDelta(48.0, $forecast->usableStock, 1e-9);
        $this->assertFalse($forecast->lowConfidence);
        $this->assertSame(ForecastTrend::Up, $forecast->trend);

        // 48 / 2.5714 = 18.67 gün → "Yakında"; tükenme 18 gün sonra.
        $this->assertEqualsWithDelta(18.7, $forecast->daysOfCover, 0.05);
        $this->assertSame(ForecastRisk::Soon, $forecast->risk);
        $this->assertSame('2026-10-06', $forecast->stockoutDate->toDateString());
        $this->assertEqualsWithDelta(77.14, $forecast->horizonUsage, 0.01);

        // Min. stok 20: (48 − 20) / 2.5714 = 10.9 → 10 gün sonra sipariş zamanı.
        $this->assertSame('2026-09-28', $forecast->minStockDate->toDateString());
    }

    public function test_non_usage_outs_and_cancelled_usage_are_ignored(): void
    {
        $this->at(2, fn () => $this->stock->out($this->gloves, $this->centralWarehouse, 5, null, $this->admin, 'Hasar', StockOutReason::Damaged));
        $cancelled = $this->at(1, fn () => $this->stock->out($this->gloves, $this->centralWarehouse, 7, null, $this->admin, 'Yanlış giriş', StockOutReason::ClinicalUse))->first();
        $this->stock->cancel($cancelled, $this->admin);

        $forecast = $this->forecastFor($this->gloves);

        $this->assertSame(16.0, $forecast->weeklyUsage[11]);
        $this->assertEqualsWithDelta(18 / 7, $forecast->dailyRate, 0.0001);
        // Hasarlı 5 adet stoktan düştü ama tüketim sayılmadı.
        $this->assertEqualsWithDelta(43.0, $forecast->usableStock, 1e-9);
    }

    public function test_expired_stock_is_not_counted_as_usable(): void
    {
        $this->at(20, fn () => $this->stock->in($this->gloves, $this->centralWarehouse, 30, ['lot_no' => 'ESKI', 'expiry_date' => '2026-09-10'], $this->admin));

        $forecast = $this->forecastFor($this->gloves);

        $this->assertEqualsWithDelta(48.0, $forecast->usableStock, 1e-9);
        $this->assertEqualsWithDelta(30.0, $forecast->expiredStock, 1e-9);
    }

    public function test_short_history_uses_simple_average_with_low_confidence(): void
    {
        $masks = Product::create(['name' => 'Maske', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->at(12, fn () => $this->stock->in($masks, $this->centralWarehouse, 50, ['lot_no' => 'M-1'], $this->admin));
        $this->at(9, fn () => $this->stock->out($masks, $this->centralWarehouse, 7, null, $this->admin, 'Kullanım', StockOutReason::Consumption));
        $this->at(2, fn () => $this->stock->out($masks, $this->centralWarehouse, 7, null, $this->admin, 'Kullanım', StockOutReason::Consumption));

        $forecast = $this->forecastFor($masks);

        // İki haftalık geçmiş (< 3): (7 + 7) / 14 gün = 1/gün.
        $this->assertTrue($forecast->lowConfidence);
        $this->assertEqualsWithDelta(1.0, $forecast->dailyRate, 1e-9);
        $this->assertEqualsWithDelta(36.0, $forecast->daysOfCover, 1e-9);
    }

    public function test_risk_classes(): void
    {
        $idle = Product::create(['name' => 'Yedek Frez', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->stock->in($idle, $this->centralWarehouse, 5, ['lot_no' => 'F-1'], $this->admin);
        $this->assertSame(ForecastRisk::NoUsage, $this->forecastFor($idle)->risk);
        $this->assertNull($this->forecastFor($idle)->daysOfCover);

        // Stoğun 40'ını tüket: 8 adet kalır → 8 / 2.57 ≈ 3.1 gün → Kritik.
        $this->at(0, fn () => $this->stock->out($this->gloves, $this->centralWarehouse, 40, null, $this->admin, 'Sayım dışı imha', StockOutReason::Expired));
        $this->assertSame(ForecastRisk::Critical, $this->forecastFor($this->gloves)->risk);

        $this->stock->out($this->gloves, $this->centralWarehouse, 8, null, $this->admin, 'İmha', StockOutReason::Expired);
        $this->assertSame(ForecastRisk::OutOfStock, $this->forecastFor($this->gloves)->risk);
    }

    public function test_branch_scope_limits_usage_and_stock(): void
    {
        // Kuzey'de aynı ürünün ayrı kullanımı.
        $this->at(5, fn () => $this->stock->in($this->gloves, $this->northWarehouse, 70, ['lot_no' => 'K-1'], $this->admin));
        $this->at(4, fn () => $this->stock->out($this->gloves, $this->northWarehouse, 21, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse));

        $northStaff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $northStaff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        $north = $this->forecastFor($this->gloves, $northStaff->fresh());
        $this->assertEqualsWithDelta(49.0, $north->usableStock, 1e-9);
        $this->assertSame(21.0, array_sum($north->weeklyUsage));

        // Kuzey personeli filtreyle Merkez şubesini seçse de veri gelmez.
        $this->assertNull($this->forecastFor($this->gloves, $northStaff->fresh(), ['branch_id' => $this->central->id]));

        // Admin tüm şubeleri toplar.
        $all = $this->forecastFor($this->gloves);
        $this->assertEqualsWithDelta(97.0, $all->usableStock, 1e-9);
        $this->assertSame(73.0, array_sum($all->weeklyUsage));
    }

    public function test_screen_lists_forecast_and_filters_by_risk(): void
    {
        $idle = Product::create(['name' => 'Yedek Frez', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->stock->in($idle, $this->centralWarehouse, 5, ['lot_no' => 'F-1'], $this->admin);

        $this->get('/raporlar/tahmin')->assertOk()->assertSee('Tüketim Tahmini')->assertSee('Nitril Eldiven')->assertSee('06.10.2026');

        Livewire::test('pages::reports.forecast')
            ->set('risk', ForecastRisk::NoUsage->value)
            ->assertSee('Yedek Frez')
            ->assertDontSee('Nitril Eldiven')
            ->set('risk', '')
            ->set('search', 'Nitril')
            ->assertSee('Nitril Eldiven')
            ->assertDontSee('Yedek Frez');
    }

    public function test_access_requires_reports_permission_and_other_organizations_are_isolated(): void
    {
        $stockOnly = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $stockOnly->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($stockOnly)->get('/raporlar/tahmin')->assertForbidden();

        $rival = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $rivalAdmin = User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($rivalAdmin)->get('/raporlar/tahmin')->assertOk()->assertDontSee('Nitril Eldiven');
        $this->assertTrue(app(ConsumptionForecastService::class)->forecast($rival->id, new ReportFilters, null)->isEmpty());
    }
}
