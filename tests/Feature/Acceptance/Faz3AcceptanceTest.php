<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Notifications\OrganizationAccountCreated;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 22 — Faz 3 Uçtan Uca Kabul Testi. Faz 3'ün tüm parçaları tek bir
 * zincirde, kurulum dahil ekranlardan (Livewire + HTTP) yürütülür:
 * onboarding → ilk girişte şifre → paket limiti → paket talebi ve manuel onay
 * → barkod/QR ile hızlı işlem → salt-okunur ve pasif mod — ve Platform Sahibi
 * hiçbir adımda klinik verisini görmez.
 */
class Faz3AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Organization $clinic;

    private string $password = 'klinik-sifresi-2026';

    public function test_faz3_end_to_end_acceptance(): void
    {
        $this->seed();
        Notification::fake();

        $this->step1_platform_owner_opens_a_new_organization();
        $this->step2_admin_changes_the_temporary_password_on_first_login();
        $this->step3_starter_limit_blocks_a_second_branch();
        $this->step4_package_request_is_approved_by_hand_and_limits_grow();
        $this->step5_barcode_and_lot_label_book_stock_through_the_single_engine();
        $this->step6_platform_sees_only_platform_level_data();
        $this->step7_read_only_mode_allows_viewing_but_no_writes();
        $this->step8_passive_mode_locks_everyone_out_without_losing_data();
        $this->step9_organizations_stay_isolated();
    }

    private function step1_platform_owner_opens_a_new_organization(): void
    {
        Livewire::test('pages::access.login')->set('email', 'platform@dental-erp.test')->set('password', 'password')->call('login')
            ->assertRedirect(route('platform.dashboard'));
        $this->owner = User::where('email', 'platform@dental-erp.test')->sole();
        $this->actingAs($this->owner);

        $screen = Livewire::test('pages::platform.organizations')->call('openForm')
            ->set('name', 'Beyaz Diş Kliniği')->set('contact_email', 'info@beyaz.test')
            ->set('plan', Plan::Starter->value)
            ->set('admin_name', 'Ayşe Yılmaz')->set('admin_email', 'ayse@beyaz.test')
            ->call('save')->assertHasNoErrors();

        $this->clinic = Organization::where('name', 'Beyaz Diş Kliniği')->sole();
        $this->admin = User::where('email', 'ayse@beyaz.test')->sole();
        $temporary = $screen->get('created')['password'];
        Notification::assertSentTo($this->admin, OrganizationAccountCreated::class, fn ($notification) => $notification->temporaryPassword === $temporary);

        // Geçici şifreyle giriş → önce şifre değişimi.
        Auth::logout();
        Livewire::test('pages::access.login')->set('email', 'ayse@beyaz.test')->set('password', $temporary)->call('login')
            ->assertRedirect(route('password.change'));
        $this->actingAs($this->admin->fresh());
        $this->get('/dashboard')->assertRedirect(route('password.change'));

        $this->temporary = $temporary;
    }

    private string $temporary = '';

    private function step2_admin_changes_the_temporary_password_on_first_login(): void
    {
        Livewire::test('pages::access.change-password')
            ->set('current_password', $this->temporary)->set('password', $this->password)->set('password_confirmation', $this->password)
            ->call('save')->assertHasNoErrors()->assertRedirect(route('dashboard'));

        $this->admin = $this->admin->fresh();
        $this->assertFalse($this->admin->must_change_password);
        $this->actingAs($this->admin);
        $this->get('/dashboard')->assertOk();
        $this->get('/paket')->assertOk()->assertSee('Başlangıç');
    }

    private function step3_starter_limit_blocks_a_second_branch(): void
    {
        Livewire::test('pages::organization.branches')->call('create')->set('name', 'Kadıköy Şubesi')->call('save')
            ->assertHasErrors('name')->assertSee('Paket limitine ulaşıldı: 1/1 aktif şube (Başlangıç paketi)');
        $this->assertSame(1, Branch::count());
    }

    private function step4_package_request_is_approved_by_hand_and_limits_grow(): void
    {
        Livewire::test('pages::organization.subscription')
            ->set('requested_plan', Plan::Professional->value)->set('note', 'Kadıköy şubesini açıyoruz')
            ->call('submitRequest')->assertHasNoErrors()->assertSee('onay bekliyor');
        $request = PlanChangeRequest::sole();
        $this->assertSame(Plan::Starter, $this->clinic->fresh()->plan, 'talep ödeme tetiklemez, paketi değiştirmez');

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.plan-requests')->assertSee('Beyaz Diş Kliniği')->assertSee('yükseltme')
            ->set("notes.{$request->id}", 'Havale 18.09 alındı')->call('approve', $request->id);
        $this->assertSame(PlanChangeStatus::Approved, $request->fresh()->status);
        $this->assertSame(Plan::Professional, $this->clinic->fresh()->plan);

        $this->actingAs($this->admin);
        // (Notification::fake etkin: uygulama içi bildirim de gönderim olarak doğrulanır.)
        Notification::assertSentTo($this->admin, WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->title === 'Paket talebiniz onaylandı'
            && str_contains($notification->message, 'Havale 18.09 alındı'));
        Livewire::test('pages::organization.branches')->call('create')->set('name', 'Kadıköy Şubesi')->call('save')->assertHasNoErrors();
        $this->assertSame(2, Branch::count());
        $this->get('/paket')->assertOk()->assertSee('Profesyonel')->assertSee('2 / 5');
    }

    private function step5_barcode_and_lot_label_book_stock_through_the_single_engine(): void
    {
        $warehouse = Warehouse::whereHas('branch', fn ($query) => $query->where('name', 'Merkez Şube'))->where('is_default', true)->sole();

        Livewire::test('pages::catalog.products')->call('openForm')
            ->set('name', 'Beyaz Kompozit')->set('code', 'BK-01')->set('barcode', '8691234567890')->set('base_unit', 'Adet')->set('purchase_price', '30')
            ->set('conversionRules', [['unit' => 'Kutu', 'factor' => '50']])
            ->call('save')->assertHasNoErrors();
        $product = Product::sole();
        $this->assertSame([['unit' => 'Kutu', 'factor' => 50.0]], array_map(fn ($rule) => ['unit' => $rule['unit'], 'factor' => (float) $rule['factor']], $product->conversion_rules));

        // Barkodla giriş: 2 Kutu = 100 Adet.
        Livewire::test('pages::stock.quick')->set('warehouse_id', (string) $warehouse->id)->set('mode', 'in')
            ->set('scanCode', '8691234567890')->call('scan')->assertSee('Beyaz Kompozit')
            ->set('quantity', '2')->set('unit', 'Kutu')->set('lot_no', 'BK-LOT-1')->set('expiry_date', now()->addYear()->toDateString())
            ->call('submit')->assertHasNoErrors();
        $lot = StockLot::sole();
        $this->assertSame(100.0, (float) $lot->quantity);

        // Lot etiketi basılır, okutulur ve o lottan 10 adet çıkış yapılır.
        $this->get("/etiket/lot/{$lot->id}?adet=2")->assertOk()->assertSee("DERP:L:{$lot->id}")->assertSee('Lot BK-LOT-1');
        Livewire::test('pages::stock.quick')->set('mode', 'out')
            ->set('scanCode', "DERP:L:{$lot->id}")->call('scan')->assertSet('lot_id', (string) $lot->id)
            ->set('quantity', '10')->set('reasonCategory', 'clinical_use')
            ->call('submit')->assertHasNoErrors();
        $this->assertSame(90.0, (float) $lot->fresh()->quantity);

        // Tek giriş noktası ve diğer ekranlarla tutarlılık.
        $this->assertSame(90.0, (float) StockMovement::whereHas('lot.product')->sum('quantity'));
        Livewire::test('pages::dashboard')->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 90.0 && $summary['monthlyUsage'] === 10.0);
        Livewire::test('pages::reports.movements')->set(['from' => '', 'to' => ''])->assertViewHas('totals', fn (array $totals) => $totals['net'] === 90.0);
    }

    private function step6_platform_sees_only_platform_level_data(): void
    {
        $this->actingAs($this->owner);

        foreach (['/platform', '/platform/organizasyonlar', "/platform/organizasyonlar/{$this->clinic->id}", '/platform/paket-talepleri?statusFilter='] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Beyaz Kompozit')->assertDontSee('BK-LOT-1')->assertDontSee('8691234567890');
        }

        $this->get('/platform/organizasyonlar')->assertSee('Beyaz Diş Kliniği')->assertSee('Profesyonel')->assertSee('2 / 5');
        $this->get('/stok-durumu')->assertRedirect(route('platform.dashboard'));
        $this->get('/etiket/lot/'.StockLot::sole()->id)->assertRedirect(route('platform.dashboard'));
    }

    private function step7_read_only_mode_allows_viewing_but_no_writes(): void
    {
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('setStatus', OrganizationStatus::ReadOnly->value);
        $this->assertSame(OrganizationStatus::ReadOnly, $this->clinic->fresh()->status);

        $this->actingAs($this->admin->fresh());
        $this->get('/stok-durumu')->assertOk()->assertSee('salt-okunur')->assertSee('BK-LOT-1');
        $this->get('/raporlar/hareketler')->assertOk();

        $movements = StockMovement::count();
        try {
            Livewire::test('pages::stock.quick')->set('mode', 'out')->set('scanCode', '8691234567890')->call('scan')->set('quantity', '5')->call('submit');
        } catch (\Throwable) {
        }
        try {
            Livewire::test('pages::organization.subscription')->set('requested_plan', Plan::Enterprise->value)->call('submitRequest');
        } catch (\Throwable) {
        }

        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(90.0, (float) StockLot::sole()->quantity);
        $this->assertSame(1, PlanChangeRequest::withoutGlobalScopes()->count());
    }

    private function step8_passive_mode_locks_everyone_out_without_losing_data(): void
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('setStatus', OrganizationStatus::Passive->value);

        $this->actingAs($this->admin->fresh());
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        Livewire::test('pages::access.login')->set('email', 'ayse@beyaz.test')->set('password', $this->password)->call('login')->assertHasErrors('email');

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('setStatus', OrganizationStatus::Active->value);

        Auth::logout();
        Livewire::test('pages::access.login')->set('email', 'ayse@beyaz.test')->set('password', $this->password)->call('login')->assertRedirect(route('dashboard'));
        $this->actingAs($this->admin->fresh());
        $this->get('/stok-durumu')->assertOk()->assertSee('BK-LOT-1');
        $this->assertSame(90.0, (float) StockLot::sole()->quantity);
    }

    private function step9_organizations_stay_isolated(): void
    {
        $seededAdmin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->actingAs($seededAdmin);

        Livewire::test('pages::stock.quick')->set('scanCode', '8691234567890')->call('scan')->assertSet('productId', null)->assertDontSee('Beyaz Kompozit');
        Livewire::test('pages::stock.quick')->set('scanCode', 'DERP:L:'.StockLot::withoutGlobalScopes()->sole()->id)->call('scan')->assertSet('productId', null);
        $this->get('/paket')->assertOk()->assertDontSee('Havale 18.09 alındı');
        foreach (['/stok-durumu', '/stok-hareketleri', '/raporlar/hareketler', '/urunler', '/subeler'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Beyaz Kompozit')->assertDontSee('BK-LOT-1')->assertDontSee('Kadıköy Şubesi');
        }
        $this->get('/etiket/urun/'.Product::withoutGlobalScopes()->where('name', 'Beyaz Kompozit')->sole()->id)->assertNotFound();
    }
}
