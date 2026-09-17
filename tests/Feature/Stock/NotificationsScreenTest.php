<?php

namespace Tests\Feature\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockAlertService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Kritik Ürün',
            'base_unit' => 'Adet',
            'min_stock' => 0,
            'status' => 'active',
        ]);

        StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'lot_no' => 'LOT-1',
            'unit_cost' => 1,
            'quantity' => 2,
        ]);

        app(StockAlertService::class)->scan();
    }

    public function test_notifications_page_requires_authentication(): void
    {
        $this->get('/bildirimler')->assertRedirect('/login');
    }

    public function test_admin_sees_their_own_notification_on_the_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::notifications.index')
            ->assertSee('Kritik Ürün')
            ->assertSee('Kritik stok seviyesi');
    }

    public function test_marking_a_notification_as_read_clears_the_unread_badge(): void
    {
        $this->actingAs($this->admin);

        $notification = $this->admin->unreadNotifications()->firstOrFail();

        Livewire::test('pages::notifications.index')
            ->call('markAsRead', $notification->id)
            ->assertDontSee('Tümünü okundu işaretle');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_every_unread_notification(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::notifications.index')->call('markAllAsRead');

        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $otherAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $notification = $this->admin->unreadNotifications()->firstOrFail();

        $this->actingAs($otherAdmin);

        Livewire::test('pages::notifications.index')
            ->call('markAsRead', $notification->id)
            ->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_notification_bell_shows_the_unread_notification_once_opened(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('notification-bell')
            ->call('toggle')
            ->assertSee('Kritik Ürün')
            ->assertSee('Kritik stok seviyesi');
    }

    public function test_notification_bell_can_mark_a_notification_as_read(): void
    {
        $this->actingAs($this->admin);

        $notification = $this->admin->unreadNotifications()->firstOrFail();

        Livewire::test('notification-bell')->call('markAsRead', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }
}
