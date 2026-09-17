<?php

namespace App\Domain\Stock\Jobs;

use App\Domain\Stock\Services\StockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * mimari.md Bölüm 8: kritik stok / SKT taramaları scheduler tarafından tetiklenir
 * ama ağır işlem arka planda kuyrukta çalışır — bu iş sınıfı o kuyruklama katmanı,
 * gerçek iş mantığı StockAlertService'te.
 */
class ScanStockLevelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StockAlertService $alerts): void
    {
        $alerts->scan();
    }
}
