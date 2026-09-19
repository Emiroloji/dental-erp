<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * mimari.md Bölüm 6: rapor üretimi arayüzü kilitlemez, kuyrukta çalışır.
 * İş mantığı ReportExportService'te.
 */
class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportExportService $exports): void
    {
        $exports->generate(ReportExport::withoutGlobalScopes()->findOrFail($this->exportId));
    }

    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::withoutGlobalScopes()->find($this->exportId);

        if ($export) {
            app(ReportExportService::class)->markFailed($export, $exception);
        }
    }
}
