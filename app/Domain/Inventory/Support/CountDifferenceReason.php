<?php

namespace App\Domain\Inventory\Support;

/**
 * proje.md Bölüm 10: fark için neden seçilir (kayıp, hasar, kayıt hatası, diğer).
 */
enum CountDifferenceReason: string
{
    case Loss = 'loss';
    case Damage = 'damage';
    case RecordError = 'record_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Loss => 'Kayıp',
            self::Damage => 'Hasar',
            self::RecordError => 'Kayıt hatası',
            self::Other => 'Diğer',
        };
    }
}
