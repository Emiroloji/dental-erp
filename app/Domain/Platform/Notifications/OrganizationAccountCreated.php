<?php

namespace App\Domain\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * proje.md Bölüm 2: "Sistem otomatik olarak Ana Klinik Sahibi için bir admin
 * hesabı üretir ve geçici şifre ile e-posta gönderir." Aynı bildirim, Platform
 * Sahibi geçici şifreyi yeniden ürettiğinde de gönderilir.
 */
class OrganizationAccountCreated extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $temporaryPassword,
        public readonly bool $isReset = false,
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
        return (new MailMessage)
            ->subject($this->isReset ? 'Dental ERP — geçici şifreniz yenilendi' : 'Dental ERP — hesabınız açıldı')
            ->greeting("Merhaba {$notifiable->name},")
            ->line($this->isReset
                ? "{$this->organizationName} hesabınız için yeni bir geçici şifre oluşturuldu."
                : "{$this->organizationName} için Dental ERP hesabınız açıldı. Ana Klinik Sahibi olarak tam yetkilisiniz.")
            ->line("Giriş e-postası: {$notifiable->email}")
            ->line("Geçici şifre: {$this->temporaryPassword}")
            ->action('Giriş Yap', route('login'))
            ->line('İlk girişte kendi şifrenizi belirlemeniz istenecek.');
    }
}
