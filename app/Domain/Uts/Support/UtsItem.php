<?php

namespace App\Domain\Uts\Support;

/**
 * Kurum üzerindeki tekil ürün kaydı (doküman 3.4.1.3, Tablo 51).
 */
final readonly class UtsItem
{
    public function __construct(
        public ?string $productType,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public ?string $productionDate,
        public ?string $expiryDate,
        public ?string $importDate,
        public ?int $manufacturerCode,
        public ?string $tracking,
        public ?string $udi,
        public ?string $brand,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $text = fn (string $key): ?string => isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;

        return new self(
            $text('UTP'),
            (string) ($row['UNO'] ?? ''),
            $text('LNO'),
            $text('SNO'),
            isset($row['ADT']) ? (int) $row['ADT'] : null,
            $text('URT'),
            $text('SKT'),
            $text('ITT'),
            isset($row['UIK']) ? (int) $row['UIK'] : null,
            $text('UAK'),
            $text('UDI'),
            $text('MME'),
        );
    }
}
