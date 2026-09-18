<?php

namespace App\Domain\Returns\Notifications;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Support\ReturnStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Number;

/**
 * İade bildirimleri: yeni iade talebi Admin'lere (onay yalnızca Admin'de),
 * onay/red ve tedarikçinin kararı talebi açana gider.
 */
class ReturnNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function statusChanged(SupplierReturn $return, ReturnStatus $status, User $actor, ?string $note = null): void
    {
        $return->loadMissing(['product', 'supplier', 'requester']);

        [$title, $level, $recipients] = match ($status) {
            ReturnStatus::Requested => ['İade talebi onay bekliyor', WorkflowNotification::LEVEL_INFO, $this->recipients->admins($return->organization_id)],
            ReturnStatus::Approved => ['İade talebi onaylandı — kargoya verilebilir', WorkflowNotification::LEVEL_GOOD, [$return->requester]],
            ReturnStatus::Rejected => ['İade reddedildi', WorkflowNotification::LEVEL_WARN, [$return->requester]],
            ReturnStatus::SupplierApproved => ['Tedarikçi iadeyi onayladı', WorkflowNotification::LEVEL_GOOD, [$return->requester]],
            default => [null, null, []],
        };

        if ($title === null) {
            return;
        }

        $message = "{$return->number()} · {$return->product->name} · "
            .Number::format((float) $return->quantity, maxPrecision: 2)." {$return->product->base_unit} · {$return->supplier->name}"
            .(filled($note) ? " — {$note}" : '');

        $this->recipients->send($recipients, new WorkflowNotification('return', $title, $message, route('returns.show', $return), $level), $actor);
    }
}
