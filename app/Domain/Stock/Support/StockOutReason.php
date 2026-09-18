<?php

namespace App\Domain\Stock\Support;

enum StockOutReason: string
{
    case ClinicalUse = 'clinical_use';
    case Consumption = 'consumption';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case ReturnToSupplier = 'return_to_supplier';
    case Transfer = 'transfer';
    case Other = 'other';

    /**
     * "Kullanım", gerçek klinik tüketimidir (proje.md Bölüm 11). Hasar, SKT
     * imhası, iade ve transfer stoğu azaltır ama kullanım değildir; bunlar
     * "Diğer Çıkışlar" olarak ayrı raporlanır.
     *
     * @return array<int, self>
     */
    public static function usageReasons(): array
    {
        return [self::ClinicalUse, self::Consumption];
    }

    /**
     * Stok Çıkışı formunda seçilebilen nedenler. Transfer elle çıkış olarak
     * girilemez — stok düşer ama hiçbir depoya varmazdı; transfer, Transferler
     * akışıyla yapılır. (Transfer değeri eski kayıtlar için enum'da kalır.)
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $reason) => $reason !== self::Transfer));
    }

    public function label(): string
    {
        return match ($this) {
            self::ClinicalUse => 'Klinik içi kullanım',
            self::Consumption => 'Sarf',
            self::Damaged => 'Hasarlı ürün',
            self::Expired => 'SKT geçmiş',
            self::ReturnToSupplier => 'İade',
            self::Transfer => 'Şubeler/depolar arası transfer',
            self::Other => 'Diğer',
        };
    }
}
