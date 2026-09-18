<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 9 — MVP 1 Uçtan Uca Kabul Testi.
 *
 * Beş madde tek bir zincir halinde, gerçek ekranlar (Livewire bileşenleri ve
 * HTTP route'ları) üzerinden yürütülür; her adım bir öncekinin bıraktığı veriyle
 * devam eder. Servislere doğrudan yalnızca ikinci organizasyonun verisini
 * hazırlarken dokunulur.
 */
class Mvp1AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Warehouse $warehouse;

    private Product $product;

    public function test_mvp1_end_to_end_acceptance(): void
    {
        $this->step1_organization_is_seeded_and_admin_logs_in();
        $this->step2_admin_adds_staff_with_limited_permission();
        $this->step3_stock_in_and_out_report_current_stock_and_usage_separately();
        $this->step4_dashboard_numbers_and_critical_stock_alerts();
        $this->step5_second_organization_data_never_leaks();
    }

    /** Madde 1: Bir organizasyon (seeder ile) açılır, Admin giriş yapar. */
    private function step1_organization_is_seeded_and_admin_logs_in(): void
    {
        $this->seed();

        Livewire::test('pages::access.login')
            ->set('email', 'admin@dental-erp.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $this->admin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->warehouse = Warehouse::where('is_default', true)->sole();

        $this->assertTrue($this->admin->isAdmin());
        $this->get('/dashboard')->assertOk()->assertSee('Kontrol Paneli');
    }

    /** Madde 2: Admin bir personel ekler ve ona sınırlı yetki (yalnızca Stok Giriş/Çıkış) tanımlar. */
    private function step2_admin_adds_staff_with_limited_permission(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::access.staff')
            ->call('openForm')
            ->set('name', 'Depo Görevlisi')
            ->set('email', 'depo@dental-erp.test')
            ->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $this->warehouse->branch_id)
            ->set('modules.stock_movement.read', true)
            ->set('modules.stock_movement.write', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->staff = User::where('email', 'depo@dental-erp.test')->sole();
        $this->assertCount(1, $this->staff->permissions);

        $this->app['auth']->forgetGuards();

        Livewire::test('pages::access.login')
            ->set('email', 'depo@dental-erp.test')
            ->set('password', 'guclu-sifre-1')
            ->call('login')
            ->assertHasNoErrors();

        $this->actingAs($this->staff->fresh());

        foreach (['/stok-durumu', '/stok-girisleri', '/stok-cikislari', '/stok-hareketleri'] as $allowed) {
            $this->get($allowed)->assertOk();
        }

        foreach (['/urunler', '/kategoriler', '/tedarikciler', '/personel', '/raporlar', '/denetim-kayitlari'] as $forbidden) {
            $this->get($forbidden)->assertForbidden();
        }

        // Yetkisi olmayan bir aksiyonu doğrudan çağırmak da engellenir.
        Livewire::test('pages::catalog.products')
            ->set('name', 'İzinsiz Ürün')
            ->call('save')
            ->assertForbidden();
    }

    /**
     * Madde 3: Ürün girilir, stok girişi ve çıkışı yapılır; mevcut stok ve
     * kullanım miktarı doğru ve ayrı raporlanır (proje.md Bölüm 7: 100 girilip
     * 20 kullanıldığında mevcut stok 80, toplam kullanım 20).
     */
    private function step3_stock_in_and_out_report_current_stock_and_usage_separately(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::catalog.categories')
            ->call('openForm')
            ->set('name', 'Sarf Malzemeleri')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::catalog.products')
            ->call('openForm')
            ->set('name', 'Lateks Eldiven')
            ->set('code', 'ELD-001')
            ->set('base_unit', 'Adet')
            ->set('purchase_price', '2.50')
            ->set('min_stock', '30')
            ->call('save')
            ->assertHasNoErrors();

        $this->product = Product::where('code', 'ELD-001')->sole();

        // Stok girişini sınırlı yetkili personel yapar — verilen yetki gerçekten işe yarıyor.
        $this->actingAs($this->staff->fresh());

        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '100')
            ->set('unit_cost', '2.50')
            ->set('lot_no', 'LOT-A')
            ->set('expiry_date', now()->addYear()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->stockOut('20');

        // Yanlışlıkla girilen bir çıkış iptal edilir: stok geri gelir, kullanıma sayılmaz.
        $this->stockOut('5');
        $mistake = StockMovement::where('type', StockMovementType::Out->value)->latest('id')->firstOrFail();

        Livewire::test('pages::stock.movements')
            ->call('cancel', $mistake->id)
            ->assertHasNoErrors();

        $this->actingAs($this->admin);

        Livewire::test('pages::stock.status')->assertSee('Lateks Eldiven')->assertSee('80');

        Livewire::test('pages::reports.stock')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('product.id', $this->product->id)['quantity'] === 80.0);

        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', function (array $summary) {
                return $summary['totalStockQuantity'] === 80.0
                    && $summary['monthlyUsage'] === 20.0
                    && $summary['topUsedProducts'][0]['name'] === 'Lateks Eldiven'
                    && $summary['topUsedProducts'][0]['used'] === 20.0;
            });
    }

    /**
     * Madde 4: Dashboard doğru rakamları gösterir (cache'e rağmen), kritik stok
     * uyarısı doğru tetiklenir ve Admin + yetkili personele bildirim düşer.
     */
    private function step4_dashboard_numbers_and_critical_stock_alerts(): void
    {
        $this->actingAs($this->admin);

        // Dashboard önce bir kez açılır; özet cache'e yazılır.
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['levelCounts'] === ['normal' => 1, 'low' => 0, 'critical' => 0]);

        // 80 -> 25: minimum stok (30) altına iner -> Sarı.
        $this->actingAs($this->staff->fresh());
        $this->stockOut('55');
        $this->actingAs($this->admin);

        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 25.0
                && $summary['levelCounts'] === ['normal' => 0, 'low' => 1, 'critical' => 0]);

        $this->artisan('stock:scan-levels')->assertSuccessful();

        $this->assertAlert($this->admin, 'low');
        $this->assertAlert($this->staff, 'low');

        // 25 -> 3: kritik eşiğin (5) altına iner -> Kırmızı, seviye kötüleştiği için yeni bildirim.
        $this->actingAs($this->staff->fresh());
        $this->stockOut('22');
        $this->actingAs($this->admin);

        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['levelCounts'] === ['normal' => 0, 'low' => 0, 'critical' => 1]);

        $this->artisan('stock:scan-levels')->assertSuccessful();

        $this->assertAlert($this->admin, 'critical');
        $this->assertAlert($this->staff, 'critical');

        $this->get('/bildirimler')->assertOk()->assertSee('Lateks Eldiven');
    }

    /** Madde 5: İkinci bir organizasyonun verisi, ilk organizasyonun hiçbir ekranında görünmez. */
    private function step5_second_organization_data_never_leaks(): void
    {
        $rival = Organization::create(['name' => 'Rakip Klinik', 'status' => 'active', 'plan' => 'starter']);
        $rivalBranch = Branch::create(['organization_id' => $rival->id, 'name' => 'Rakip Şube', 'status' => 'active']);
        $rivalWarehouse = Warehouse::create(['branch_id' => $rivalBranch->id, 'name' => 'Rakip Depo', 'is_default' => true, 'status' => 'active']);
        $rivalAdmin = User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active', 'name' => 'Rakip Admin']);
        User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_STAFF, 'status' => 'active', 'name' => 'Rakip Personel']);

        $this->actingAs($rivalAdmin);
        $rivalProduct = Product::create(['name' => 'Rakip Kompozit', 'code' => 'RKP-001', 'base_unit' => 'Adet', 'min_stock' => 50, 'status' => 'active']);
        app(StockMovementService::class)->in($rivalProduct, $rivalWarehouse, 40, ['lot_no' => 'RAKIP-LOT', 'unit_cost' => 9], $rivalAdmin, 'Rakip giriş');
        app(StockMovementService::class)->out($rivalProduct, $rivalWarehouse, 10, null, $rivalAdmin, 'Rakip çıkış');
        $this->artisan('stock:scan-levels')->assertSuccessful();

        $this->actingAs($this->admin);

        $screens = [
            '/dashboard', '/urunler', '/kategoriler', '/tedarikciler', '/personel',
            '/stok-durumu', '/stok-girisleri', '/stok-cikislari', '/stok-hareketleri',
            '/bildirimler', '/raporlar', '/denetim-kayitlari',
        ];

        foreach ($screens as $screen) {
            $response = $this->get($screen)->assertOk();

            foreach (['Rakip Kompozit', 'RKP-001', 'RAKIP-LOT', 'Rakip Şube', 'Rakip Depo', 'Rakip Admin', 'Rakip Personel'] as $leak) {
                $response->assertDontSee($leak);
            }
        }

        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['productCount'] === 1
                && $summary['totalStockQuantity'] === 3.0
                && $summary['monthlyUsage'] === 97.0
                && $summary['staffCount'] === 1
                && $summary['branchCount'] === 1);

        // Başka organizasyonun ürününe/deposuna yazma denemesi de reddedilir.
        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $rivalProduct->id)
            ->set('warehouse_id', (string) $rivalWarehouse->id)
            ->set('quantity', '1')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertNotFound();

        $this->assertSame(30.0, (float) StockLot::where('product_id', $rivalProduct->id)->sum('quantity'));
    }

    private function stockOut(string $quantity): void
    {
        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', $quantity)
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasNoErrors();
    }

    private function assertAlert(User $recipient, string $level): void
    {
        $this->assertTrue(
            $recipient->fresh()->unreadNotifications()
                ->where('type', StockLevelAlert::class)
                ->where('data->product_id', $this->product->id)
                ->where('data->level', $level)
                ->exists(),
            "{$recipient->name} kullanıcısına '{$level}' seviyesinde stok uyarısı düşmedi.",
        );
    }
}
