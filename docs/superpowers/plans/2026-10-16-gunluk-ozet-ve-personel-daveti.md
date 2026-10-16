# Günlük Özet ve Personel Daveti (Aşama 39) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Her sabah kullanıcıya okunmamış bildirimlerinin gruplu e-posta özetini göndermek ve yeni personele geçici şifreli "hesabınız açıldı" e-postası göndermek.

**Architecture:** Bildirim türü etiketleri `App\Support\Notifications\NotificationKind`'da toplanır (Bildirimler sayfası ve e-posta ortak kullanır). `DailyDigestService` kullanıcının penceredeki okunmamış bildirimlerinden `DailyDigest` mail bildirimi üretir; `notifications:send-digest` 07:30 (İstanbul) çalışır. `StaffService::createStaff` geçici şifre üretir ve `StaffAccountCreated` gönderir (`OrganizationAccountCreated` deseni).

**Tech Stack:** Laravel 12 Notifications (mail + database), Livewire 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-16-gunluk-ozet-ve-personel-daveti-design.md`

## Global Constraints

- Metinler Türkçe; kod adları İngilizce.
- Özet zamanı `07:30`, saat dilimi `Europe/Istanbul` (uygulama saat dilimi UTC kalır).
- Pencere: `(digest_sent_at ?? now−24s, now]`, en fazla 7 gün geriye; yalnızca okunmamış bildirimler.
- Geçici şifre `Str::password(12, symbols: false)`; `must_change_password = true`.
- `StaffAccountCreated` senkron (kuyruğa girmez); `DailyDigest` `ShouldQueue`.
- Commit: `feat|test|docs: ... — Aşama 39 (n/4)` + `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: NotificationKind ve Bildirimler sayfası

**Files:**
- Create: `app/Support/Notifications/NotificationKind.php`
- Modify: `resources/views/pages/notifications/⚡index.blade.php` (KINDS → NotificationKind::LABELS; tür süzgeci)
- Test: `tests/Unit/Support/NotificationKindTest.php` (DB gerektirdiği için `Tests\TestCase` kullanır)

**Interfaces:**
- Produces: `NotificationKind::LABELS` (array<string,string>), `NotificationKind::label(string $kind): string`, `NotificationKind::of(DatabaseNotification): string`, `NotificationKind::summary(DatabaseNotification): string`, `NotificationKind::ORDER` grup sırası.

- [ ] **Step 1: Test**

`tests/Unit/Support/NotificationKindTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Support\Notifications\NotificationKind;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationKindTest extends TestCase
{
    private function notification(string $type, array $data): DatabaseNotification
    {
        return new DatabaseNotification(['type' => $type, 'data' => $data]);
    }

    public function test_kind_is_resolved_from_type_or_data(): void
    {
        $this->assertSame('stock', NotificationKind::of($this->notification(StockLevelAlert::class, ['product_name' => 'X'])));
        $this->assertSame('cold_chain', NotificationKind::of($this->notification('App\\Any', ['kind' => 'cold_chain'])));
        $this->assertSame('other', NotificationKind::of($this->notification('App\\Any', [])));
        $this->assertSame('Soğuk Zincir', NotificationKind::label('cold_chain'));
        $this->assertSame('Diğer', NotificationKind::label('bilinmeyen'));
    }

    public function test_summary_uses_message_or_stock_reason(): void
    {
        $this->assertSame('Transfer onaylandı.', NotificationKind::summary($this->notification('App\\Any', ['message' => 'Transfer onaylandı.'])));
        $this->assertSame('Kompozit: Stok tamamen tükendi.', NotificationKind::summary($this->notification(StockLevelAlert::class, ['product_name' => 'Kompozit', 'reasons' => ['Stok tamamen tükendi.']])));
        $this->assertSame('Kompozit', NotificationKind::summary($this->notification(StockLevelAlert::class, ['product_name' => 'Kompozit', 'reasons' => []])));
    }
}
```

- [ ] **Step 2: Run — FAIL** `php artisan test --compact --filter=NotificationKindTest`

- [ ] **Step 3: Implement**

`app/Support/Notifications/NotificationKind.php`:

