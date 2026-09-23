<?php

namespace App\Console\Commands;

use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use App\Domain\Platform\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class BackupRunCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:run {--sunucu-disi-atla : Yedeği al ama sunucu dışına kopyalama.}';

    /**
     * @var string
     */
    protected $description = 'Veritabanının sıkıştırılmış yedeğini alır, sunucu dışına kopyalar ve saklama süresi dolmuş eski yedekleri siler.';

    public function handle(DatabaseBackupService $backups, OffsiteBackupSync $offsite): int
    {
        try {
            $result = $backups->run();
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());
            Log::error('[yedek] '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Yedek alındı: %s (%s)', $result['file'], Number::fileSize($result['size'])));

        if ($result['pruned'] !== []) {
            $this->line(sprintf(
                '%d eski yedek silindi (%d günden eski): %s',
                count($result['pruned']),
                $backups->retentionDays(),
                implode(', ', $result['pruned']),
            ));
        }

        return $this->pushOffsite($offsite);
    }

    /**
     * Sunucu dışı kopya başarısız olursa komut hata koduyla çıkar: yedek
     * yalnızca sunucunun kendi diskinde duruyorsa gerçek bir yedek değildir ve
     * bunun sessizce geçmesi, yedeğe ihtiyaç duyulan gün fark edilmesi demektir.
     */
    private function pushOffsite(OffsiteBackupSync $offsite): int
    {
        if ($this->option('sunucu-disi-atla')) {
            return self::SUCCESS;
        }

        if (! $offsite->isConfigured()) {
            $this->warn('Sunucu dışı kopya yapılandırılmadı; yedek yalnızca bu sunucuda duruyor.');

            return self::SUCCESS;
        }

        try {
            $copied = $offsite->push();
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());
            Log::error('[yedek] Sunucu dışı kopya başarısız: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s hedefine %d dosya kopyalandı.',
            $offsite->describe(),
            count($copied),
        ));

        return self::SUCCESS;
    }
}
