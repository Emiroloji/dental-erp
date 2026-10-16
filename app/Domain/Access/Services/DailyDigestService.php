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

        // Doğrudan sorgu: User denetlenen bir model; her gün her kullanıcı için
        // denetim kaydı yazılmasın.
        User::whereKey($user->id)->update(['digest_sent_at' => $now]);

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
