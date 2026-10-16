<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Notifications\StaffAccountCreated;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class StaffInviteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Gülüş Kliniği', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function create(string $email = 'ayse@klinik.test')
    {
        return Livewire::test('pages::access.staff')
            ->call('openForm')
            ->set('name', 'Ayşe')
            ->set('email', $email)
            ->set('branch_id', (string) $this->branch->id)
            ->call('save');
    }

    public function test_new_staff_gets_a_temporary_password_by_email(): void
    {
        Notification::fake();

        $this->create()->assertHasNoErrors()->assertSee('Geçici şifre ayse@klinik.test adresine gönderildi.');

        $staff = User::where('email', 'ayse@klinik.test')->sole();
        $this->assertTrue($staff->must_change_password);

        Notification::assertSentTo($staff, StaffAccountCreated::class, function (StaffAccountCreated $mail) use ($staff) {
            return $mail->organizationName === 'Gülüş Kliniği'
                && Hash::check($mail->temporaryPassword, $staff->password)
                && str_contains($mail->toMail($staff)->subject, 'hesabınız açıldı');
        });
    }

    public function test_staff_form_has_no_password_field(): void
    {
        Livewire::test('pages::access.staff')->call('openForm')
            ->assertDontSee('wire:model="password"', false)
            ->assertSee('Geçici şifre personelin e-postasına gönderilir');
    }

    public function test_resend_invite_replaces_the_password(): void
    {
        Notification::fake();
        $this->create();
        $staff = User::where('email', 'ayse@klinik.test')->sole();
        $staff->forceFill(['must_change_password' => false])->save();
        $oldHash = $staff->password;

        Livewire::test('pages::access.staff')->call('resendInvite', $staff->id)->assertSee('Yeni geçici şifre ayse@klinik.test adresine gönderildi.');

        $staff->refresh();
        $this->assertNotSame($oldHash, $staff->password);
        $this->assertTrue($staff->must_change_password);
        Notification::assertSentTo($staff, StaffAccountCreated::class, fn (StaffAccountCreated $mail) => $mail->isReset);
    }

    public function test_resend_invite_is_limited_to_own_active_staff(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        $foreign = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        Livewire::test('pages::access.staff')->call('resendInvite', $foreign->id)->assertNotFound();
        Livewire::test('pages::access.staff')->call('resendInvite', $this->admin->id)->assertNotFound();
    }

    public function test_mail_failure_keeps_the_staff_and_warns(): void
    {
        // Gerçek gönderim yolu: kapalı bir porta bağlanan SMTP aktarımı.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->create()->assertSee('Personel oluşturuldu ancak e-posta gönderilemedi.');

        $this->assertTrue(User::where('email', 'ayse@klinik.test')->exists());
    }
}
