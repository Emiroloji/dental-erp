<?php

namespace Tests\Feature\Notifications;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockAlertService;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Transfer\Services\TransferService;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $central;

    private Branch $north;

    private Warehouse $centralWarehouse;

    private Warehouse $northWarehouse;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $this->northWarehouse = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Dental Tedarik', 'status' => 'active']);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active', 'min_stock' => 0]);
        app(StockMovementService::class)->in($this->product, $this->centralWarehouse, 100, ['lot_no' => 'LOT001', 'unit_cost' => 10], $this->admin);
    }

    /**
     * @param  array<int, Module>  $modules
     */
    private function staff(Branch $branch, array $modules, string $name = 'Personel'): User
    {
        $user = User::factory()->create(['name' => $name, 'organization_id' => $branch->organization_id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        foreach ($modules as $module) {
            Permission::create(['user_id' => $user->id, 'module' => $module->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => 'own_branch']);
        }

        return $user->fresh();
    }

    /**
     * Aynı saniyede yazılan bildirimlerin sırası garanti değil; başlıklar sıralı döner.
     *
     * @return array<int, string>
     */
    private function titlesFor(User $user): array
    {
        return $user->fresh()->notifications()->where('type', WorkflowNotification::class)->get()->pluck('data.title')->sort()->values()->all();
    }

    public function test_transfer_notifications_reach_the_side_that_must_act(): void
    {
        $requester = $this->staff($this->north, [Module::Transfer], 'Kuzey Talep');
        $northColleague = $this->staff($this->north, [Module::Transfer], 'Kuzey Diğer');
        $centralKeeper = $this->staff($this->central, [Module::Transfer], 'Merkez Depocu');
        $stockOnly = $this->staff($this->central, [Module::StockMovement], 'Merkez Stok');
        $transfers = app(TransferService::class);

        $transfer = $transfers->request($this->product, $this->centralWarehouse, $this->northWarehouse, 10, 'Kuzeyde bitti', $requester);

        // Yeni talep: onaylayacak kaynak (Merkez) taraf + Admin; talep eden ve hedef taraf değil.
        $this->assertSame(['Yeni transfer talebi'], $this->titlesFor($centralKeeper));
        $this->assertSame(['Yeni transfer talebi'], $this->titlesFor($this->admin));
        $this->assertSame([], $this->titlesFor($requester));
        $this->assertSame([], $this->titlesFor($northColleague));
        $this->assertSame([], $this->titlesFor($stockOnly), 'Transfer yetkisi olmayan bilgilendirilmez');

        $notification = $centralKeeper->notifications()->sole();
        $this->assertStringContainsString("Transfer #{$transfer->id} · Kompozit A · 10 Adet · Merkez Merkez Depo → Kuzey Kuzey Depo — Kuzeyde bitti", $notification->data['message']);
        $this->assertSame(route('transfers.index'), $notification->data['url']);
        $this->assertSame('transfer', $notification->data['kind']);

        $transfers->approve($transfer, $centralKeeper);
        $this->assertSame(['Transfer talebi onaylandı'], $this->titlesFor($requester));

        $transfers->prepare($transfer, $centralKeeper);
        $transfers->ship($transfer, $centralKeeper);
        // Gönderim: teslim alacak hedef taraf + talep eden.
        $this->assertSame(['Transfer gönderildi — teslim alın'], $this->titlesFor($northColleague));
        $this->assertSame(['Transfer gönderildi — teslim alın', 'Transfer talebi onaylandı'], $this->titlesFor($requester));

        $transfers->receive($transfer, $requester);
        $this->assertContains('Transfer teslim alındı', $this->titlesFor($centralKeeper));
    }

    public function test_purchase_approval_requests_go_to_admins_and_decisions_to_the_requester(): void
    {
        $buyer = $this->staff($this->central, [Module::Purchasing], 'Satınalmacı');
        $otherBuyer = $this->staff($this->central, [Module::Purchasing], 'Diğer Satınalmacı');
        $orders = app(PurchaseOrderService::class);

        $order = $orders->create($this->supplier, $this->centralWarehouse, [['product_id' => $this->product->id, 'quantity' => 50, 'unit_price' => 4]], null, null, $buyer);
        $this->assertSame([], $this->titlesFor($this->admin), 'taslak bildirim üretmez');

        $orders->submit($order, $buyer);
        $this->assertSame(['Satın alma talebi onay bekliyor'], $this->titlesFor($this->admin));
        $this->assertSame([], $this->titlesFor($otherBuyer));
        $this->assertStringContainsString("{$order->number()} · Dental Tedarik · 1 kalem · 200,00 ₺", $this->admin->notifications()->sole()->data['message']);

        $orders->reject($order, $this->admin, 'Bütçe yok');
        $this->assertSame(['Satın alma talebi reddedildi'], $this->titlesFor($buyer));
        $this->assertStringEndsWith('— Bütçe yok', $buyer->notifications()->sole()->data['message']);
        $this->assertSame(WorkflowNotification::LEVEL_WARN, $buyer->notifications()->sole()->data['level']);
    }

    public function test_a_submitted_count_notifies_admins_and_the_decision_notifies_the_counter(): void
    {
        $counter = $this->staff($this->central, [Module::StockMovement], 'Sayım Görevlisi');
        $secondAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $passiveAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'passive']);
        $counts = app(StockCountService::class);

        $count = $counts->start($this->centralWarehouse, $counter);
        $line = $count->lines()->sole();
        $counts->record($count, [$line->id => ['counted_quantity' => 97, 'reason' => 'loss']], $counter);
        $counts->submit($count, $counter);

        $this->assertSame(['Sayım tamamlandı — onay bekliyor'], $this->titlesFor($this->admin));
        $this->assertSame(['Sayım tamamlandı — onay bekliyor'], $this->titlesFor($secondAdmin));
        $this->assertSame([], $this->titlesFor($passiveAdmin), 'pasif kullanıcı bildirim almaz');
        $this->assertSame(route('inventory.show', $count), $this->admin->notifications()->sole()->data['url']);
        $this->assertStringContainsString('1 farklı satır', $this->admin->notifications()->sole()->data['message']);

        $counts->sendBack($count, $this->admin, 'Rafı tekrar say');
        $counts->submit($count, $counter);
        $counts->approve($count, $secondAdmin);

        $this->assertSame(['Sayım onaylandı', 'Sayım yeniden sayıma gönderildi'], $this->titlesFor($counter));
        // Onaylayan Admin kendi işlemi için bildirim almaz.
        $this->assertSame(['Sayım tamamlandı — onay bekliyor', 'Sayım tamamlandı — onay bekliyor'], $this->titlesFor($secondAdmin));
    }

    public function test_return_requests_notify_admins_and_decisions_notify_the_requester(): void
    {
        $clerk = $this->staff($this->central, [Module::StockMovement], 'Depo Görevlisi');
        $returns = app(ReturnService::class);

        $return = $returns->request(StockLot::sole(), 5, ReturnReason::Damaged, $this->supplier, $clerk);
        $this->assertSame(['İade talebi onay bekliyor'], $this->titlesFor($this->admin));
        $this->assertSame(route('returns.show', $return), $this->admin->notifications()->sole()->data['url']);

        $returns->approve($return, $this->admin);
        $returns->ship($return, $clerk);
        $returns->supplierApprove($return, $clerk, 'Kabul');
        $this->assertSame(['İade talebi onaylandı — kargoya verilebilir'], $this->titlesFor($clerk), 'kendi işlemi (tedarikçi onayı girişi) bildirim üretmez');
    }

    public function test_notifications_never_cross_organizations(): void
    {
        $foreignOrg = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $foreignAdmin = User::factory()->create(['organization_id' => $foreignOrg->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $clerk = $this->staff($this->central, [Module::StockMovement]);

        app(ReturnService::class)->request(StockLot::sole(), 5, ReturnReason::Damaged, $this->supplier, $clerk);

        $this->assertSame(0, $foreignAdmin->notifications()->count());
    }

    public function test_notifications_screen_filters_by_kind_and_opens_the_related_page(): void
    {
        $clerk = $this->staff($this->central, [Module::StockMovement]);
        $return = app(ReturnService::class)->request(StockLot::sole(), 5, ReturnReason::Damaged, $this->supplier, $clerk);

        // Bir de stok uyarısı olsun.
        $this->product->update(['min_stock' => 200]);
        app(StockAlertService::class)->scan($this->organization);

        $this->actingAs($this->admin);
        $notification = $this->admin->notifications()->where('type', WorkflowNotification::class)->sole();

        Livewire::test('pages::notifications.index')
            ->assertSee('İade talebi onay bekliyor')
            ->assertSee($return->number().' · Kompozit A · 5 Adet · Dental Tedarik')
            ->assertSee('Kompozit A')
            ->set('kindFilter', 'return')
            ->assertSee('İade talebi onay bekliyor')
            ->assertDontSee('stok seviyesi')
            ->set('kindFilter', 'stock')
            ->assertSee('stok seviyesi')
            ->assertDontSee('İade talebi onay bekliyor')
            ->set('kindFilter', '')
            ->call('open', $notification->id)
            ->assertRedirect(route('returns.show', $return));

        $this->assertNotNull($notification->fresh()->read_at);

        $second = app(ReturnService::class)->request(StockLot::sole(), 1, ReturnReason::Damaged, $this->supplier, $clerk);
        $bellNotification = $this->admin->unreadNotifications()->where('type', WorkflowNotification::class)->sole();
        Livewire::test('notification-bell')->call('toggle')->assertSee($second->number())
            ->call('markAsRead', $bellNotification->id)
            ->assertRedirect(route('returns.show', $second));
    }
}
