<?php

namespace App\Domain\Import\Support;

/**
 * Dosyanın bir satırı: ham metin değerleri, importer'ın hazırladığı yazılacak
 * değerler ve satır hataları.
 */
final class ImportRow
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @param  array<string, string>  $raw  başlık => hücre metni
     */
    public function __construct(
        public readonly int $line,
        public readonly array $raw,
    ) {}

    public function fail(string $column, string $message): void
    {
        $this->errors[] = "{$column}: {$message}";
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
