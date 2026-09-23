<?php

namespace App\Domain\Platform\Backup;

use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * Aşama 30 — yedeğin rclone ile sunucu dışına kopyalanması.
 *
 * "copy" kullanılır, "sync" değil: sync yereldekini birebir yansıtır ve
 * saklama süresi dolan bir yedek yerelden silindiğinde hedeften de silerdi.
 * copy yalnızca ekler — hedefte yerelden daha uzun bir geçmiş birikir, ki
 * yedekleme için istenen budur. Dosyalar sıkıştırılmış SQL dökümü olduğu için
 * boyut yıllarca sorun çıkarmaz (bkz. docs/yedekleme.md).
 */
class RcloneOffsiteBackupSync implements OffsiteBackupSync
{
    /**
     * @param  string  $localDirectory  yedeklerin bulunduğu yerel klasörün tam yolu
     * @param  string|null  $remote  rclone hedefi, ör. "drive:dental-erp-yedek"
     * @param  string|null  $configPath  rclone.conf tam yolu (cron farklı HOME ile çalışır)
     */
    public function __construct(
        private readonly string $localDirectory,
        private readonly ?string $remote,
        private readonly string $binary = 'rclone',
        private readonly ?string $configPath = null,
        private readonly int $timeout = 900,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->remote);
    }

    public function describe(): string
    {
        return $this->remote ?? 'yapılandırılmadı';
    }

    public function push(): array
    {
        $this->assertConfigured();

        $result = $this->run([
            'copy',
            $this->localDirectory,
            $this->remote,
            '--include', '*.sql.gz',
            '--verbose',
        ], 'Yedek sunucu dışına kopyalanamadı');

        // rclone kopyaladığı her dosyayı stderr'e "<dosya>: Copied (new)" diye
        // yazar; değişmeyen dosyalar için hiçbir şey yazmaz.
        preg_match_all('/^.*?([^\s:]+\.sql\.gz): Copied \(/mi', $result, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function latestBackupAt(): ?CarbonImmutable
    {
        $this->assertConfigured();

        $output = $this->run([
            'lsjson',
            $this->remote,
            '--files-only',
            '--include', '*.sql.gz',
        ], 'Sunucu dışı yedek hedefi okunamadı');

        $files = json_decode($output, true);

        if (! is_array($files)) {
            throw new BackupException('rclone lsjson çıktısı okunamadı: '.mb_substr(trim($output), 0, 200));
        }

        $latest = null;

        foreach ($files as $file) {
            if (! isset($file['ModTime'])) {
                continue;
            }

            $modifiedAt = CarbonImmutable::parse($file['ModTime']);

            if ($latest === null || $modifiedAt->greaterThan($latest)) {
                $latest = $modifiedAt;
            }
        }

        return $latest;
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function run(array $arguments, string $failureMessage): string
    {
        if ($this->configPath !== null && $this->configPath !== '') {
            array_splice($arguments, 1, 0, ['--config', $this->configPath]);
        }

        // Komut, DatabaseBackupService ile aynı biçimde tek bir kabuk satırı
        // olarak kurulur; her parça escapeshellarg'dan geçer.
        $commandLine = $this->binary.' '.implode(' ', array_map(escapeshellarg(...), $arguments));

        $result = Process::timeout((float) $this->timeout)->run($commandLine);

        if ($result->failed()) {
            throw new BackupException($failureMessage.': '.trim($result->errorOutput() ?: $result->output()));
        }

        // "copy" ilerleme ve sonuç satırlarını stderr'e yazar, "lsjson" JSON'u
        // stdout'a; ikisini birleştirmek her iki komutta da doğru çalışır.
        return $result->output().$result->errorOutput();
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BackupException(
                'BACKUP_RCLONE_REMOTE tanımlı değil; sunucu dışı yedek hedefi bilinmiyor.'
            );
        }
    }
}
