<?php

namespace App\Domain\Finance\Support;

enum CategoryDirection: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Gelir',
            self::Expense => 'Gider',
        };
    }
}
