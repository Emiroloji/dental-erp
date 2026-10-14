<?php

namespace App\Console\Commands;

use App\Domain\Finance\Jobs\ScanFinanceDueJob;
use Illuminate\Console\Command;

class ScanFinanceDueCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'finance:scan-due';

    /**
     * @var string
     */
    protected $description = 'Vadesi 3 gün içinde dolacak veya geçmiş ödeme/tahsilatlar için Admin ve finans yetkili personele bildirim oluşturur.';

    public function handle(): int
    {
        ScanFinanceDueJob::dispatch();

        $this->info('Vade taraması kuyruğa alındı.');

        return self::SUCCESS;
    }
}
