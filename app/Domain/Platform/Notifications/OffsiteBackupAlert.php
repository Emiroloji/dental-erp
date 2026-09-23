<?php

namespace App\Domain\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aşama 30 (ek) — sunucu dışı yedekle ilgili uyarı.
 *
 * Yedeğin dışarı çıkmadığını yalnızca log'a yazmak işe yaramaz: log dosyasına
 * kimse bakmaz ve durum, yedeğe ihtiyaç duyulan gün fark edilir. Bu bildirim
 * sorunu doğrudan sorumlunun gelen kutusuna taşır.
 *
 * Düzelme bildirimi de bilinçli: yalnızca "bozuldu" maili gönderilirse,
 * sessizlik "düzeldi" mi yoksa "mail de mi gitmiyor" mu belli olmaz.
 */
class OffsiteBackupAlert extends Notification
{
    private function __construct(
        public readonly bool $resolved,
        public readonly string $target,
        public readonly ?string $problem = null,
    ) {}

    public static function problem(string $target, string $problem): self
    {
        return new self(false, $target, $problem);
    }

    public static function resolved(string $target): self
    {
        return new self(true, $target);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->resolved ? $this->resolvedMessage() : $this->problemMessage();
    }

    private function problemMessage(): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Dental ERP — YEDEK UYARISI: yedek sunucu dışına çıkmıyor')
            ->greeting('Yedekleme uyarısı')
            ->line('Günlük veritabanı yedeği sunucu dışındaki hedefe ulaşmıyor. Şu anda elinizdeki tek kopya sunucunun kendi diskinde; sunucuya bir şey olursa veri de gider.')
            ->line("Hedef: {$this->target}")
            ->line("Sorun: {$this->problem}")
            ->line('Sık karşılaşılan nedenler: rclone yetkisinin düşmesi, hedef klasörün silinmesi veya yapılandırma dosyasının okunamaması.')
            ->line('Kontrol için sunucuda: php artisan backup:check-offsite')
            ->line('Bu uyarı sorun sürdüğü sürece günde bir kez tekrarlanır; düzeldiğinde ayrıca haber verilir.');
    }

    private function resolvedMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject('Dental ERP — yedekleme düzeldi')
            ->greeting('Yedekleme yeniden çalışıyor')
            ->line("Sunucu dışı yedek hedefi ({$this->target}) yeniden güncel. Ek bir işlem gerekmiyor.");
    }
}
