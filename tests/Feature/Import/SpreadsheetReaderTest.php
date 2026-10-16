<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Exceptions\ImportFileException;
use App\Domain\Import\Support\SpreadsheetReader;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    use BuildsSpreadsheets;

    public function test_reads_rows_keyed_by_heading_and_line_number(): void
    {
        $path = $this->spreadsheet([
            ['Ürün Kodu', 'Miktar', 'Fazla Sütun'],
            ['ENJ-01', 40, 'x'],
            ['', '', ''],
            ['  KMP-02 ', 12.5, ''],
        ]);

        $rows = (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu', 'Miktar']);

        $this->assertSame([
            2 => ['Ürün Kodu' => 'ENJ-01', 'Miktar' => '40'],
            4 => ['Ürün Kodu' => 'KMP-02', 'Miktar' => '12.5'],
        ], $rows);
    }

    public function test_reads_semicolon_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "Ürün Kodu;Miktar\nENJ-01;1.250,50\n");

        $rows = (new SpreadsheetReader)->read($path, 'csv', ['Ürün Kodu', 'Miktar']);

        $this->assertSame([2 => ['Ürün Kodu' => 'ENJ-01', 'Miktar' => '1.250,50']], $rows);
    }

    public function test_formula_text_is_not_evaluated(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu'], ['=1+1']]);

        $rows = (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu']);

        $this->assertSame('=1+1', $rows[2]['Ürün Kodu']);
    }

    public function test_missing_columns_are_rejected(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu'], ['ENJ-01']]);

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Şablondaki şu sütunlar dosyada yok: Miktar');

        (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu', 'Miktar']);
    }

    public function test_file_without_data_rows_is_rejected(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu']]);

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Dosyada veri satırı yok.');

        (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu']);
    }

    public function test_row_limit_is_enforced(): void
    {
        $rows = [['Ürün Kodu']];
        for ($i = 0; $i <= SpreadsheetReader::MAX_ROWS; $i++) {
            $rows[] = ["K-{$i}"];
        }
        $path = $this->spreadsheet($rows, 'csv');

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('en fazla 2.000 satır');

        (new SpreadsheetReader)->read($path, 'csv', ['Ürün Kodu']);
    }
}