```php
<?php

namespace App\Support\Notifications;

use App\Domain\Stock\Notifications\StockLevelAlert;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Uygulama içi bildirim türleri ve okunur özetleri. Bildirimler sayfası ve
 * günlük özet e-postası (Aşama 39) aynı etiketleri kullanır.
 */
final class NotificationKind
{
    /** Tür => etiket; sıra, özet e-postasındaki grup sırasıdır. */
    public const LABELS = [
        'stock' => 'Stok Uyarıları',
        'cold_chain' => 'Soğuk Zincir',
        'finance' => 'Finans',
        'transfer' => 'Transfer',
        'purchase' => 'Satın Alma',
        'stock_count' => 'Stok Sayımı',
        'return' => 'İade',
        'plan' => 'Paket',
    ];

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? 'Diğer';
    }

    public static function of(DatabaseNotification $notification): string
    {
        if ($notification->type === StockLevelAlert::class) {
            return 'stock';
        }

        $kind = $notification->data['kind'] ?? null;

        return is_string($kind) && array_key_exists($kind, self::LABELS) ? $kind : 'other';
    }

    /**
     * Tek satırlık okunur metin.
     */
    public static function summary(DatabaseNotification $notification): string
    {
        $data = $notification->data;

        if (filled($data['message'] ?? null)) {
            return $data['message'];
        }

        $product = $data['product_name'] ?? 'Bildirim';
        $reason = $data['reasons'][0] ?? null;

        return $reason ? "{$product}: {$reason}" : $product;
    }
}
```

In `⚡index.blade.php`: delete the `public const KINDS = [...]` block, add `use App\Support\Notifications\NotificationKind;`, replace `self::KINDS` with `NotificationKind::LABELS` (two places in `with()`).

- [ ] **Step 4: Run** `php artisan test --compact --filter='NotificationKindTest|Notification'` — PASS.
- [ ] **Step 5: Commit** `feat: bildirim türleri ortak sınıfta, soğuk zincir süzgeci — Aşama 39 (1/4)`

---

### Task 2: Günlük özet servisi, e-postası, komut ve tercih

**Files:**
- Create: `database/migrations/2026_10_16_120000_add_daily_digest_to_users.php`
- Create: `app/Domain/Access/Services/DailyDigestService.php`, `app/Domain/Access/Notifications/DailyDigest.php`, `app/Console/Commands/SendDailyDigestCommand.php`
- Modify: `app/Models/User.php` (casts), `routes/console.php`, `resources/views/pages/notifications/⚡index.blade.php` (anahtar)
- Test: `tests/Feature/Notifications/DailyDigestTest.php`

**Interfaces:**
- Consumes: `NotificationKind`.
- Produces: `DailyDigestService::send(?CarbonImmutable $now = null): int` (gönderilen e-posta sayısı); `DailyDigest(array $groups, int $total)` — `$groups`: `array<string label, array{items: list<string>, more: int}>`; komut `notifications:send-digest`; Livewire eylemi `toggleDigest()`.

- [ ] **Step 1: Test**

`tests/Feature/Notifications/DailyDigestTest.php`:

```php
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
```

Not: `User::ROLE_PLATFORM_OWNER` sabitinin adını `app/Models/User.php`'den doğrula; farklıysa testte onu kullan.

- [ ] **Step 2: Run — FAIL** `php artisan test --compact --filter=DailyDigestTest`

- [ ] **Step 3: Migration**

`database/migrations/2026_10_16_120000_add_daily_digest_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('daily_digest')->default(true)->after('must_change_password');
            $table->timestamp('digest_sent_at')->nullable()->after('daily_digest');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_digest', 'digest_sent_at']);
        });
    }
};
```

`app/Models/User.php` casts: add `'daily_digest' => 'boolean', 'digest_sent_at' => 'datetime',`.

- [ ] **Step 4: Notification**

`app/Domain/Access/Notifications/DailyDigest.php`:

```php
<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 39: kullanıcının son özetten beri düşen okunmamış bildirimlerinin
 * gruplu e-posta özeti. Kuyrukta gönderilir; zamanlayıcı SMTP'yi beklemez.
 */
class DailyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, array{items: list<string>, more: int}>  $groups  etiket => satırlar
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $total,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Dental ERP — günlük özet: {$this->total} yeni bildirim")
            ->greeting("Merhaba {$notifiable->name},")
            ->line("Dünden bu yana okunmamış {$this->total} bildiriminiz var.");

        foreach ($this->groups as $label => $group) {
            $mail->line("**{$label}**");

            foreach ($group['items'] as $item) {
                $mail->line("• {$item}");
            }

            if ($group['more'] > 0) {
                $mail->line("• … ve {$group['more']} bildirim daha");
            }
        }

        return $mail
            ->action('Bildirimleri aç', route('notifications.index'))
            ->line('Bu e-postayı Bildirimler sayfasından kapatabilirsiniz.');
    }
}
```

