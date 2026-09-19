<?php

namespace App\Domain\Stock\Notifications;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Number;

/**
 * Saklama aralığı dışında ölçülüp gerekçeyle kabul edilen soğuk zincir
 * girişini Ana Klinik Sahibi'ne bildirir (Aşama 26).
 */
class ColdChainNotifier
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function outOfRangeAccepted(StockMovement $movement, ?User $actor): void
    {
        $movement->loadMissing('lot.product', 'warehouse.branch');
        $product = $movement->lot->product;

        $message = "{$product->name} · lot {$movement->lot->lot_no} · ölçülen ".Number::format((float) $movement->temperature, maxPrecision: 1)." °C (aralık {$product->storageRangeLabel()}) · "
            ."{$movement->warehouse->branch->name} {$movement->warehouse->name} — gerekçe: {$movement->temperature_note}";

        $this->recipients->send(
            $this->recipients->admins($product->organization_id),
            new WorkflowNotification('cold_chain', 'Soğuk zincir: aralık dışı giriş kabul edildi', $message, route('stock.movements'), WorkflowNotification::LEVEL_WARN),
            $actor,
        );
    }
}
