<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Reporting\Jobs\GenerateReportExportJob;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Reporting\Support\ReportType;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Kuyruğa alınan rapor dışa aktarımı (mimari.md Bölüm 6). Ekran yalnızca bir
 * kayıt açar ve işi kuyruğa verir; dosya arka planda üretilir, hazır olunca
 * isteyen kullanıcıya uygulama içi bildirim gider.
 *
 * Güvenlik: iş, isteyen kullanıcı adına çalışır — tenant kapsamı
 * (BelongsToOrganization) ve Raporlar modülündeki şube kapsamı ekrandakiyle
 * aynen uygulanır. Kapsam üretim anında yeniden hesaplanır; bu arada yetkisi
 * kaldırılan veya pasife alınan kullanıcının raporu üretilmez.
 */
class ReportExportService
{
    public const FORMATS = ['xlsx', 'pdf'];

    public function __construct(
        private readonly StockReportService $stock,
        private readonly MovementReportService $movements,
        private readonly UsageReportService $usage,
        private readonly PurchaseReportService $purchasing,
        private readonly ReportDownloader $downloader,
    ) {}

    /**
     * @param  array{filters?: array<string, mixed>, search?: ?string, type?: ?string}  $parameters
     */
    public function request(User $user, ReportType $type, string $format, array $parameters): ReportExport
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Desteklenmeyen biçim: {$format}");
        }

        $export = ReportExport::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'report' => $type,
            'format' => $format,
            'parameters' => $parameters,
            'status' => ReportExportStatus::Pending,
        ]);

        GenerateReportExportJob::dispatch($export->id)->afterCommit();

        return $export;
    }

    public function generate(ReportExport $export): void
    {
        if ($export->status !== ReportExportStatus::Pending) {
            return;
        }

        $user = User::find($export->user_id);

        if (! $user?->isActive() || $user->organization_id !== $export->organization_id || ! $user->canModule(Module::Reports, 'read')) {
            $this->markFailed($export, new RuntimeException('Raporu isteyen kullanıcının artık rapor yetkisi yok.'));

            return;
        }

        $previousUser = Auth::user();
        Auth::setUser($user);

        try {
            $content = $this->content($export, $user);
            $fileName = $export->report->fileBaseName().'-'.$export->created_at->format('Ymd-His').'.'.$export->format;
            $path = "report-exports/{$export->organization_id}/{$export->id}-{$fileName}";

            Storage::disk('local')->put($path, $content);

            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_name' => $fileName,
                'file_path' => $path,
                'completed_at' => now(),
            ]);
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        $user->notify(new WorkflowNotification(
            'report',
            'Rapor hazır',
            "{$export->report->label()} ({$this->formatLabel($export->format)}) indirilmeye hazır.",
            route('reports.exports.download', $export),
            WorkflowNotification::LEVEL_GOOD,
        ));
    }

    public function markFailed(ReportExport $export, ?Throwable $exception = null): void
    {
        if ($export->status !== ReportExportStatus::Pending) {
            return;
        }

        $export->update([
            'status' => ReportExportStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);

        $export->user?->notify(new WorkflowNotification(
            'report',
            'Rapor hazırlanamadı',
            "{$export->report->label()} ({$this->formatLabel($export->format)}) oluşturulamadı. Lütfen tekrar deneyin.",
            route('reports.stock'),
            WorkflowNotification::LEVEL_WARN,
        ));
    }

    public function formatLabel(string $format): string
    {
        return $format === 'pdf' ? 'PDF' : 'Excel';
    }

    /**
     * Ekranlarla aynı servis çağrıları; şube kapsamı üretim anındaki yetkiden.
     */
    private function content(ReportExport $export, User $user): string
    {
        $parameters = $export->parameters ?? [];
        $filters = ReportFilters::fromArray($parameters['filters'] ?? []);
        $accessible = $user->accessibleBranchIds(Module::Reports);
        $pdf = $export->format === 'pdf';

        if ($export->report === ReportType::Stock) {
            $stockFilters = [
                'category_id' => $filters->categoryId,
                'supplier_id' => $filters->supplierId,
                'warehouse_id' => $filters->warehouseId,
                'search' => filled($parameters['search'] ?? null) ? (string) $parameters['search'] : null,
            ];
            $branchIds = $filters->branchScope($accessible);

            return $pdf
                ? $this->stock->pdfContent($stockFilters, $branchIds)
                : $this->stock->excelContent($stockFilters, $branchIds);
        }

        $organizationId = $export->organization_id;
        $movementType = in_array($parameters['type'] ?? null, array_column(StockMovementType::cases(), 'value'), true) ? $parameters['type'] : null;

        $table = match ($export->report) {
            ReportType::Movements => $this->movements->table($organizationId, $filters, $accessible, $movementType),
            ReportType::Usage => $this->usage->table($organizationId, $filters, $accessible),
            ReportType::Purchasing => $this->purchasing->table($organizationId, $filters, $accessible),
        };

        return $pdf
            ? $this->downloader->pdfContent($export->report->label(), $table, $filters)
            : $this->downloader->excelContent($export->report->label(), $table);
    }
}
