<?php

namespace App\Console\Commands;

use App\Domain\Platform\Exceptions\BackupException;
use App\Domain\Platform\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupRestoreCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:restore {file? : Geri yüklenecek yedek dosyasının adı} {--force : Onay sorulmadan çalıştırır}';

    /**
     * @var string
     */
    protected $description = 'Bir yedeği veritabanına geri yükler. Mevcut veriyi yedekteki hâliyle değiştirir.';

    public function handle(DatabaseBackupService $backups): int
    {
        $available = $backups->backups();

        if ($available === []) {
            $this->error('Diskte geri yüklenebilecek bir yedek yok.');

            return self::FAILURE;
        }

        $file = $this->argument('file') ?? $this->choice(
            'Hangi yedek geri yüklensin?',
            array_map(
                fn (array $backup) => $backup['name'],
                $available,
            ),
            0,
        );

        // Geri yükleme geri alınamaz: --force verilmedikçe her ortamda onay sorulur.
        $this->warn("Bu işlem mevcut veritabanını '{$file}' yedeğiyle değiştirir. Geri alınamaz.");

        if (! $this->option('force') && ! $this->confirm('Devam edilsin mi?', false)) {
            $this->line('İşlem iptal edildi.');

            return self::FAILURE;
        }

        try {
            $backups->restore($file);
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $selected = collect($available)->firstWhere('name', $file);

        $this->info(sprintf(
            'Yedek geri yüklendi: %s%s',
            $file,
            $selected ? ' ('.Number::fileSize($selected['size']).', '.$selected['modified_at']->format('d.m.Y H:i').')' : '',
        ));

        return self::SUCCESS;
    }
}
