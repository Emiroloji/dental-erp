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
     * akışıyla yapılır. Tedarikçiye iade de elle çıkış olamaz — iade kaydı,
     * durum takibi ve kredi notu atlanırdı; iade, İadeler akışıyla yapılır.
     * (Bu değerler eski kayıtlar ve ilgili akışlar için enum'da kalır.)
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason) => ! in_array($reason, [self::Transfer, self::ReturnToSupplier], true),
        ));
    }

    /**
     * İmha niteliğindeki çıkışlar: "kullanımı engelle" ayarında da SKT'si
     * geçmiş lottan yapılabilir (ExpiredLotPolicy).
     */
    public function disposesStock(): bool
    {
        return in_array($this, [self::Expired, self::Damaged, self::ReturnToSupplier], true);
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
