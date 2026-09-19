<?php

namespace App\Domain\Forecasting\Support;

/**
 * Stoğun tahmini yetme süresine göre risk sınıfı. Sıralama değeri (order)
 * ekranda en acil ürünlerin üstte görünmesi içindir.
 */
enum ForecastRisk: string
{
    case OutOfStock = 'out_of_stock';
    case Critical = 'critical';
    case Soon = 'soon';
    case Sufficient = 'sufficient';
    case NoUsage = 'no_usage';

    public function label(): string
    {
        return match ($this) {
            self::OutOfStock => 'Tükendi',
            self::Critical => 'Kritik',
            self::Soon => 'Yakında',
            self::Sufficient => 'Yeterli',
            self::NoUsage => 'Tüketim yok',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::OutOfStock => 0,
            self::Critical => 1,
            self::Soon => 2,
            self::Sufficient => 3,
            self::NoUsage => 4,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::OutOfStock, self::Critical => 'bg-status-critical-bg text-status-critical',
            self::Soon => 'bg-status-warn-bg text-status-warn',
            self::Sufficient => 'bg-status-good-bg text-status-good',
            self::NoUsage => 'bg-line text-ink-muted',
        };
    }
}
