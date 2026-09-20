<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Notifications\ResetPasswordNotification;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 28 — şifremi unuttum akışı.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $status = 'active'): Organization
    {
        return Organization::create(['name' => 'Test Klinik', 'status' => $status, 'plan' => 'starter']);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization()->id,
            'email' => 'personel@test.com',
            'password' => bcrypt('eski-sifre'),
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ], $attributes));
    }

    public function test_active_user_receives_reset_link(): void
    {
        Notification::fake();

        $user = $this->user();

        Livewire::test('pages::access.forgot-password')
            ->set('email', 'personel@test.com')
            ->call('sendLink')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_unknown_email_reports_the_same_message_without_sending_a_link(): void
    {
        Notification::fake();

        Livewire::test('pages::access.forgot-password')
            ->set('email', 'olmayan@test.com')
            ->call('sendLink')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_passive_user_does_not_receive_a_reset_link(): void
    {
        Notification::fake();

        $this->user(['status' => 'passive']);

        Livewire::test('pages::access.forgot-password')
            ->set('email', 'personel@test.com')
            ->call('sendLink')
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_user_of_a_passive_organization_does_not_receive_a_reset_link(): void
    {
        Notification::fake();

        $this->user(['organization_id' => $this->organization('passive')->id]);

        Livewire::test('pages::access.forgot-password')
            ->set('email', 'personel@test.com')
            ->call('sendLink')
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_platform_owner_can_request_a_reset_link(): void
    {
        Notification::fake();

        $owner = User::factory()->create([
            'organization_id' => null,
            'email' => 'platform@test.com',
            'role' => User::ROLE_PLATFORM_OWNER,
            'status' => 'active',
        ]);

        Livewire::test('pages::access.forgot-password')
            ->set('email', 'platform@test.com')
            ->call('sendLink')
            ->assertSet('sent', true);

        Notification::assertSentTo($owner, ResetPasswordNotification::class);
    }

    public function test_valid_token_resets_the_password_and_clears_the_temporary_flag(): void
    {
        $user = $this->user(['must_change_password' => true]);
        $token = Password::createToken($user);

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'yeni-sifre-123')
            ->set('password_confirmation', 'yeni-sifre-123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(Hash::check('yeni-sifre-123', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Auth::attempt(['email' => 'personel@test.com', 'password' => 'yeni-sifre-123']));
    }

    public function test_token_can_only_be_used_once(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'yeni-sifre-123')
            ->set('password_confirmation', 'yeni-sifre-123')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'baska-sifre-123')
            ->set('password_confirmation', 'baska-sifre-123')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('yeni-sifre-123', $user->refresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'yeni-sifre-123')
            ->set('password_confirmation', 'yeni-sifre-123')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('eski-sifre', $user->refresh()->password));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = $this->user();
        Password::createToken($user);

        Livewire::test('pages::access.reset-password', ['token' => 'uydurma-token'])
            ->set('email', 'personel@test.com')
            ->set('password', 'yeni-sifre-123')
            ->set('password_confirmation', 'yeni-sifre-123')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('eski-sifre', $user->refresh()->password));
    }

    public function test_short_password_is_rejected(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'kisa')
            ->set('password_confirmation', 'kisa')
            ->call('save')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('eski-sifre', $user->refresh()->password));
    }

    public function test_passive_user_cannot_reset_even_with_a_valid_token(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $user->update(['status' => 'passive']);

        Livewire::test('pages::access.reset-password', ['token' => $token])
            ->set('email', 'personel@test.com')
            ->set('password', 'yeni-sifre-123')
            ->set('password_confirmation', 'yeni-sifre-123')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('eski-sifre', $user->refresh()->password));
    }

    public function test_reset_mail_contains_a_link_to_the_reset_screen(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $mail = (new ResetPasswordNotification($token))->toMail($user);

        $this->assertSame('Dental ERP — Şifre sıfırlama', $mail->subject);
        $this->assertStringContainsString(route('password.reset', ['token' => $token, 'email' => $user->email]), $mail->actionUrl);
    }

    public function test_guest_can_open_both_screens(): void
    {
        $user = $this->user();

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Şifremi unuttum');

        $this->get(route('password.reset', ['token' => Password::createToken($user), 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Yeni şifre belirleyin');
    }

    public function test_login_screen_links_to_the_forgot_password_screen(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'));
    }

    public function test_reset_screen_is_only_for_guests(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('password.request'))->assertRedirect();
    }
}
