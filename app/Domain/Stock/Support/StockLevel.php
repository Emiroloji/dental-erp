<?php

namespace App\Domain\Stock\Support;

enum StockLevel: string
{
    case Normal = 'normal';
    case Low = 'low';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Low => 'Düşük',
            self::Critical => 'Kritik',
        };
    }

    public function colorLabel(): string
    {
        return match ($this) {
            self::Normal => 'Yeşil',
            self::Low => 'Sarı',
            self::Critical => 'Kırmızı',
        };
    }

    /**
     * app.css'te uyarı semantiği için ayrılmış status-* token'larını kullanan
     * Tailwind sınıfları — dekoratif değil, yalnızca stok seviyesi anlamı için.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Normal => 'bg-status-good-bg text-status-good',
            self::Low => 'bg-status-warn-bg text-status-warn',
            self::Critical => 'bg-status-critical-bg text-status-critical',
        };
    }
}
