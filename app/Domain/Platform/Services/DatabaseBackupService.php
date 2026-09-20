<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Exceptions\BackupException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Aşama 28 — veritabanı yedekleme.
 *
 * Yedek, pg_dump çıktısının gzip ile sıkıştırılmış düz SQL hâlidir. Düz SQL
 * bilinçli bir tercih: dosya her ortamda psql ile geri yüklenebilir, gerekirse
 * gözle okunabilir ve pg_restore sürüm uyumu derdi olmaz. Dökümde
 * --clean --if-exists bulunduğu için geri yükleme, var olan şemanın üzerine
 * güvenle yazar.
 *
 * Yedekler önce yerel geçici bir dosyaya yazılır, sonra yapılandırılan diske
 * (yerelde storage, canlıda s3 vb.) aktarılır — böylece pg_dump'ın uzak diske
 * doğrudan yazabilmesi gerekmez.
 */
class DatabaseBackupService
{
    /**
     * @param  array<string, mixed>  $connection  config/database.php'deki aktif bağlantı
     * @param  array<string, mixed>  $options  config/backup.php
     */
    public function __construct(
        private readonly array $connection,
        private readonly array $options,
    ) {}

    public function fileName(?CarbonImmutable $at = null): string
    {
        return sprintf(
            '%s-%s.sql.gz',
            $this->connection['database'] ?? 'database',
            ($at ?? CarbonImmutable::now())->format('Y-m-d-His'),
        );
    }

    /**
     * Yedeği alır, diske yazar ve saklama süresi dolmuş yedekleri siler.
     *
     * @return array{file: string, size: int, pruned: array<int, string>}
     */
    public function run(?CarbonImmutable $at = null): array
    {
        $this->assertPostgres();

        $fileName = $this->fileName($at);
        $temporaryPath = $this->temporaryPath($fileName);

        try {
            $this->execute($this->dumpCommandLine($temporaryPath), 'Yedek alınamadı');

            $stream = fopen($temporaryPath, 'rb');

            try {
                $this->disk()->writeStream($this->path($fileName), $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $size = (int) $this->disk()->size($this->path($fileName));
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return ['file' => $fileName, 'size' => $size, 'pruned' => $this->prune($at)];
    }

    /**
     * Yedeği aktif veritabanına geri yükler. Mevcut veriyi değiştirir —
     * çağıran taraf onayı almış olmalıdır.
     */
    public function restore(string $fileName): void
    {
        $this->assertPostgres();

        if (! $this->disk()->exists($this->path($fileName))) {
            throw new BackupException("Yedek dosyası bulunamadı: {$fileName}");
        }

        $temporaryPath = $this->temporaryPath($fileName);

        try {
            $stream = $this->disk()->readStream($this->path($fileName));
            $target = fopen($temporaryPath, 'wb');

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if (is_resource($target)) {
                    fclose($target);
                }
            }

            $this->execute($this->restoreCommandLine($temporaryPath), 'Yedek geri yüklenemedi');
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * Diskteki yedekler, en yeniden eskiye.
     *
     * @return array<int, array{name: string, size: int, modified_at: CarbonImmutable}>
     */
    public function backups(): array
    {
        $files = collect($this->disk()->files($this->options['path']))
            ->filter(fn (string $file) => str_ends_with($file, '.sql.gz'))
            ->map(fn (string $file) => [
                'name' => basename($file),
                'size' => (int) $this->disk()->size($file),
                'modified_at' => CarbonImmutable::createFromTimestamp($this->disk()->lastModified($file)),
            ])
            ->sortByDesc('modified_at')
            ->values();

        return $files->all();
    }

    /**
     * Saklama süresi dolmuş yedekleri siler.
     *
     * @return array<int, string> silinen dosya adları
     */
    public function prune(?CarbonImmutable $at = null): array
    {
        $threshold = ($at ?? CarbonImmutable::now())->subDays($this->retentionDays());

        $deleted = [];

        foreach ($this->backups() as $backup) {
            if ($backup['modified_at']->lessThan($threshold)) {
                $this->disk()->delete($this->path($backup['name']));
                $deleted[] = $backup['name'];
            }
        }

        return $deleted;
    }

    public function dumpCommandLine(string $targetPath): string
    {
        $command = implode(' ', [
            $this->options['pg_dump'],
            ...$this->connectionArguments(),
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
            '--format=plain',
        ]);

        return $command.' | gzip -9 > '.escapeshellarg($targetPath);
    }

    public function restoreCommandLine(string $sourcePath): string
    {
        $command = implode(' ', [
            $this->options['psql'],
            ...$this->connectionArguments(),
            '--set',
            'ON_ERROR_STOP=on',
            '--quiet',
        ]);

        return 'gunzip -c '.escapeshellarg($sourcePath).' | '.$command;
    }

    public function retentionDays(): int
    {
        return max(1, (int) $this->options['retention_days']);
    }

    /**
     * @return array<int, string>
     */
    private function connectionArguments(): array
    {
        return [
            '--host='.escapeshellarg((string) $this->connection['host']),
            '--port='.escapeshellarg((string) $this->connection['port']),
            '--username='.escapeshellarg((string) $this->connection['username']),
            '--dbname='.escapeshellarg((string) $this->connection['database']),
        ];
    }

    private function execute(string $commandLine, string $failureMessage): void
    {
        $result = Process::timeout((float) $this->options['timeout'])
            ->env(['PGPASSWORD' => (string) ($this->connection['password'] ?? '')])
            ->run($commandLine);

        if ($result->failed()) {
            throw new BackupException($failureMessage.': '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function assertPostgres(): void
    {
        if (($this->connection['driver'] ?? null) !== 'pgsql') {
            throw new BackupException(
                'Yedekleme yalnızca PostgreSQL bağlantısında çalışır, mevcut sürücü: '
                .($this->connection['driver'] ?? 'tanımsız').'.'
            );
        }
    }

    private function temporaryPath(string $fileName): string
    {
        $directory = storage_path('app/backup-tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.$fileName;

        touch($path);

        return $path;
    }

    private function path(string $fileName): string
    {
        return trim($this->options['path'], '/').'/'.$fileName;
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->options['disk']);
    }
}
