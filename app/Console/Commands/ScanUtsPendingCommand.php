<?php

namespace App\Console\Commands;

use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Uts\Jobs\ScanUtsPendingJob;
use App\Domain\Uts\Models\UtsConnection;
use Illuminate\Console\Command;

class ScanUtsPendingCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'uts:scan-pending';

    /**
     * @var string
     */
    protected $description = 'ÜTS bağlantısı olan aktif organizasyonların kabul bekleyen bildirimlerini taramak için kuyruğa iş atar.';

    public function handle(): int
    {
        $connections = UtsConnection::withoutGlobalScopes()
            ->whereHas('organization', fn ($query) => $query->where('status', OrganizationStatus::Active->value))
            ->pluck('id');

        $connections->each(fn (int $id) => ScanUtsPendingJob::dispatch($id));

        $this->info("{$connections->count()} organizasyon için ÜTS taraması kuyruğa alındı.");

        return self::SUCCESS;
    }
}
