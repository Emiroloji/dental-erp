<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Exports\TableExport;
use App\Domain\Reporting\Support\ReportFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gelişmiş raporların Excel/PDF dışa aktarımı (proje.md Bölüm 11). Rapor
 * servisleri tablo verisini (başlık + satır + toplam) hazırlar, bu sınıf
 * dosyaya çevirir.
 */
class ReportDownloader
{
    /**
     * @param  array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals?: array<int, mixed>}  $table
     */
    public function excel(string $title, string $fileName, array $table): BinaryFileResponse
    {
        $rows = $table['rows'];

        if (! empty($table['totals'])) {
            $rows = $rows->push($table['totals']);
        }

        return Excel::download(new TableExport($title, $table['headings'], $rows), "{$fileName}.xlsx");
    }

    /**
     * Livewire yalnızca akış (Streamed) veya dosya (BinaryFile) yanıtını indirme
     * olarak gönderebilir; DomPDF'in download() yanıtı düz Response olduğu için
     * PDF akış olarak döndürülür.
     *
     * @param  array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals?: array<int, mixed>}  $table
     */
    public function pdf(string $title, string $fileName, array $table, ReportFilters $filters): StreamedResponse
    {
        $pdf = Pdf::loadView('reports.table-pdf', [
            'title' => $title,
            'filters' => $filters->describe(),
            'headings' => $table['headings'],
            'rows' => $table['rows'],
            'totals' => $table['totals'] ?? [],
        ])->setPaper('a4', 'landscape');

        return self::streamPdf($pdf->output(), "{$fileName}.pdf");
    }

    /**
     * Kuyruktaki dışa aktarım için dosya içeriği (ReportExportService).
     *
     * @param  array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals?: array<int, mixed>}  $table
     */
    public function excelContent(string $title, array $table): string
    {
        $rows = $table['rows'];

        if (! empty($table['totals'])) {
            $rows = $rows->push($table['totals']);
        }

        return Excel::raw(new TableExport($title, $table['headings'], $rows), ExcelFormat::XLSX);
    }

    /**
     * @param  array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals?: array<int, mixed>}  $table
     */
    public function pdfContent(string $title, array $table, ReportFilters $filters): string
    {
        return Pdf::loadView('reports.table-pdf', [
            'title' => $title,
            'filters' => $filters->describe(),
            'headings' => $table['headings'],
            'rows' => $table['rows'],
            'totals' => $table['totals'] ?? [],
        ])->setPaper('a4', 'landscape')->output();
    }

    public static function streamPdf(string $content, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $fileName, ['Content-Type' => 'application/pdf']);
    }
}
