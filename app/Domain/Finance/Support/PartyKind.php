<?php

namespace App\Domain\Finance\Support;

enum PartyKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Kurum / Müşteri',
            self::Supplier => 'Tedarikçi',
            self::Other => 'Diğer',
        };
    }
}