- [ ] **Step 5: Service**

`app/Domain/Access/Services/DailyDigestService.php`:

```php
<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Notifications\DailyDigest;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use App\Support\Notifications\NotificationKind;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Günlük özet (Aşama 39). İçerik kullanıcının uygulama içi bildirimleridir:
 * alıcı seçimi, şube kapsamı ve tekrar engeli bildirim üretilirken zaten
 * uygulandığı için burada ikinci bir hesap yolu açılmaz.
 */
class DailyDigestService
{
    private const ITEMS_PER_GROUP = 10;

    /**
     * @return int gönderilen e-posta sayısı
     */
    public function send(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        User::query()
            ->whereNotNull('organization_id')
            ->where('status', 'active')
            ->where('daily_digest', true)
            ->whereHas('organization', fn ($query) => $query->where('status', '!=', OrganizationStatus::Passive->value))
            ->chunkById(100, function (Collection $users) use ($now, &$sent) {
                foreach ($users as $user) {
                    $sent += $this->sendTo($user, $now) ? 1 : 0;
                }
            });

        return $sent;
    }

    private function sendTo(User $user, CarbonImmutable $now): bool
    {
        $from = CarbonImmutable::parse($user->digest_sent_at ?? $now->subDay())->max($now->subDays(7));

        $notifications = $user->unreadNotifications()
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $now)
            ->oldest()
            ->get();

        $user->forceFill(['digest_sent_at' => $now])->save();

        if ($notifications->isEmpty()) {
            return false;
        }

        $user->notify(new DailyDigest($this->group($notifications), $notifications->count()));

        return true;
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<string, array{items: list<string>, more: int}>
     */
    private function group(Collection $notifications): array
    {
        $order = [...array_keys(NotificationKind::LABELS), 'other'];

        return $notifications
            ->groupBy(fn (DatabaseNotification $notification) => NotificationKind::of($notification))
            ->sortBy(fn ($items, string $kind) => array_search($kind, $order, true))
            ->mapWithKeys(fn (Collection $items, string $kind) => [NotificationKind::label($kind) => [
                'items' => $items->take(self::ITEMS_PER_GROUP)->map(fn ($n) => NotificationKind::summary($n))->values()->all(),
                'more' => max(0, $items->count() - self::ITEMS_PER_GROUP),
            ]])
            ->all();
    }
}
```

Note: `forceFill` on `User` triggers auditing if `User` is `Auditable`; check and, if so, use `$user->newQuery()->whereKey($user->id)->update(['digest_sent_at' => $now])` to avoid an audit row per user per day.

- [ ] **Step 6: Command + schedule**

`app/Console/Commands/SendDailyDigestCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Access\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendDailyDigestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:send-digest';

    /**
     * @var string
     */
    protected $description = 'Kullanıcılara son özetten beri düşen okunmamış bildirimlerinin günlük e-posta özetini gönderir.';

    public function handle(DailyDigestService $digests): int
    {
        $this->info($digests->send().' özet e-postası kuyruğa alındı.');

        return self::SUCCESS;
    }
}
```

`routes/console.php` (after `finance:scan-due`):

```php

// Günlük özet e-postası (Aşama 39): stok taraması saatlik, finans vade taraması
// 05:00'te çalıştığı için özet o sabahın uyarılarını da içerir.
Schedule::command('notifications:send-digest')->dailyAt('07:30')->timezone('Europe/Istanbul')->withoutOverlapping();
```

- [ ] **Step 7: Toggle on notifications page**

In `⚡index.blade.php` component add:

```php
    public function toggleDigest(): void
    {
        $user = auth()->user();
        $user->forceFill(['daily_digest' => ! $user->daily_digest])->save();
    }
```

In the header, under the description paragraph:

