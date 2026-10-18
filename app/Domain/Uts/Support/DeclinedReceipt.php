<?php

namespace App\Domain\Uts\Support;

/**
 * Kurumun "Almak istemiyorum" olarak işaretlediği Verme bildirimi satırı
 * (rev. 1.99, 3.4.25.3, Tablo 99).
 */
final readonly class DeclinedReceipt
{
    /** Kurumun kendi işaretlediği; yalnızca bunlar "Almak istiyorum" ile geri alınır. */
    public const STATUS_DECLINED_BY_US = 'ALMAK_ISTEMIYORUM';

    public function __construct(
        public ?int $senderCode,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public ?string $notificationId,
        public ?string $institutionName,
        public ?string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['GKK']) && is_numeric($row['GKK']) ? (int) $row['GKK'] : null,
            (string) ($row['UNO'] ?? ''),
            self::text($row['LNO'] ?? null),
            self::text($row['SNO'] ?? null),
            isset($row['ADT']) ? (int) $row['ADT'] : null,
            self::text($row['BID'] ?? null),
            self::text($row['DKU'] ?? null),
            self::text($row['AID'] ?? null),
        );
    }

    public function canUndo(): bool
    {
        return $this->notificationId !== null && ($this->status === null || $this->status === self::STATUS_DECLINED_BY_US);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
