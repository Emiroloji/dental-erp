<?php

namespace App\Domain\Transfer\Notifications;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Transfer bildirimleri (proje.md Bölüm 11: "yeni talep, talep onay/red, transfer").
 * Yeni talep, onaylayacak kaynak tarafa; onay/red talep edene; gönderim teslim
 * alacak hedef tarafa; teslim ve iptal karşı tarafa gider.
 */
class TransferNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function statusChanged(TransferRequest $transfer, TransferStatus $status, User $actor, ?string $note = null): void
    {
        $transfer->loadMissing(['product', 'fromWarehouse.branch', 'toWarehouse.branch', 'requester']);

        [$title, $level, $recipients] = match ($status) {
            TransferStatus::Pending => ['Yeni transfer talebi', WorkflowNotification::LEVEL_INFO, $this->side($transfer->fromWarehouse)],
            TransferStatus::Approved => ['Transfer talebi onaylandı', WorkflowNotification::LEVEL_GOOD, [$transfer->requester]],
            TransferStatus::Rejected => ['Transfer talebi reddedildi', WorkflowNotification::LEVEL_WARN, [$transfer->requester]],
            TransferStatus::Shipped => ['Transfer gönderildi — teslim alın', WorkflowNotification::LEVEL_INFO, [...$this->side($transfer->toWarehouse), $transfer->requester]],
            TransferStatus::Received => ['Transfer teslim alındı', WorkflowNotification::LEVEL_GOOD, $this->side($transfer->fromWarehouse)],
            TransferStatus::Cancelled => ['Transfer iptal edildi', WorkflowNotification::LEVEL_WARN, [...$this->side($transfer->fromWarehouse), $transfer->requester]],
            default => [null, null, []],
        };

        if ($title === null) {
            return;
        }

        $message = "Transfer #{$transfer->id} · {$transfer->product->name} · "
            .Number::format((float) $transfer->quantity, maxPrecision: 2)." {$transfer->product->base_unit} · "
            ."{$transfer->fromWarehouse->branch->name} {$transfer->fromWarehouse->name} → {$transfer->toWarehouse->branch->name} {$transfer->toWarehouse->name}"
            .(filled($note) ? " — {$note}" : '');

        $this->recipients->send($recipients, new WorkflowNotification('transfer', $title, $message, route('transfers.index'), $level), $actor);
    }

    /**
     * Depo tarafı: o şubede Transfer yazma yetkili personel + Admin.
     *
     * @return Collection<int, User>
     */
    private function side(Warehouse $warehouse): Collection
    {
        $organizationId = $warehouse->branch->organization_id;

        return $this->recipients->admins($organizationId)
            ->concat($this->recipients->staffWith($organizationId, Module::Transfer, 'write', $warehouse->branch_id));
    }
}
