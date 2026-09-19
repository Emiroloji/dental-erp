<?php

namespace App\Domain\Assistant\Support;

/**
 * Asistanın çalıştırabildiği raporlar; her biri mevcut bir rapor ekranına karşılık gelir.
 */
enum AssistantReport: string
{
    case Stock = 'stock';
    case Movements = 'movements';
    case Usage = 'usage';
    case Purchasing = 'purchasing';
    case Forecast = 'forecast';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stok Durumu',
            self::Movements => 'Stok Hareketleri',
            self::Usage => 'Kullanım ve Maliyet',
            self::Purchasing => 'Satın Alma ve İade',
            self::Forecast => 'Tüketim Tahmini',
        };
    }

    /**
     * Yapay zekâya verilen açıklama (hangi soruların bu rapora gideceği).
     */
    public function description(): string
    {
        return match ($this) {
            self::Stock => 'Güncel stok durumu: ürün bazında mevcut miktar, stok değeri ve uyarı seviyesi (kritik/düşük). Tarih almaz. "Ne kadar stok var", "hangi ürünler kritik" gibi sorular.',
            self::Movements => 'Stok hareketleri listesi (giriş, çıkış, transfer, sayım, iade, iptal). movement_type ile daraltılabilir. "Dün ne çıkış yapıldı", "geçen hafta hangi girişler oldu" gibi sorular.',
            self::Usage => 'Dönem içi kullanım (tüketim) ve maliyeti, ürün bazında, en çok kullanılandan aza sıralı. "En çok ne kullandık", "geçen ay eldiven kullanımı ne kadar", "kullanım maliyeti" gibi sorular.',
            self::Purchasing => 'Tedarikçi bazında satın alma, teslim alınan, açık sipariş ve iade tutarları. "Hangi tedarikçiden ne kadar aldık", "açık siparişler" gibi sorular.',
            self::Forecast => 'Tüketim tahmini: günlük tüketim, stoğun kaç gün yeteceği, tahmini tükenme tarihi. risk ile daraltılabilir. "Ne zaman biter", "yakında tükenecek ürünler", "kaç gün yeter" gibi sorular. Tarih almaz.',
        };
    }

    public function usesDates(): bool
    {
        return in_array($this, [self::Movements, self::Usage, self::Purchasing], true);
    }

    public function routeName(): string
    {
        return match ($this) {
            self::Stock => 'reports.stock',
            self::Movements => 'reports.movements',
            self::Usage => 'reports.usage',
            self::Purchasing => 'reports.purchasing',
            self::Forecast => 'reports.forecast',
        };
    }
}
