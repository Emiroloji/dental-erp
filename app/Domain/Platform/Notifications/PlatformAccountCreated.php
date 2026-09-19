<?php

namespace App\Domain\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Yeni Platform Sahibi hesabı veya yenilenen geçici şifre (Aşama 23).
 */
class PlatformAccountCreated extends Notification
{
    public function __construct(
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
            ->subject($this->isReset ? 'Dental ERP — platform şifreniz yenilendi' : 'Dental ERP — Platform Sahibi hesabınız açıldı')
            ->greeting("Merhaba {$notifiable->name},")
            ->line($this->isReset
                ? 'Platform Yönetici Paneli hesabınız için yeni bir geçici şifre oluşturuldu.'
                : 'Dental ERP Platform Yönetici Paneli için hesabınız açıldı.')
            ->line("Giriş e-postası: {$notifiable->email}")
            ->line("Geçici şifre: {$this->temporaryPassword}")
            ->action('Giriş Yap', route('login'))
            ->line('İlk girişte kendi şifrenizi belirlemeniz istenecek.');
    }
}
