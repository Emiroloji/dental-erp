<?php

namespace App\Domain\Finance\Support;

enum ObligationStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::Partial => 'Kısmen ödendi',
            self::Paid => 'Kapandı',
            self::Cancelled => 'İptal',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Partial;
    }
}
