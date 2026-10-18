<?php

namespace App\Domain\EInvoice\Jobs;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Bir organizasyonun gelen e-faturalarını eşitler. Aynı bağlantı için kuyrukta
 * tek iş bulunur. Entegratör hatası işi düşürmez; sonraki turda yeniden denenir.
 */
class SyncEInvoicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(EInvoiceSyncService $sync): void
    {
        $connection = EInvoiceConnection::withoutGlobalScopes()->find($this->connectionId);

        if (! $connection) {
            return;
        }

        try {
            $sync->sync($connection);
        } catch (EInvoiceException $e) {
            Log::warning('e-Fatura eşitlemesi yapılamadı', ['organization_id' => $connection->organization_id, 'error' => $e->getMessage()]);
        }
    }
}
