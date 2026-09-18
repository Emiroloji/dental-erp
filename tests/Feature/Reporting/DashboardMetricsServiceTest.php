<?php

namespace Tests\Feature\Reporting;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsServiceTest extends TestCase
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
        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Kompozit A',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'purchase_price' => 10,
            'status' => 'active',
        ]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_summary_reports_total_quantity_value_and_level_counts(): void
    {
        $this->actingAs($this->admin);

        app(StockMovementService::class)->in($this->product, $this->warehouse, 100, ['unit_cost' => 5], $this->admin);

        $summary = app(DashboardMetricsService::class)->summaryFor($this->organization->id);

        $this->assertSame(1, $summary['productCount']);
        $this->assertSame(100.0, $summary['totalStockQuantity']);
        $this->assertSame(500.0, $summary['totalStockValue']);
        $this->assertSame(1, $summary['levelCounts']['normal']);
        $this->assertSame(0, $summary['levelCounts']['critical']);
    }

    public function test_cancelled_movements_are_not_counted_as_usage_or_daily_totals(): void
    {
        $this->actingAs($this->admin);

        $service = app(StockMovementService::class);
        $service->in($this->product, $this->warehouse, 100, ['unit_cost' => 5], $this->admin);
        $mistakenIn = $service->in($this->product, $this->warehouse, 10, ['unit_cost' => 5], $this->admin);
        $service->out($this->product, $this->warehouse, 20, null, $this->admin, 'Klinik içi kullanım');
        $mistakenOut = $service->out($this->product, $this->warehouse, 5, null, $this->admin, 'Klinik içi kullanım')->sole();

        $service->cancel($mistakenIn, $this->admin);
        $service->cancel($mistakenOut, $this->admin);

        $summary = app(DashboardMetricsService::class)->summaryFor($this->organization->id);

        $this->assertSame(80.0, $summary['totalStockQuantity']);
        $this->assertSame(100.0, $summary['todayIn']);
        $this->assertSame(20.0, $summary['todayOut']);
        $this->assertSame(20.0, $summary['monthlyUsage']);
        $this->assertSame([['name' => 'Kompozit A', 'used' => 20.0]], $summary['topUsedProducts']);
    }

    public function test_the_summary_is_cached_and_survives_new_data_until_invalidated(): void
    {
        $this->actingAs($this->admin);

        $metrics = app(DashboardMetricsService::class);

        $first = $metrics->summaryFor($this->organization->id);
        $this->assertSame(0.0, $first['totalStockQuantity']);

        // Directly mutate the lot behind the cache's back (bypassing the service),
        // to isolate "is it actually cached" from "does invalidation work".
        StockLot::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'lot_no' => 'LOT-1',
            'unit_cost' => 1,
            'quantity' => 999,
        ]);

        $stillCached = $metrics->summaryFor($this->organization->id);
        $this->assertSame(0.0, $stillCached['totalStockQuantity'], "ikinci çağrı cache'ten gelmeli, veritabanını tekrar sorgulamamalı");

        $metrics->forget($this->organization->id);

        $afterForget = $metrics->summaryFor($this->organization->id);
        $this->assertSame(999.0, $afterForget['totalStockQuantity']);
    }

    public function test_a_stock_movement_invalidates_the_dashboard_cache_immediately(): void
    {
        $this->actingAs($this->admin);

        $metrics = app(DashboardMetricsService::class);

        $before = $metrics->summaryFor($this->organization->id);
        $this->assertSame(0.0, $before['totalStockQuantity']);

        // fazlar-adimlar.md Aşama 7 doğrulama kriteri: "stok hareketi yapıldığında
        // dashboard üzerindeki rakamın cache'e rağmen anında güncellendiği test
        // edilir" — StockMovementService kendi cache'i geçersiz kılıyor, biz burada
        // sadece o efekti gözlemliyoruz.
        app(StockMovementService::class)->in($this->product, $this->warehouse, 50, [], $this->admin);

        $after = $metrics->summaryFor($this->organization->id);
        $this->assertSame(50.0, $after['totalStockQuantity']);
    }

    public function test_dashboard_summaries_are_isolated_per_organization(): void
    {
        $this->actingAs($this->admin);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 50, [], $this->admin);

        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);

        $metrics = app(DashboardMetricsService::class);

        $otherSummary = $metrics->summaryFor($otherOrganization->id);

        $this->assertSame(0, $otherSummary['productCount']);
        $this->assertSame(0.0, $otherSummary['totalStockQuantity']);
    }
}
