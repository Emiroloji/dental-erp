<?php

namespace App\Domain\Reporting\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Gelişmiş raporların ortak Excel dışa aktarımı: başlıklar + düz satırlar.
 * Her rapor servisi kendi satırlarını dizi olarak hazırlar (bkz. *ReportService::table()).
 *
 * @implements FromCollection<int, array<int, mixed>>
 */
class TableExport implements FromCollection, WithHeadings, WithTitle
{
    /**
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly Collection $rows,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }
}
