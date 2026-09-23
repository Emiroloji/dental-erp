<?php

namespace App\Console\Commands;

use App\Domain\Platform\Contracts\ErrorReporter;
use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use App\Domain\Platform\Notifications\OffsiteBackupAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Aşama 30 — "yedek gerçekten dışarı gitti mi" kontrolü.
 *
 * Yedekleme sisteminin en tehlikeli hatası sessizce çalışmamaktır: rclone
 * yetkisi düşer, hedef klasör silinir, disk dolar — ve bu, yedeğe ihtiyaç
 * duyulan gün fark edilir. Bu komut hedefe bakıp en yeni yedeğin yaşını
 * ölçer; eskiyse log'a yazar, hata izlemeye bildirir, yapılandırılmışsa
 * e-posta gönderir ve hata koduyla çıkar.
 */
class BackupCheckOffsiteCommand extends Command
{
    /**
     * Uyarı maili gönderildiğini hatırlayan önbellek anahtarı. Aynı sorun
     * sürerken her gün yeniden mail atılmaması ve düzeldiğinde tek bir
     * "düzeldi" maili gidebilmesi için tutulur.
     */
    private const ALERT_CACHE_KEY = 'backup:offsite-alert-sent';

    /**
     * @var string
     */
    protected $signature = 'backup:check-offsite';

    /**
     * @var string
     */
    protected $description = 'Sunucu dışındaki en yeni yedeğin yaşını kontrol eder; eskiyse uyarır, e-posta gönderir ve hata koduyla çıkar.';

    public function handle(OffsiteBackupSync $offsite, ErrorReporter $errors): int
    {
        if (! $offsite->isConfigured()) {
            $this->warn('Sunucu dışı kopya yapılandırılmadı; kontrol edilecek bir hedef yok.');

            return self::SUCCESS;
        }

        try {
            $latest = $offsite->latestBackupAt();
        } catch (BackupException $exception) {
            return $this->reportProblem($offsite, $errors, $exception->getMessage(), $exception);
        }

        $maxAgeHours = max(1, (int) config('backup.offsite.max_age_hours'));

        if ($latest === null) {
            return $this->reportProblem(
                $offsite,
                $errors,
                sprintf('%s hedefinde hiç yedek yok.', $offsite->describe()),
            );
        }

        // Carbon 3 ondalıklı döner; yaş tam saate yuvarlanır.
        $ageHours = (int) $latest->diffInHours(CarbonImmutable::now());

        if ($ageHours > $maxAgeHours) {
            return $this->reportProblem($offsite, $errors, sprintf(
                '%s hedefindeki en yeni yedek %d saatlik (%s); sınır %d saat.',
                $offsite->describe(),
                $ageHours,
                $latest->translatedFormat('d.m.Y H:i'),
                $maxAgeHours,
            ));
        }

        $this->reportRecovery($offsite);

        $this->info(sprintf(
            'Sunucu dışı yedek güncel: %s (%d saat önce, %s).',
            $latest->translatedFormat('d.m.Y H:i'),
            $ageHours,
            $offsite->describe(),
        ));

        return self::SUCCESS;
    }

    private function reportProblem(OffsiteBackupSync $offsite, ErrorReporter $errors, string $message, ?BackupException $exception = null): int
    {
        $this->error($message);
        Log::error('[yedek] '.$message);
        $errors->report($exception ?? new BackupException($message), ['kaynak' => 'backup:check-offsite']);

        $this->sendAlert($offsite, $message);

        return self::FAILURE;
    }

    /**
     * Sorun sürdüğü sürece günde bir kezden fazla mail atılmaz; aksi hâlde
     * uyarı gürültüye dönüşür ve okunmaz olur.
     */
    private function sendAlert(OffsiteBackupSync $offsite, string $message): void
    {
        $address = config('backup.offsite.alert_email');

        if (blank($address)) {
            return;
        }

        if (Cache::get(self::ALERT_CACHE_KEY) !== null) {
            $this->line('Uyarı maili yakın zamanda gönderildi, tekrarlanmıyor.');

            return;
        }

        Notification::route('mail', $address)
            ->notify(OffsiteBackupAlert::problem($offsite->describe(), $message));

        $hours = max(1, (int) config('backup.offsite.alert_throttle_hours'));
        Cache::put(self::ALERT_CACHE_KEY, CarbonImmutable::now()->toIso8601String(), now()->addHours($hours));

        $this->line("Uyarı maili gönderildi: {$address}");
    }

    /**
     * Daha önce uyarı gönderildiyse, düzeldiğini de bildirmek gerekir:
     * yalnızca "bozuldu" maili atılırsa sessizliğin "düzeldi" mi yoksa
     * "mail de mi gitmiyor" mu olduğu anlaşılmaz.
     */
    private function reportRecovery(OffsiteBackupSync $offsite): void
    {
        $address = config('backup.offsite.alert_email');

        if (blank($address) || Cache::get(self::ALERT_CACHE_KEY) === null) {
            return;
        }

        Cache::forget(self::ALERT_CACHE_KEY);

        Notification::route('mail', $address)
            ->notify(OffsiteBackupAlert::resolved($offsite->describe()));

        $this->line("Düzelme maili gönderildi: {$address}");
    }
}
