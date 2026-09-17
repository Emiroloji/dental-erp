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
use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Domain\Stock\Services\StockAlertService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    private function criticalProduct(): Product
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Kritik Ürün',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ]);

        StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'lot_no' => 'LOT-1',
            'unit_cost' => 1,
            'quantity' => 2,
        ]);

        return $product;
    }

    public function test_admin_is_notified_when_a_product_drops_to_a_critical_level(): void
    {
        $this->criticalProduct();

        app(StockAlertService::class)->scan();

        $this->assertSame(1, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_staff_with_stock_read_permission_is_notified_but_staff_without_it_is_not(): void
    {
        $this->criticalProduct();

        $authorizedStaff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create([
            'user_id' => $authorizedStaff->id,
            'module' => Module::StockMovement->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);

        $unauthorizedStaff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        app(StockAlertService::class)->scan();

        $this->assertSame(1, $authorizedStaff->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $unauthorizedStaff->fresh()->unreadNotifications()->count());
    }

    public function test_a_healthy_stock_product_does_not_generate_any_notification(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Sağlıklı Ürün',
            'base_unit' => 'Adet',
            'min_stock' => 5,
            'status' => 'active',
        ]);

        StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'lot_no' => 'LOT-1',
            'unit_cost' => 1,
            'quantity' => 500,
        ]);

        $sent = app(StockAlertService::class)->scan();

        $this->assertSame(0, $sent);
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_scanning_twice_in_a_row_does_not_duplicate_the_alert(): void
    {
        $this->criticalProduct();

        app(StockAlertService::class)->scan();
        app(StockAlertService::class)->scan();

        $this->assertSame(1, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_once_the_alert_is_read_a_new_scan_at_the_same_level_creates_a_fresh_one(): void
    {
        $this->criticalProduct();

        app(StockAlertService::class)->scan();
        $this->admin->fresh()->unreadNotifications->markAsRead();

        app(StockAlertService::class)->scan();

        $this->assertSame(1, $this->admin->fresh()->unreadNotifications()->count());
        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    public function test_notifications_are_isolated_per_organization(): void
    {
        $this->criticalProduct();

        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        app(StockAlertService::class)->scan();

        $this->assertSame(0, $otherAdmin->fresh()->unreadNotifications()->count());
    }

    public function test_notification_data_carries_the_product_and_level_for_the_ui(): void
    {
        $product = $this->criticalProduct();

        app(StockAlertService::class)->scan();

        $notification = $this->admin->fresh()->unreadNotifications()->first();

        $this->assertSame(StockLevelAlert::class, $notification->type);
        $this->assertSame($product->id, $notification->data['product_id']);
        $this->assertSame('critical', $notification->data['level']);
        $this->assertNotEmpty($notification->data['reasons']);
    }
}
