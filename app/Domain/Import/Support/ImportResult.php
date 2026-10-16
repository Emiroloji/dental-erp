<?php

namespace App\Domain\Import\Support;

/**
 * Bir dosyanın doğrulama sonucu. Tek satır bile hatalıysa dosya içe
 * aktarılmaz (hepsi ya da hiçbiri).
 */
final class ImportResult
{
    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<string, array<int, string>>  $creates  içe aktarmada oluşturulacak kayıtlar (ör. 'Kategori' => ['Dolgu'])
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $creates = [],
    ) {}

    public function isValid(): bool
    {
        return $this->rows !== [] && $this->errorCount() === 0;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** Hatalı satır sayısı. */
    public function errorCount(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row) => ! $row->isValid()));
    }

    /**
     * Ekranda tablo olarak gösterilen hatalar, satır sırasıyla.
     *
     * @return array<int, array{line: int, column: string, message: string}>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->rows as $row) {
            foreach ($row->errors as $error) {
                $errors[] = ['line' => $row->line, ...$error];
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string> "Satır 14 · Ürün Kodu: ..." biçiminde
     */
    public function errorMessages(): array
    {
        return array_map(fn (array $error) => "Satır {$error['line']} · {$error['column']}: {$error['message']}", $this->errors());
    }
}
