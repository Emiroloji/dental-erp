<?php

namespace App\Console\Commands;

use App\Domain\Finance\Jobs\GenerateRecurringObligationsJob;
use Illuminate\Console\Command;

class GenerateRecurringObligationsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'finance:generate-recurring';

    /**
     * @var string
     */
    protected $description = 'Tekrarlayan finans kalemlerinden (kira, maaş...) önümüzdeki 35 günün ödeme/tahsilat kayıtlarını üretir.';

    public function handle(): int
    {
        GenerateRecurringObligationsJob::dispatch();

        $this->info('Tekrarlayan kalem üretimi kuyruğa alındı.');

        return self::SUCCESS;
    }
}
