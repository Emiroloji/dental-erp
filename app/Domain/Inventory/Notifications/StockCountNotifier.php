<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;

/**
 * Sayım bildirimleri (fazlar-adimlar.md Aşama 15: "sayım tamamlandı"):
 * onaya gönderilen sayım Admin'lere; onay veya yeniden sayıma geri gönderme
 * sayımı başlatana gider.
 */
class StockCountNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function statusChanged(StockCount $count, StockCountStatus $status, User $actor, ?string $note = null): void
    {
        $count->loadMissing(['warehouse.branch', 'starter']);

        [$title, $level, $recipients] = match ($status) {
            StockCountStatus::PendingApproval => ['Sayım tamamlandı — onay bekliyor', WorkflowNotification::LEVEL_INFO, $this->recipients->admins($count->organization_id)],
            StockCountStatus::Approved => ['Sayım onaylandı', WorkflowNotification::LEVEL_GOOD, [$count->starter]],
            StockCountStatus::Counting => ['Sayım yeniden sayıma gönderildi', WorkflowNotification::LEVEL_WARN, [$count->starter]],
            default => [null, null, []],
        };

        if ($title === null) {
            return;
        }

        $differences = $count->lines()->get()->filter->hasDifference()->count();
        $message = "{$count->number()} · {$count->warehouse->branch->name} {$count->warehouse->name} · {$differences} farklı satır"
            .(filled($note) ? " — {$note}" : '');

        $this->recipients->send($recipients, new WorkflowNotification('stock_count', $title, $message, route('inventory.show', $count), $level), $actor);
    }
}
