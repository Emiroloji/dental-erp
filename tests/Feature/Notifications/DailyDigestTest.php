<?php

namespace Tests\Feature\Notifications;

use App\Domain\Access\Notifications\DailyDigest;
use App\Domain\Access\Services\DailyDigestService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    private function notifyAt(User $user, string $at, string $kind = 'transfer', string $message = 'Transfer onaylandı.'): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        $user->notify(new WorkflowNotification($kind, 'Başlık', $message, '/x'));
        $this->travelBack();
    }

    private function sendAt(string $at): int
    {
        return app(DailyDigestService::class)->send(CarbonImmutable::parse($at));
    }

    public function test_unread_notifications_of_the_last_day_are_mailed_grouped(): void
    {
        $this->notifyAt($this->admin, '2026-10-15 10:00', 'finance', 'Kira ödemesi bugün.');
        $this->notifyAt($this->admin, '2026-10-15 12:00', 'cold_chain', 'Aralık dışı giriş.');
        $this->notifyAt($this->admin, '2026-10-15 13:00');
        $this->notifyAt($this->admin, '2026-10-14 03:00', 'transfer', 'Çok eski.');
        $this->admin->notifications()->where('data->message', 'Transfer onaylandı.')->update(['read_at' => now()]);

        Notification::fake();
        $this->assertSame(1, $this->sendAt('2026-10-16 04:30'));

        Notification::assertSentTo($this->admin, DailyDigest::class, function (DailyDigest $digest) {
            $mail = $digest->toMail($this->admin);

            return $digest->total === 2
                && array_keys($digest->groups) === ['Soğuk Zincir', 'Finans']
                && $mail->subject === 'Dental ERP — günlük özet: 2 yeni bildirim';
        });
        $this->assertNotNull($this->admin->fresh()->digest_sent_at);
    }

    public function test_same_notification_is_not_mailed_twice_and_empty_window_sends_nothing(): void
    {
        $this->notifyAt($this->admin, '2026-10-15 10:00');

        Notification::fake();
        $this->assertSame(1, $this->sendAt('2026-10-16 04:30'));
        $this->assertSame(0, $this->sendAt('2026-10-17 04:30'));
        Notification::assertSentToTimes($this->admin, DailyDigest::class, 1);
        $this->assertSame('2026-10-17 04:30:00', $this->admin->fresh()->digest_sent_at->toDateTimeString());
    }

    public function test_window_never_reaches_back_more_than_seven_days(): void
    {
        $this->admin->forceFill(['digest_sent_at' => '2026-09-01 04:30'])->save();
        $this->notifyAt($this->admin, '2026-09-20 10:00');

        Notification::fake();
        $this->assertSame(0, $this->sendAt('2026-10-16 04:30'));
    }

    public function test_opted_out_passive_users_passive_organizations_and_platform_owner_get_nothing(): void
    {
        $optedOut = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active', 'daily_digest' => false]);
        $passive = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'passive']);
        $closed = Organization::create(['name' => 'Kapalı', 'status' => 'passive', 'plan' => 'starter']);
        $closedAdmin = User::factory()->create(['organization_id' => $closed->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);

        foreach ([$optedOut, $passive, $closedAdmin, $owner] as $user) {
            $this->notifyAt($user, '2026-10-15 10:00');
        }

        Notification::fake();
        $this->assertSame(0, $this->sendAt('2026-10-16 04:30'));
        Notification::assertNothingSent();
    }

    public function test_long_groups_are_trimmed(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->notifyAt($this->admin, '2026-10-15 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'transfer', "Transfer {$i}");
        }

        Notification::fake();
        $this->sendAt('2026-10-16 04:30');

        Notification::assertSentTo($this->admin, DailyDigest::class, fn (DailyDigest $digest) => count($digest->groups['Transfer']['items']) === 10 && $digest->groups['Transfer']['more'] === 2);
    }

    public function test_command_is_scheduled_for_istanbul_morning(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'notifications:send-digest'));

        $this->assertNotNull($event);
        $this->assertSame('30 7 * * *', $event->expression);
        $this->assertSame('Europe/Istanbul', $event->timezone);
        $this->artisan('notifications:send-digest')->assertSuccessful();
    }

    public function test_user_can_toggle_their_own_digest(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::notifications.index')
            ->assertSee('Günlük özet e-postası açık')
            ->call('toggleDigest')
            ->assertSee('Günlük özet e-postası kapalı');

        $this->assertFalse($this->admin->fresh()->daily_digest);
    }
}
