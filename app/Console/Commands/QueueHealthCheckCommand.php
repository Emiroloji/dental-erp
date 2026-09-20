<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class QueueHealthCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'queue:health-check';

    /**
     * @var string
     */
    protected $description = 'Bekleyen ve başarısız kuyruk işlerini kontrol eder; sorun varsa log\'a uyarı yazar ve hata koduyla çıkar.';

    public function handle(QueueHealth $health): int
    {
        $result = $health->check();

        if ($result['status'] === 'ok') {
            $this->info(sprintf('Kuyruk sağlıklı (bekleyen: %d, son başarısız: %d).', $result['pending'], $result['failed_recently']));

            return self::SUCCESS;
        }

        foreach ($result['messages'] as $message) {
            $this->warn($message);
            Log::warning('[kuyruk] '.$message);
        }

        return self::FAILURE;
    }
}