```blade
            <p class="text-[13px] text-ink-muted mt-3">
                Günlük özet e-postası {{ auth()->user()->daily_digest ? 'açık' : 'kapalı' }}.
                <button type="button" wire:click="toggleDigest" class="text-brand-600 hover:underline">{{ auth()->user()->daily_digest ? 'Kapat' : 'Aç' }}</button>
            </p>
```

(Test asserts `Günlük özet e-postası açık` — exact text above.)

- [ ] **Step 8: Run** `php artisan test --compact --filter='DailyDigestTest|Notification'` — PASS.
- [ ] **Step 9: Commit** `feat: günlük özet e-postası — servis, komut ve tercih — Aşama 39 (2/4)`

---

### Task 3: Personel daveti

**Files:**
- Create: `app/Domain/Access/Notifications/StaffAccountCreated.php`, `app/Domain/Access/Exceptions/InviteNotSentException.php`
- Modify: `app/Domain/Access/Services/StaffService.php` (createStaff, resendInvite)
- Modify: `resources/views/pages/access/⚡staff.blade.php` (şifre alanı kalkar, `resendInvite` eylemi)
- Modify: tests that `->set('password', …)` on `pages::access.staff`
- Test: `tests/Feature/Access/StaffInviteTest.php`

**Interfaces:**
- Produces: `StaffAccountCreated(string $organizationName, string $temporaryPassword, bool $isReset = false)` public readonly props; `StaffService::createStaff(array{name,email,branch_id} $attributes, ...)` (no `password`); `StaffService::resendInvite(User $staff): void`; staff page action `resendInvite(int $userId)`.

- [ ] **Step 1: Test**

`tests/Feature/Access/StaffInviteTest.php`:

```php
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
            ->assertSee('Geçici şifre personelin e-postasına gönderilir.');
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
```

- [ ] **Step 2: Run — FAIL** `php artisan test --compact --filter=StaffInviteTest`

- [ ] **Step 3: Notification**

`app/Domain/Access/Notifications/StaffAccountCreated.php`:

```php
<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 39: yeni personelin hesabı açıldığında veya Admin yeni geçici şifre
 * gönderdiğinde. Ana Klinik Sahibi için kullanılan OrganizationAccountCreated
 * deseniyle aynıdır; senkron gönderilir (geçici şifre kuyruk deposunda beklemez).
 */
class StaffAccountCreated extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $temporaryPassword,
        public readonly bool $isReset = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->isReset ? 'Dental ERP — geçici şifreniz yenilendi' : 'Dental ERP — hesabınız açıldı')
            ->greeting("Merhaba {$notifiable->name},")
            ->line($this->isReset
                ? "{$this->organizationName} hesabınız için yeni bir geçici şifre oluşturuldu."
                : "{$this->organizationName} için Dental ERP hesabınız açıldı.")
            ->line("Giriş e-postası: {$notifiable->email}")
            ->line("Geçici şifre: {$this->temporaryPassword}")
            ->action('Giriş Yap', route('login'))
            ->line('İlk girişte kendi şifrenizi belirlemeniz istenecek.');
    }
}
```

- [ ] **Step 4: Service**

In `StaffService`:
- docblock `@param array{name: string, email: string, branch_id: int} $attributes`
- inside the transaction: `$password = Str::password(12, symbols: false);` before `User::create`, `'password' => Hash::make($password), 'must_change_password' => true,`; return `[$user, $password]` from the closure.
- after the transaction:

```php
        [$user, $password] = DB::transaction(function () use (...) { ... return [$user, $password]; });

        $user->notify(new StaffAccountCreated($user->organization->name, $password));

        return $user;
```

Mail failure must not undo the user: the page catches it (Step 5). Add:

```php
    /**
     * Personele yeni geçici şifre üretip e-postalar (Aşama 39); ilk girişte
     * yine değiştirmesi istenir. Yalnızca aktif personel için.
     */
    public function resendInvite(User $staff): void
    {
        if ($staff->role !== User::ROLE_STAFF || $staff->status !== 'active') {
            throw new StaffRuleException('Yeni geçici şifre yalnızca aktif personele gönderilebilir.');
        }

        $password = Str::password(12, symbols: false);
        $staff->update(['password' => Hash::make($password), 'must_change_password' => true]);
        $staff->notify(new StaffAccountCreated($staff->organization->name, $password, isReset: true));
    }
```

Add imports `Illuminate\Support\Str`, `App\Domain\Access\Notifications\StaffAccountCreated`.

