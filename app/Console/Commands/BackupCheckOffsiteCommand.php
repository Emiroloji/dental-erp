<?php

namespace App\Console\Commands;

use App\Domain\Platform\Contracts\ErrorReporter;
use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Aşama 30 — "yedek gerçekten dışarı gitti mi" kontrolü.
 *
 * Yedekleme sisteminin en tehlikeli hatası sessizce çalışmamaktır: rclone
 * yetkisi düşer, hedef klasör silinir, disk dolar — ve bu, yedeğe ihtiyaç
 * duyulan gün fark edilir. Bu komut hedeğe bakıp en yeni yedeğin yaşını
 * ölçer; eskiyse log'a yazar, hata izlemeye bildirir (SENTRY_DSN tanımlıysa
 * Sentry oradan e-posta gönderir) ve hata koduyla çıkar.
 */
class BackupCheckOffsiteCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:check-offsite';

    /**
     * @var string
     */
    protected $description = 'Sunucu dışındaki en yeni yedeğin yaşını kontrol eder; eskiyse uyarır ve hata koduyla çıkar.';

    public function handle(OffsiteBackupSync $offsite, ErrorReporter $errors): int
    {
        if (! $offsite->isConfigured()) {
            $this->warn('Sunucu dışı kopya yapılandırılmadı; kontrol edilecek bir hedef yok.');

            return self::SUCCESS;
        }

        try {
            $latest = $offsite->latestBackupAt();
        } catch (BackupException $exception) {
            return $this->reportProblem($errors, $exception->getMessage(), $exception);
        }

        $maxAgeHours = max(1, (int) config('backup.offsite.max_age_hours'));

        if ($latest === null) {
            return $this->reportProblem(
                $errors,
                sprintf('%s hedefinde hiç yedek yok.', $offsite->describe()),
            );
        }

        // Carbon 3 ondalıklı döner; yaş tam saate yuvarlanır.
        $ageHours = (int) $latest->diffInHours(CarbonImmutable::now());

        if ($ageHours > $maxAgeHours) {
            return $this->reportProblem($errors, sprintf(
                '%s hedefindeki en yeni yedek %d saatlik (%s); sınır %d saat.',
                $offsite->describe(),
                $ageHours,
                $latest->translatedFormat('d.m.Y H:i'),
                $maxAgeHours,
            ));
        }

        $this->info(sprintf(
            'Sunucu dışı yedek güncel: %s (%d saat önce, %s).',
            $latest->translatedFormat('d.m.Y H:i'),
            $ageHours,
            $offsite->describe(),
        ));

        return self::SUCCESS;
    }

    private function reportProblem(ErrorReporter $errors, string $message, ?BackupException $exception = null): int
    {
        $this->error($message);
        Log::error('[yedek] '.$message);
        $errors->report($exception ?? new BackupException($message), ['kaynak' => 'backup:check-offsite']);

        return self::FAILURE;
    }
}
