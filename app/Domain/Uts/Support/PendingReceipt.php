<?php

namespace App\Domain\Uts\Support;

/**
 * Kuruma yapılan, henüz kabul edilmemiş bir Verme bildirimi satırı
 * (doküman 3.4.7.3, Tablo 64).
 */
final readonly class PendingReceipt
{
    public function __construct(
        public ?int $senderCode,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public ?string $notificationId,
        public ?string $documentNo,
        public ?string $notifiedAt,
        public ?string $senderName,
        public ?string $brand,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['GKK']) ? (int) $row['GKK'] : null,
            (string) ($row['UNO'] ?? ''),
            self::text($row['LNO'] ?? null),
            self::text($row['SNO'] ?? null),
            isset($row['ADT']) ? (int) $row['ADT'] : null,
            self::text($row['BID'] ?? null),
            self::text($row['BNO'] ?? null),
            self::text($row['BZA'] ?? null),
            self::text($row['GKU'] ?? null),
            self::text($row['MME'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
