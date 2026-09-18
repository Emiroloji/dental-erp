<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Notifications\OrganizationAccountCreated;
use App\Domain\Platform\Support\Plan;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 18 — Platform Yönetici Paneli (mimari.md Bölüm 9, proje.md Bölüm 2).
 * Doğrulama (1/2): Platform Sahibi organizasyonları yönetir ama hiçbir
 * klinik/stok verisine erişemez (kurallar.md Bölüm 4).
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $clinic;

    private User $clinicAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active', 'email' => 'platform@test.com', 'password' => Hash::make('password')]);

        $this->clinic = Organization::create(['name' => 'Gülümseme Diş', 'status' => 'active', 'plan' => 'professional']);
        $branch = Branch::create(['organization_id' => $this->clinic->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->clinicAdmin = User::factory()->create(['organization_id' => $this->clinic->id, 'branch_id' => $branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active', 'email' => 'admin@klinik.test', 'password' => Hash::make('password')]);

        $this->actingAs($this->clinicAdmin);
        $product = Product::create(['name' => 'Gizli Kompozit X', 'code' => 'GZL-01', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $warehouse, 77, ['lot_no' => 'GIZLI-LOT'], $this->clinicAdmin);
        Auth::logout();
    }

    public function test_platform_owner_logs_into_the_panel_and_is_kept_out_of_clinic_screens(): void
    {
        Livewire::test('pages::access.login')->set('email', 'platform@test.com')->set('password', 'password')->call('login')
            ->assertRedirect(route('platform.dashboard'));

        $this->actingAs($this->owner);
        $this->get('/platform')->assertOk()->assertSee('Genel Bakış');

        foreach (['/dashboard', '/urunler', '/stok-durumu', '/stok-hareketleri', '/raporlar', '/transferler', '/satin-alma', '/iadeler', '/denetim-kayitlari', '/paket'] as $url) {
            $this->get($url)->assertRedirect(route('platform.dashboard'));
        }

        // Tenant kapsamı varsayılan kapalı: Platform Sahibi oturumunda tenant modelleri boş döner.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Branch::count());
        $this->assertSame(0, Warehouse::whereHas('branch')->count());
        $this->assertSame(0, StockLot::whereHas('product')->count());
    }

    public function test_platform_screens_show_only_platform_level_data(): void
    {
        $this->actingAs($this->owner);

        foreach (['/platform', '/platform/organizasyonlar', "/platform/organizasyonlar/{$this->clinic->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('Gizli Kompozit X')->assertDontSee('GZL-01')->assertDontSee('GIZLI-LOT')->assertDontSee('Ana Depo');
        }

        $this->get('/platform/organizasyonlar')->assertSee('Gülümseme Diş')->assertSee('Profesyonel')->assertSee('1 / 5');
    }

    public function test_clinic_users_cannot_open_the_platform_panel(): void
    {
        $this->actingAs($this->clinicAdmin);

        $this->get('/platform')->assertForbidden();
        $this->get('/platform/organizasyonlar')->assertForbidden();
        $this->get("/platform/organizasyonlar/{$this->clinic->id}")->assertForbidden();
    }

    public function test_creating_an_organization_opens_branch_warehouse_and_an_admin_with_a_temporary_password(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);

        $screen = Livewire::test('pages::platform.organizations')->call('openForm')
            ->set('name', 'Beyaz Diş Kliniği')->set('contact_email', 'info@beyaz.test')->set('contact_phone', '0212 000 00 00')
            ->set('plan', Plan::Starter->value)
            ->set('admin_name', 'Ayşe Yılmaz')->set('admin_email', 'ayse@beyaz.test')
            ->call('save')->assertHasNoErrors();

        $organization = Organization::where('name', 'Beyaz Diş Kliniği')->sole();
        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertSame(Plan::Starter, $organization->plan);
        $this->assertSame(['Merkez Şube'], Branch::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('name')->all());
        $this->assertSame(1, Warehouse::whereIn('branch_id', Branch::withoutGlobalScopes()->where('organization_id', $organization->id)->select('id'))->where('is_default', true)->count());

        $admin = User::where('email', 'ayse@beyaz.test')->sole();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->must_change_password);
        $this->assertSame($organization->id, $admin->organization_id);

        $password = $screen->get('created')['password'];
        $screen->assertSee($password);
        $this->assertTrue(Hash::check($password, $admin->password));
        Notification::assertSentTo($admin, OrganizationAccountCreated::class, fn (OrganizationAccountCreated $notification) => $notification->temporaryPassword === $password);

        // Aynı e-posta ikinci kez kullanılamaz.
        Livewire::test('pages::platform.organizations')->call('openForm')
            ->set('name', 'Başka')->set('admin_name', 'X')->set('admin_email', 'ayse@beyaz.test')
            ->call('save')->assertHasErrors('admin_email');
    }

    public function test_the_new_admin_must_change_the_temporary_password_before_anything_else(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);
        $password = Livewire::test('pages::platform.organizations')->call('openForm')
            ->set('name', 'Beyaz Diş Kliniği')->set('admin_name', 'Ayşe')->set('admin_email', 'ayse@beyaz.test')
            ->call('save')->get('created')['password'];
        Auth::logout();

        Livewire::test('pages::access.login')->set('email', 'ayse@beyaz.test')->set('password', $password)->call('login')
            ->assertRedirect(route('password.change'));

        $admin = User::where('email', 'ayse@beyaz.test')->sole();
        $this->actingAs($admin);
        $this->get('/dashboard')->assertRedirect(route('password.change'));
        $this->get('/urunler')->assertRedirect(route('password.change'));

        Livewire::test('pages::access.change-password')
            ->set('current_password', $password)->set('password', $password)->set('password_confirmation', $password)
            ->call('save')->assertHasErrors('password');

        Livewire::test('pages::access.change-password')
            ->set('current_password', $password)->set('password', 'yeni-guclu-sifre')->set('password_confirmation', 'yeni-guclu-sifre')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('dashboard'));

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->actingAs($admin->fresh());
        $this->get('/dashboard')->assertOk();
    }

    public function test_plan_and_custom_limits_are_applied_with_an_overage_warning(): void
    {
        $this->actingAs($this->owner);
        foreach (['Kadıköy', 'Beşiktaş'] as $name) {
            Branch::create(['organization_id' => $this->clinic->id, 'name' => $name, 'status' => 'active']);
        }

        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])
            ->set('plan', Plan::Starter->value)
            ->assertSee('Limit aşımı uyarısı')
            ->assertSee('şube 3/1')
            ->call('savePlan');

        $this->assertSame(Plan::Starter, $this->clinic->fresh()->plan);
        $this->assertSame(3, Branch::withoutGlobalScopes()->where('organization_id', $this->clinic->id)->where('status', 'active')->count(), 'mevcut şubeler korunur');

        // Kurumsal paket, özel 50 GB depolama.
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])
            ->set('plan', Plan::Enterprise->value)->set('max_storage_mb', '51200')
            ->assertDontSee('Limit aşımı uyarısı')
            ->call('savePlan')->assertHasNoErrors();
        $this->assertSame(['branches' => null, 'users' => null, 'storage_mb' => 51200], $this->clinic->fresh()->limits());

        // Değişiklik organizasyonun denetim kaydına Platform Sahibi adıyla yazılır.
        $log = AuditLog::withoutGlobalScopes()->where('entity_type', Organization::class)->where('entity_id', $this->clinic->id)->latest('id')->first();
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($this->clinic->id, $log->organization_id);
        $this->assertSame('enterprise', $log->after['plan']);
    }

    public function test_platform_owner_can_reset_an_admin_password(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);

        $screen = Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('resetPassword', $this->clinicAdmin->id);
        $password = $screen->get('resetResult')['password'];

        $this->assertTrue(Hash::check($password, $this->clinicAdmin->fresh()->password));
        $this->assertTrue($this->clinicAdmin->fresh()->must_change_password);
        Notification::assertSentTo($this->clinicAdmin, OrganizationAccountCreated::class, fn ($notification) => $notification->isReset);
    }

    public function test_passive_organization_is_locked_out_even_with_an_open_session(): void
    {
        $this->actingAs($this->clinicAdmin);
        $this->get('/dashboard')->assertOk();

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('setStatus', 'passive');
        $this->assertSame(OrganizationStatus::Passive, $this->clinic->fresh()->status);

        $this->actingAs($this->clinicAdmin->fresh());
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        Livewire::test('pages::access.login')->set('email', 'admin@klinik.test')->set('password', 'password')->call('login')
            ->assertHasErrors('email');

        // Veri silinmez; yeniden aktifleştirilince erişim döner.
        $this->actingAs($this->owner);
        Livewire::test('pages::platform.organization', ['organization' => $this->clinic->id])->call('setStatus', 'active');
        $this->actingAs($this->clinicAdmin->fresh());
        $this->get('/stok-durumu')->assertOk()->assertSee('Gizli Kompozit X');
    }

    public function test_dashboard_statistics(): void
    {
        $this->actingAs($this->owner);
        Organization::create(['name' => 'Dolu Klinik', 'status' => 'read_only', 'plan' => 'starter', 'max_users' => 1]);
        $full = Organization::where('name', 'Dolu Klinik')->sole();
        User::factory()->create(['organization_id' => $full->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        Livewire::test('pages::platform.dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['organizations'] === 2
                && $summary['byStatus'] === ['active' => 1, 'read_only' => 1, 'passive' => 0]
                && $summary['byPlan'] === ['starter' => 1, 'professional' => 1, 'enterprise' => 0]
                && $summary['users'] === 2)
            ->assertViewHas('nearLimits', fn ($rows) => $rows->pluck('organization.name')->all() === ['Dolu Klinik'])
            ->assertSee('Dolu Klinik');
    }
}
