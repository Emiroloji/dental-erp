<?php

namespace App\Http\Controllers\Reporting;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hazır rapor dosyasını yalnızca isteyen kullanıcı indirir; başkasının (aynı
 * organizasyondan da olsa) dışa aktarımı 404 döner.
 */
class ReportExportDownloadController extends Controller
{
    public function __invoke(int $export): StreamedResponse
    {
        $record = ReportExport::where('user_id', auth()->id())->find($export);

        abort_unless(
            $record && $record->status === ReportExportStatus::Completed && Storage::disk('local')->exists($record->file_path),
            404,
        );

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    }
}
