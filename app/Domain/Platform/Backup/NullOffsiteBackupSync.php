<?php

namespace App\Domain\Platform\Backup;

use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use Carbon\CarbonImmutable;

/**
 * Aşama 30 — sunucu dışı kopya kapalıyken kullanılan uygulama.
 *
 * Geliştirme ortamının ve testlerin varsayılanı budur: hiçbir dış servise
 * bağlanılmaz. Yanlışlıkla çağrılırsa sessiz kalmaz, açık bir hata verir.
 */
class NullOffsiteBackupSync implements OffsiteBackupSync
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'yapılandırılmadı';
    }

    public function push(): array
    {
        throw new BackupException(
            'Sunucu dışı yedek hedefi yapılandırılmadı. .env dosyasında BACKUP_OFFSITE_DRIVER '
            .'ve BACKUP_RCLONE_REMOTE ayarlanmalı — bkz. docs/yedekleme.md.'
        );
    }

    public function latestBackupAt(): ?CarbonImmutable
    {
        return null;
    }
}
