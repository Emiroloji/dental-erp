<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\FinanceDueAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Zamanlayıcı tetikler, iş kuyrukta çalışır (mimari.md Bölüm 8; bkz. ScanStockLevelsJob).
 */
class ScanFinanceDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FinanceDueAlertService $alerts): void
    {
        $alerts->scan();
    }
}
