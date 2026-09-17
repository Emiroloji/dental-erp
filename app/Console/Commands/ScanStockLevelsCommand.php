<?php

namespace App\Console\Commands;

use App\Domain\Stock\Jobs\ScanStockLevelsJob;
use Illuminate\Console\Command;

class ScanStockLevelsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stock:scan-levels';

    /**
     * @var string
     */
    protected $description = 'Kritik stok seviyesi ve son kullanma tarihi (SKT) taraması yapar, düşen ürünler için Admin ve yetkili personele bildirim oluşturur.';

    public function handle(): int
    {
        ScanStockLevelsJob::dispatch();

        $this->info('Stok seviyesi tarama görevi kuyruğa alındı.');

        return self::SUCCESS;
    }
}
