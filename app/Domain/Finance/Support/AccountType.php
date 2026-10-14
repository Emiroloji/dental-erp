<?php

namespace App\Domain\Finance\Support;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Pos = 'pos';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kasa',
            self::Bank => 'Banka',
            self::Pos => 'POS',
        };
    }
}
