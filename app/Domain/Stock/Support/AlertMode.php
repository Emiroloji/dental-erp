<?php

namespace App\Domain\Stock\Support;

/**
 * Bir ürünün uyarı eşiğinin hangi eksende ölçüldüğü (fazlar-adimlar.md Aşama 29.1).
 * Ürün kartında seçilmezse miktar bazlı sabit varsayılan geçerli olur.
 */
enum AlertMode: string
{
    case Quantity = 'quantity';
    case Days = 'days';

    public function label(): string
    {
        return match ($this) {
            self::Quantity => 'Miktar bazlı',
            self::Days => 'Gün bazlı (SKT)',
        };
    }

    /** Eşik değerinin birimi — ekranlarda eşik alanlarının yanında gösterilir. */
    public function unitLabel(): string
    {
        return match ($this) {
            self::Quantity => 'adet',
            self::Days => 'gün',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Quantity => 'Kalan stok adedine göre uyarır.',
            self::Days => 'Son kullanma tarihine kalan gün sayısına göre uyarır.',
        };
    }
}
