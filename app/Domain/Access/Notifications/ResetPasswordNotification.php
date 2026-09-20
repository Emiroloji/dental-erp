<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 28: şifremi unuttum akışının e-postası. Laravel'in hazır
 * password reset altyapısını kullanır; yalnızca metin Türkçeleştirilir.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Dental ERP — Şifre sıfırlama')
            ->greeting('Merhaba '.$notifiable->name.',')
            ->line('Hesabınız için bir şifre sıfırlama talebi aldık.')
            ->action('Şifremi sıfırla', $this->resetUrl($notifiable))
            ->line("Bu bağlantı {$minutes} dakika boyunca geçerlidir ve yalnızca bir kez kullanılabilir.")
            ->line('Bu talebi siz yapmadıysanız bir şey yapmanıza gerek yok, şifreniz değişmez.')
            ->salutation('Dental ERP');
    }

    private function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
