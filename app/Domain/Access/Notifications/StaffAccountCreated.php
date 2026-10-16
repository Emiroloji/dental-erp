<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 39: yeni personelin hesabı açıldığında veya Admin yeni geçici şifre
 * gönderdiğinde. Ana Klinik Sahibi için kullanılan OrganizationAccountCreated
 * deseniyle aynıdır; senkron gönderilir (geçici şifre kuyruk deposunda beklemez).
 */
class StaffAccountCreated extends Notification
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
                : "{$this->organizationName} için Dental ERP hesabınız açıldı.")
            ->line("Giriş e-postası: {$notifiable->email}")
            ->line("Geçici şifre: {$this->temporaryPassword}")
            ->action('Giriş Yap', route('login'))
            ->line('İlk girişte kendi şifrenizi belirlemeniz istenecek.');
    }
}