Also update the one direct caller in `tests/Feature/Audit/AuditableTest.php` (remove `'password'` from attributes).

- [ ] **Step 5: Page**

In `⚡staff.blade.php`:
- remove `public string $password = '';`, `'password'` from `reset([...])`, the validation rule, the `'password' =>` attribute, and the form field block; put in its place (same grid cell):

```blade
                <p class="text-[13px] text-ink-muted sm:col-span-2">Geçici şifre personelin e-postasına gönderilir; ilk girişte kendi şifresini belirler.</p>
```

(the test looks for `Geçici şifre personelin e-postasına gönderilir.`)
- `save()`: the service commits the user before mailing; a mail failure surfaces as `InviteNotSentException` and the record stays:

```php
        try {
            $staff = $staffService->createStaff(...);
        } catch (StaffRuleException|PlanLimitException $e) {
            $this->addError('name', $e->getMessage());

            return;
        } catch (InviteNotSentException $e) {
            $this->closeForm();
            session()->flash('error', 'Personel oluşturuldu ancak e-posta gönderilemedi. "Yeni şifre gönder" ile tekrar deneyin.');

            return;
        }
```

with `App\Domain\Access\Exceptions\InviteNotSentException extends RuntimeException` thrown by the service when `notify()` fails (after `report($e)`), for both `createStaff` and `resendInvite`.

- `resendInvite` action:

```php
    public function resendInvite(int $userId, StaffService $staffService): void
    {
        Gate::authorize('staff_management.update');
        $staff = $this->findStaff($userId);

        try {
            $staffService->resendInvite($staff);
        } catch (StaffRuleException $e) {
            abort(404);
        } catch (InviteNotSentException) {
            session()->flash('error', 'E-posta gönderilemedi. Biraz sonra tekrar deneyin.');

            return;
        }

        session()->flash('status', "Yeni geçici şifre {$staff->email} adresine gönderildi.");
    }
```

(`findStaff` already 404s for other organizations; `StaffRuleException` → 404 covers the Admin row.)
- list row, in the `@elseif ($member->status === 'active')` branch, before "Pasife Al":

```blade
                                    <button wire:click="resendInvite({{ $member->id }})" wire:confirm="{{ $member->name }} için yeni geçici şifre üretilip e-postasına gönderilsin mi? Eski şifresi geçersiz olur." class="text-[13px] text-brand-600 hover:underline mr-3">
                                        Yeni şifre gönder
                                    </button>
```

Check `findStaff`/`staffQuery()` excludes the Admin; if it doesn't, the `StaffRuleException` path handles it.

- [ ] **Step 6: Existing tests** — for every test that drives `pages::access.staff` with `->set('password', …)`: delete that call. Where the test afterwards logs in as the created staff with that password, add `Notification::fake()` before creation and read the password:

```php
$password = null;
Notification::assertSentTo(User::where('email', $email)->sole(), StaffAccountCreated::class, function ($n) use (&$password) { $password = $n->temporaryPassword; return true; });
```

and expect the forced redirect to the change-password page on first login where the test previously expected the dashboard (or set `must_change_password` false explicitly if the test is about something else).

Find them: `grep -rln "pages::access.staff" tests`.

- [ ] **Step 7: Run** `php artisan test --compact` — all PASS.
- [ ] **Step 8: Commit** `feat: personel daveti — geçici şifre e-postası ve yeni şifre gönderme — Aşama 39 (3/4)`

---

### Task 4: Görsel kontrol, belgeler, rapor

- [ ] `npm run build`; render `/bildirimler` and `/personel` (staff form open) and check visually (desktop + narrow).
- [ ] Render the two mails: `php artisan tinker` → `(new DailyDigest([...], 3))->toMail($user)->render()` into an HTML file, screenshot.
- [ ] README: under Uyarılar ve raporlar add "günlük özet e-postası (07:30, kapatılabilir)"; under Organizasyon: "personel geçici şifreyle e-postayla davet edilir".
- [ ] `docs/production.md` or `docs/dokploy.md`: note that the digest needs MAIL_* and the queue worker; Gmail SMTP günlük gönderim sınırı (~500) notu.
- [ ] Commit `docs: günlük özet ve personel daveti — README — Aşama 39 (4/4)`
- [ ] NotebookLM `asama-39-rapor.md`.
