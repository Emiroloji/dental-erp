<?php

namespace App\Domain\Import\Exports;

use App\Domain\Reporting\Exports\TableExport;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * İndirilebilir şablon: ilk sayfa doldurulacak tablo (başlıklar + örnek satır),
 * ikinci sayfa sütun açıklamaları. Okuyucu yalnızca ilk sayfayı okur.
 */
class ImportTemplateExport implements WithMultipleSheets
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string>>  $examples
     * @param  array<int, array{0: string, 1: string}>  $notes  [sütun, açıklama]
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $examples,
        private readonly array $notes,
    ) {}

    /**
     * @return array<int, TableExport>
     */
    public function sheets(): array
    {
        return [
            new TableExport($this->title, $this->headings, collect($this->examples)),
            new TableExport('Açıklama', ['Sütun', 'Açıklama'], collect($this->notes)),
        ];
    }
}
