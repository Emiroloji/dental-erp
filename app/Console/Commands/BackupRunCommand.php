<?php

namespace App\Console\Commands;

use App\Domain\Platform\Exceptions\BackupException;
use App\Domain\Platform\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupRunCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:run';

    /**
     * @var string
     */
    protected $description = 'Veritabanının sıkıştırılmış yedeğini alır ve saklama süresi dolmuş eski yedekleri siler.';

    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $result = $backups->run();
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

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

        return self::SUCCESS;
    }
}
