<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\RecurringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Zamanlayıcı tetikler, iş kuyrukta çalışır (mimari.md Bölüm 8; bkz. ScanStockLevelsJob).
 */
class GenerateRecurringObligationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RecurringService $recurrings): void
    {
        $recurrings->generateDue();
    }
}
