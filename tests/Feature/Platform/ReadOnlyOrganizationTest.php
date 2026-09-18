<?php

namespace Tests\Feature\Platform;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Organization\Exceptions\ReadOnlyOrganizationException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockAlertService;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Transfer\Models\TransferRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 18 doğrulaması (2/2): salt-okunur organizasyonda hiçbir yazma işlemi
 * yapılamaz — hangi ekran veya servis denerse denesin. Görüntüleme ve rapor
 * alma sürer; bildirimi okundu işaretlemek ve kendi şifresini değiştirmek serbesttir.
 */
class ReadOnlyOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private Warehouse $second;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->second = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active', 'password' => Hash::make('password')]);

        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Tedarikçi', 'status' => 'active']);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active', 'min_stock' => 500, 'supplier_id' => $this->supplier->id]);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 100, ['lot_no' => 'LOT-1'], $this->admin);
        app(StockAlertService::class)->scan($this->organization);

        $this->organization->update(['status' => 'read_only']);
        $this->admin->refresh();
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        return [
            'products' => Product::withoutGlobalScopes()->count(),
            'movements' => StockMovement::count(),
            'lot' => (int) StockLot::sum('quantity'),
            'transfers' => TransferRequest::withoutGlobalScopes()->count(),
            'orders' => PurchaseOrder::withoutGlobalScopes()->count(),
            'counts' => StockCount::withoutGlobalScopes()->count(),
            'returns' => SupplierReturn::withoutGlobalScopes()->count(),
            'users' => User::count(),
            'branches' => Branch::withoutGlobalScopes()->count(),
            'warehouses' => Warehouse::count(),
        ];
    }

    public function test_admin_can_still_log_in_view_and_report(): void
    {
        $this->app['auth']->logout();
        Livewire::test('pages::access.login')->set('email', $this->admin->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard'));

        $this->actingAs($this->admin);
        foreach (['/dashboard', '/urunler', '/stok-durumu', '/stok-hareketleri', '/raporlar', '/raporlar/hareketler', '/transferler', '/satin-alma', '/stok-sayimi', '/iadeler', '/denetim-kayitlari', '/paket'] as $url) {
            $this->get($url)->assertOk()->assertSee('salt-okunur', false);
        }

        // Yazma butonları gizli.
        $this->get('/urunler')->assertDontSee('Yeni Ürün');
        $this->get('/stok-girisleri')->assertOk()->assertDontSee('Yeni Giriş');
        $this->get('/stok-cikislari')->assertOk()->assertDontSee('Yeni Çıkış');
        $this->get('/transferler')->assertDontSee('Yeni Talep');
        $this->get('/iadeler')->assertDontSee('Yeni İade');
        $this->get('/stok-sayimi')->assertDontSee('Sayım Başlat');
    }

    public function test_every_write_screen_is_refused_and_nothing_changes(): void
    {
        $before = $this->snapshot();

        $attempts = [
            'ürün' => fn () => Livewire::test('pages::catalog.products')->call('openForm')->set('name', 'Yeni')->call('save'),
            'stok girişi' => fn () => Livewire::test('pages::stock.in')->call('openForm')->set('product_id', (string) $this->product->id)->set('warehouse_id', (string) $this->warehouse->id)->set('quantity', '5')->call('save'),
            'stok çıkışı' => fn () => Livewire::test('pages::stock.out')->call('openForm')->set('product_id', (string) $this->product->id)->set('warehouse_id', (string) $this->warehouse->id)->set('quantity', '5')->set('reasonCategory', 'clinical_use')->call('save'),
            'transfer' => fn () => Livewire::test('pages::transfer.index')->call('openForm')->set('product_id', (string) $this->product->id)->set('from_warehouse_id', (string) $this->warehouse->id)->set('to_warehouse_id', (string) $this->second->id)->set('quantity', '5')->call('save'),
            'satın alma' => fn () => Livewire::test('pages::purchasing.index')->call('create')->set('supplier_id', (string) $this->supplier->id)->set('warehouse_id', (string) $this->warehouse->id)->set('lines.0.product_id', (string) $this->product->id)->set('lines.0.quantity', '5')->call('save', true),
            'sayım' => fn () => Livewire::test('pages::inventory.index')->call('openStart')->set('warehouse_id', (string) $this->warehouse->id)->call('start'),
            'iade' => fn () => Livewire::test('pages::returns.index')->call('openForm')->set('warehouse_id', (string) $this->warehouse->id)->set('product_id', (string) $this->product->id)->set('lot_id', (string) StockLot::where('lot_no', 'LOT-1')->sole()->id)->set('quantity', '1')->set('reason', 'damaged')->call('save'),
            'personel' => fn () => Livewire::test('pages::access.staff')->call('openForm')->set('name', 'X')->set('email', 'x@x.test')->set('password', 'guclu-sifre-1')->set('branch_id', (string) $this->branch->id)->call('save'),
            'şube' => fn () => Livewire::test('pages::organization.branches')->call('create')->set('name', 'Yeni Şube')->call('save'),
            'depo' => fn () => Livewire::test('pages::organization.warehouses')->call('create')->set('branch_id', (string) $this->branch->id)->set('name', 'Yeni Depo')->call('save'),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
                // Ekran ilk adımda 403 verir (yetki kapısı) ya da koruyucu fırlatır;
                // hangisi olursa olsun sonuç aynı olmalı: hiçbir kayıt değişmez.
            }

            $this->assertSame($before, $this->snapshot(), "{$label}: salt-okunurda hiçbir şey değişmemeli");
        }

        // Kontrol grubu: aynı denemeler organizasyon aktifken gerçekten kayıt
        // oluşturur — yani yukarıdaki "değişmedi" sonucu testin kendi hatası değil.
        // (Admin kendi organizasyonunu aktife alamaz — bu da bir yazmadır; Platform Sahibi yapar.)
        $this->app['auth']->logout();
        $this->organization->update(['status' => 'active']);
        $this->actingAs($this->admin->fresh());

        foreach ($attempts as $label => $attempt) {
            $previous = $this->snapshot();
            $attempt()->assertHasNoErrors();
            $this->assertNotSame($previous, $this->snapshot(), "{$label}: aktif organizasyonda kayıt oluşmalıydı");
        }
    }

    public function test_services_are_refused_even_when_called_directly(): void
    {
        $before = $this->snapshot();

        foreach ([
            fn () => app(StockMovementService::class)->out($this->product, $this->warehouse, 1, null, $this->admin, 'x', StockOutReason::ClinicalUse),
            fn () => app(StockMovementService::class)->in($this->product, $this->warehouse, 1, [], $this->admin),
            fn () => app(StockCountService::class)->start($this->warehouse, $this->admin),
            fn () => $this->product->update(['name' => 'Değişti']),
            fn () => $this->product->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('salt-okunur organizasyonda yazma reddedilmeliydi');
            } catch (ReadOnlyOrganizationException|AuthorizationException) {
                // beklenen
            }
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Kompozit A', $this->product->fresh()->name);
    }

    public function test_marking_notifications_read_and_changing_own_password_are_allowed(): void
    {
        $notification = $this->admin->unreadNotifications()->firstOrFail();
        Livewire::test('pages::notifications.index')->call('markAsRead', $notification->id);
        $this->assertNotNull($notification->fresh()->read_at);

        Livewire::test('pages::access.change-password')
            ->set('current_password', 'password')->set('password', 'yeni-guclu-sifre')->set('password_confirmation', 'yeni-guclu-sifre')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('yeni-guclu-sifre', $this->admin->fresh()->password));

        // Ama kendi adını/rolünü değiştiremez.
        $this->expectException(ReadOnlyOrganizationException::class);
        $this->admin->update(['name' => 'Başka İsim']);
    }

    public function test_another_organization_is_not_affected(): void
    {
        // Salt-okunur organizasyonun Admin'i başka bir organizasyon bile açamaz; oturum kapatılır.
        $this->app['auth']->logout();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $other->id, 'name' => 'Şube', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $otherWarehouse, 10, [], $otherAdmin);

        $this->assertSame(10.0, (float) StockLot::where('warehouse_id', $otherWarehouse->id)->sum('quantity'));
    }
}
