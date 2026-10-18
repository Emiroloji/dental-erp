<?php

namespace App\Domain\Uts\Jobs;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Services\UtsPendingScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Bir organizasyonun ÜTS kabul bekleyenlerini tarar. ÜTS hatası işi
 * düşürmez; sonraki turda yeniden denenir.
 */
class ScanUtsPendingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(UtsPendingScanService $scanner): void
    {
        $connection = UtsConnection::withoutGlobalScopes()->find($this->connectionId);

        if (! $connection) {
            return;
        }

        try {
            $scanner->scan($connection);
        } catch (UtsException $e) {
            Log::warning('ÜTS kabul bekleyen taraması yapılamadı', ['organization_id' => $connection->organization_id, 'error' => $e->getMessage()]);
        }
    }
}
