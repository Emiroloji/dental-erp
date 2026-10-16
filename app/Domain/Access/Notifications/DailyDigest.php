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
