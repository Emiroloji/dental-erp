<?php

namespace App\Domain\Finance\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Vadesi yaklaşan veya geçmiş ödeme/tahsilat. obligation_id ve state, aynı
 * durumun okunmadan tekrar gönderilmemesi için tutulur (stok uyarılarıyla aynı desen).
 */
class FinanceDueNotification extends Notification
{
    public function __construct(
        public readonly int $obligationId,
        public readonly string $state,
        public readonly string $title,
        public readonly string $message,
        public readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'finance',
            'level' => $this->state === 'overdue' ? 'critical' : 'low',
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'obligation_id' => $this->obligationId,
            'state' => $this->state,
        ];
    }
}
