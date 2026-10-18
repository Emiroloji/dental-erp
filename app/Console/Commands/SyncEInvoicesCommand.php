<?php

namespace App\Console\Commands;

use App\Domain\EInvoice\Jobs\SyncEInvoicesJob;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\Organization\Support\OrganizationStatus;
use Illuminate\Console\Command;

class SyncEInvoicesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'einvoice:sync';

    /**
     * @var string
     */
    protected $description = 'e-Fatura bağlantısı olan aktif organizasyonların gelen faturalarını eşitlemek için kuyruğa iş atar.';

    public function handle(): int
    {
        $connections = EInvoiceConnection::withoutGlobalScopes()
            ->whereHas('organization', fn ($query) => $query->where('status', OrganizationStatus::Active->value))
            ->pluck('id');

        $connections->each(fn (int $id) => SyncEInvoicesJob::dispatch($id));

        $this->info("{$connections->count()} organizasyon için e-Fatura eşitlemesi kuyruğa alındı.");

        return self::SUCCESS;
    }
}
