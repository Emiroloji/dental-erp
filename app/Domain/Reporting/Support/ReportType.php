<?php

namespace App\Domain\Reporting\Support;

/**
 * Kuyruk üzerinden dışa aktarılabilen raporlar (proje.md Bölüm 11).
 */
enum ReportType: string
{
    case Stock = 'stock';
    case Movements = 'movements';
    case Usage = 'usage';
    case Purchasing = 'purchasing';
    case Controlled = 'controlled';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stok Durumu Raporu',
            self::Movements => 'Stok Hareket Raporu',
            self::Usage => 'Kullanım ve Maliyet Raporu',
            self::Purchasing => 'Satın Alma ve İade Raporu',
            self::Controlled => 'Kontrollü Ürün Defteri',
        };
    }

    public function fileBaseName(): string
    {
        return match ($this) {
            self::Stock => 'stok-raporu',
            self::Movements => 'stok-hareket-raporu',
            self::Usage => 'kullanim-maliyet-raporu',
            self::Purchasing => 'satin-alma-iade-raporu',
            self::Controlled => 'kontrollu-urun-defteri',
        };
    }
}
