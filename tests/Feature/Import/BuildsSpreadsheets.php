<?php

namespace Tests\Feature\Import;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

trait BuildsSpreadsheets
{
    /**
     * İlk satır başlıklar. Değerler metin olarak yazılır; sayı hücresi gerekiyorsa
     * int/float verilir.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    protected function spreadsheet(array $rows, string $extension = 'xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r + 1]);
                is_string($value)
                    ? $cell->setValueExplicit($value, DataType::TYPE_STRING)
                    : $cell->setValue($value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.'.$extension;
        IOFactory::createWriter($spreadsheet, ucfirst($extension))->save($path);

        return $path;
    }
}
