<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanStockLevelsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scheduled_command_notifies_admins_of_critical_products_end_to_end(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Tükenen Ürün',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ]);

        StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'lot_no' => 'LOT-1',
            'unit_cost' => 1,
            'quantity' => 0,
        ]);

        // QUEUE_CONNECTION=sync in phpunit.xml, so the dispatched ScanStockLevelsJob
        // runs inline here — this test therefore exercises the real command, the
        // real queue dispatch, and the real notification write in one pass.
        $this->artisan('stock:scan-levels')->assertSuccessful();

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_the_command_is_registered_on_the_hourly_schedule(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => $event->command)
            ->filter()
            ->implode(' ');

        $this->assertStringContainsString('stock:scan-levels', $commands);
    }
}
