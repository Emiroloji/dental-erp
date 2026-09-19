<?php

namespace App\Domain\Forecasting\Support;

enum ForecastTrend: string
{
    case Up = 'up';
    case Flat = 'flat';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Artıyor',
            self::Flat => 'Sabit',
            self::Down => 'Azalıyor',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Up => '↑',
            self::Flat => '→',
            self::Down => '↓',
        };
    }
}
