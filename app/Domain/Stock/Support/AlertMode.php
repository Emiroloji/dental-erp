<?php

namespace App\Domain\Stock\Support;

/**
 * Bir ürünün uyarı eşiğinin hangi eksende ölçüldüğü (fazlar-adimlar.md Aşama 29.1).
 *
 * Miktar ve SKT birbirinden bağımsız iki eksendir; ürün ikisinden birine ya da
 * "İkisi birden" ile her ikisine göre izlenebilir. Ürün kartında mod
 * seçilmezse miktar bazlı sabit varsayılan geçerli olur.
 */
enum AlertMode: string
{
    case Quantity = 'quantity';
    case Days = 'days';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Quantity => 'Miktar bazlı',
            self::Days => 'SKT (gün) bazlı',
            self::Both => 'İkisi birden',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Quantity => 'Kalan stok adedine göre uyarır.',
            self::Days => 'Son kullanma tarihine kalan gün sayısına göre uyarır.',
            self::Both => 'Hem kalan adede hem son kullanma tarihine göre uyarır; hangisi önce eşiğe ulaşırsa ürün o seviyeye geçer.',
        };
    }

    /** Ürünün kendi miktar eşikleri (adet) kullanılıyor mu. */
    public function tracksQuantity(): bool
    {
        return $this === self::Quantity || $this === self::Both;
    }

    /** Ürünün kendi SKT eşikleri (gün) kullanılıyor mu. */
    public function tracksExpiry(): bool
    {
        return $this === self::Days || $this === self::Both;
    }
}
