<?php

namespace App\Domain\Reporting\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Gelişmiş raporların ortak Excel dışa aktarımı: başlıklar + düz satırlar.
 * Her rapor servisi kendi satırlarını dizi olarak hazırlar (bkz. *ReportService::table()).
 *
 * @implements FromCollection<int, array<int, mixed>>
 */
class TableExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithTitle
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

    /**
     * Formül enjeksiyonu koruması: `=`, `+`, `-`, `@` ile başlayan metin (cari adı,
     * açıklama) formül değil, düz metin hücre olarak yazılır. Sayılar sayı kalır.
     */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

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
