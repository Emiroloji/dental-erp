<?php

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlatformAccountException;
use App\Domain\Platform\Notifications\PlatformAccountCreated;
use App\Domain\Platform\Services\PlatformAccountService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Sahibi hesaplarının yönetimi (Aşama 23).
 */
class PlatformAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['organization_id' => null, 'name' => 'Kurucu', 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
    }

    public function test_owner_opens_a_new_platform_account_with_temporary_password(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);

        $this->get('/platform/hesaplar')->assertOk()->assertSee('Kurucu');

        $component = Livewire::test('pages::platform.accounts')
            ->call('openForm')
            ->set('name', 'Ortak')
            ->set('email', 'ortak@dental-erp.test')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Ortak hesabı açıldı.');

        $account = User::where('email', 'ortak@dental-erp.test')->sole();
        $this->assertTrue($account->isPlatformOwner());
        $this->assertNull($account->organization_id);
        $this->assertTrue($account->must_change_password);

        $password = $component->get('issued')['password'];
        $this->assertTrue(Hash::check($password, $account->password));
        Notification::assertSentTo($account, PlatformAccountCreated::class, fn ($notification) => $notification->temporaryPassword === $password);
    }

    public function test_last_active_platform_owner_and_own_account_cannot_be_deactivated(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::platform.accounts')->call('deactivate', $this->owner->id)->assertSee('Kendi hesabınızı pasife alamazsınız.');
        $this->assertTrue($this->owner->fresh()->isActive());

        $second = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
        Livewire::test('pages::platform.accounts')->call('deactivate', $second->id);
        $this->assertFalse($second->fresh()->isActive());

        // Eşzamanlılık: B, A'yı pasife aldıktan sonra A'nın (artık pasif) B'yi
        // pasife alma isteği reddedilir; platform sahipsiz kalmaz.
        $second->refresh()->forceFill(['status' => 'active'])->save();
        $ownerSessionLoadedEarlier = User::find($this->owner->id);
        app(PlatformAccountService::class)->deactivate($this->owner, $second);

        try {
            app(PlatformAccountService::class)->deactivate($second, $ownerSessionLoadedEarlier);
            $this->fail('Pasif hesap başkasını pasife alamamalıydı.');
        } catch (PlatformAccountException) {
        }

        $this->assertTrue($second->fresh()->isActive());
    }

    public function test_deactivated_platform_owner_session_is_closed(): void
    {
        $second = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);

        $this->actingAs($second)->get('/platform')->assertOk();

        $second->forceFill(['status' => 'passive'])->save();

        $this->get('/platform')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_reset_issues_new_temporary_password(): void
    {
        Notification::fake();
        $second = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active', 'must_change_password' => false]);

        $this->actingAs($this->owner);
        $component = Livewire::test('pages::platform.accounts')->call('resetPassword', $second->id);

        $second->refresh();
        $this->assertTrue($second->must_change_password);
        $this->assertTrue(Hash::check($component->get('issued')['password'], $second->password));
        Notification::assertSentTo($second, PlatformAccountCreated::class, fn ($notification) => $notification->isReset);
    }

    public function test_clinic_users_cannot_be_managed_and_clinic_users_cannot_open_the_screen(): void
    {
        $clinic = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $clinicAdmin = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.accounts')
            ->assertDontSee($clinicAdmin->email)
            ->call('deactivate', $clinicAdmin->id)
            ->assertNotFound();
        $this->assertTrue($clinicAdmin->fresh()->isActive());

        $this->actingAs($clinicAdmin)->get('/platform/hesaplar')->assertForbidden();
    }
}
