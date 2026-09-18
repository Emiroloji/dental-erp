<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\Notification;

/**
 * İş akışı bildirimi (proje.md Bölüm 11: yeni talep, talep onay/red, transfer,
 * satın alma talebi/onayı, sayım, iade). Her domain kendi mesajını üretir;
 * bildirim ilgili sayfaya yönlendiren bir bağlantı taşır.
 *
 * mimari.md Bölüm 8: uygulama içi bildirimler senkron yazılır (bkz. StockLevelAlert).
 */
class WorkflowNotification extends Notification
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_WARN = 'low';

    public const LEVEL_GOOD = 'good';

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly string $url,
        public readonly string $level = self::LEVEL_INFO,
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
            'kind' => $this->kind,
            'level' => $this->level,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
