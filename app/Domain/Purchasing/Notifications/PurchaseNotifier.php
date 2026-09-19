<?php

namespace App\Domain\Purchasing\Notifications;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Number;

/**
 * Satın alma bildirimleri (proje.md Bölüm 11: "satın alma talebi/onayı"):
 * onaya gönderilen talep Admin'lere, onay/red talebi açana gider.
 */
class PurchaseNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function statusChanged(PurchaseOrder $order, PurchaseOrderStatus $status, User $actor, ?string $note = null): void
    {
        $order->loadMissing(['supplier', 'warehouse.branch', 'lines', 'requester']);

        [$title, $level, $recipients] = match ($status) {
            PurchaseOrderStatus::PendingApproval => ['Satın alma talebi onay bekliyor', WorkflowNotification::LEVEL_INFO, $this->recipients->admins($order->organization_id)],
            PurchaseOrderStatus::Approved => ['Satın alma talebi onaylandı', WorkflowNotification::LEVEL_GOOD, [$order->requester]],
            PurchaseOrderStatus::Rejected => ['Satın alma talebi reddedildi', WorkflowNotification::LEVEL_WARN, [$order->requester]],
            default => [null, null, []],
        };

        if ($title === null) {
            return;
        }

        $message = "{$order->number()} · {$order->supplier->name} · {$order->lines->count()} kalem · "
            .Number::format($order->total(), precision: 2)." ₺ · {$order->warehouse->branch->name} {$order->warehouse->name}"
            .(filled($note) ? " — {$note}" : '');

        $this->recipients->send($recipients, new WorkflowNotification('purchase', $title, $message, route('purchasing.show', $order), $level), $actor);
    }
}
