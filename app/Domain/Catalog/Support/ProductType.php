<?php

namespace App\Domain\Catalog\Support;

enum ProductType: string
{
    case Consumable = 'consumable';
    case Equipment = 'equipment';
    case Medicine = 'medicine';

    public function label(): string
    {
        return match ($this) {
            self::Consumable => 'Sarf Malzeme',
            self::Equipment => 'Ekipman',
            self::Medicine => 'İlaç',
        };
    }
}
