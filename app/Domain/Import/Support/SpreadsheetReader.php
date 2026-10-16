<?php

namespace App\Domain\Import\Support;

use App\Domain\Import\Exceptions\ImportFileException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Şablon dosyasını başlık adlarına göre satırlara çevirir. Hücreler hiçbir
 * zaman formül olarak hesaplanmaz (getValue): `=` ile başlayan metin düz
 * metin olarak okunur. Yalnızca ilk sayfa okunur; "Açıklama" sayfası
 * yok sayılır.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * @param  array<int, string>  $headings  Şablonun sütun başlıkları; hepsi dosyada olmalı
     * @return array<int, array<string, string>> dosyadaki satır no => [başlık => metin]
     */
    public function read(string $path, string $extension, array $headings): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new Xlsx,
            'csv' => (new Csv)->setInputEncoding(Csv::GUESS_ENCODING)->setFallbackEncoding('CP1254'),
            default => throw new ImportFileException('Yalnızca .xlsx ve .csv dosyaları yüklenebilir.'),
        };

        try {
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (Throwable) {
            throw new ImportFileException('Dosya okunamadı. Şablonu indirip onunla doldurduğunuzdan emin olun.');
        }

        $grid = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $this->text($cell->getValue());
            }
            $grid[$row->getRowIndex()] = $cells;
        }

        // array_shift satır numaralarını sıfırlar; anahtarlar dosyadaki satır no kalmalı.
        $header = array_map('trim', $grid[array_key_first($grid)] ?? []);
        unset($grid[array_key_first($grid)]);
        $missing = array_values(array_diff($headings, $header));

        if ($missing !== []) {
            throw new ImportFileException('Şablondaki şu sütunlar dosyada yok: '.implode(', ', $missing).'. Şablonu yeniden indirin.');
        }

        $positions = array_map(fn (string $heading) => array_search($heading, $header, true), $headings);

        $rows = [];
        foreach ($grid as $line => $cells) {
            $values = [];
            foreach ($headings as $i => $heading) {
                $values[$heading] = $cells[$positions[$i]] ?? '';
            }

            if (implode('', $values) === '') {
                continue;
            }

            $rows[$line] = $values;

            if (count($rows) > self::MAX_ROWS) {
                throw new ImportFileException('Bir dosyada en fazla 2.000 satır olabilir. Dosyayı bölerek yükleyin.');
            }
        }

        if ($rows === []) {
            throw new ImportFileException('Dosyada veri satırı yok.');
        }

        return $rows;
    }

    private function text(mixed $value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }
}
