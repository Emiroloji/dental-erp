<?php

namespace App\Domain\Platform\Notifications;

use App\Domain\Platform\Models\PlanChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 58 — yeni paket talebi Platform Sahibi'ne e-postayla bildirilir.
 *
 * Platform panelinde bildirim zili yok, menüdeki sayaç ancak panele girilince
 * görülür. Talep ödeme kontrolü bekleyen bir müşteri demek; panele bakılmayan
 * günlerde kaçmasın diye doğrudan gelen kutusuna gider.
 */
class PlanChangeRequested extends Notification
{
    public function __construct(public readonly PlanChangeRequest $request) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['organization', 'requester']);
        $change = "{$request->current_plan->label()} → {$request->requested_plan->label()}";

        $message = (new MailMessage)
            ->subject("Dental ERP — yeni paket talebi: {$request->organization->name}")
            ->greeting("Merhaba {$notifiable->name},")
            ->line("{$request->organization->name} paket değişikliği talep etti: {$change}.")
            ->line("Talep eden: {$request->requester->name} ({$request->requester->email})");

        if (filled($request->note)) {
            $message->line("Not: {$request->note}");
        }

        return $message
            ->action('Paket Taleplerini Aç', route('platform.plan-requests.index'))
            ->line('Ödemeyi sistem dışında kontrol edip talebi onaylayın veya gerekçe yazarak reddedin.');
    }
}
