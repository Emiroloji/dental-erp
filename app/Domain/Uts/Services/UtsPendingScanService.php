<?php

namespace App\Domain\Uts\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ÜTS'de kuruma yapılmış, kabul bekleyen Verme bildirimlerini tarar (Aşama
 * 47) ve ilk kez görülenler için Admin'e ve stok yazma yetkili personele
 * uygulama içi bildirim gönderir. Görülen bildirim kodları uts_pending_seen'de
 * tutulur. Oturumsuz (kuyrukta) çalışır; organizasyon bağlantıdan gelir.
 */
class UtsPendingScanService
{
    private const MAX_PAGES = 20;

    public function __construct(private readonly UtsClientFactory $clients) {}

    /**
     * @return int ilk kez görülen bildirim sayısı
     *
     * @throws UtsException
     */
    public function scan(UtsConnection $connection): int
    {
        $client = $this->clients->for($connection);
        $rows = collect();

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $client->pendingReceipts(null, $page);
            $rows = $rows->concat($result->items);

            if (! $result->hasMore) {
                break;
            }
        }

        $rows = $rows->filter(fn (PendingReceipt $row) => $row->notificationId !== null)->keyBy('notificationId');
        $seen = DB::table('uts_pending_seen')
            ->where('organization_id', $connection->organization_id)
            ->whereIn('notification_id', $rows->keys())
            ->pluck('notification_id');
        $new = $rows->except($seen->all());

        if ($new->isEmpty()) {
            return 0;
        }

        DB::table('uts_pending_seen')->insertOrIgnore($new->keys()->map(fn (string $id) => [
            'organization_id' => $connection->organization_id,
            'notification_id' => $id,
            'first_seen_at' => now(),
        ])->all());

        $this->notify($connection->organization_id, $new->values());

        return $new->count();
    }

    /**
     * @param  Collection<int, PendingReceipt>  $rows
     */
    private function notify(int $organizationId, Collection $rows): void
    {
        $senders = $rows->map(fn (PendingReceipt $row) => $row->senderName ?? (string) $row->senderCode)->filter()->unique()->take(3)->implode(', ');
        $message = "{$rows->count()} yeni Verme bildirimi kabul bekliyor".($senders !== '' ? " ({$senders})." : '.');

        foreach ($this->recipients($organizationId) as $recipient) {
            $unread = $recipient->unreadNotifications()->where('data->kind', 'uts')->exists();

            if (! $unread) {
                $recipient->notify(new WorkflowNotification('uts', 'ÜTS\'de kabul bekleyen yeni ürün', $message, url('/uts/kabul-bekleyenler'), WorkflowNotification::LEVEL_WARN));
            }
        }
    }

    /**
     * Kabul edebilecek olanlar: Admin ve stok yazma yetkili personel.
     *
     * @return Collection<int, User>
     */
    private function recipients(int $organizationId): Collection
    {
        return User::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('role', User::ROLE_ADMIN)
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('module', Module::StockMovement->value)->where('can_write', true)))
            ->get();
    }
}
