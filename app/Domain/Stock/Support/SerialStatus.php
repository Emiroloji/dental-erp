<?php

namespace App\Domain\Stock\Support;

enum SerialStatus: string
{
    case InStock = 'in_stock';
    case InTransit = 'in_transit';
    case Out = 'out';
    case Lost = 'lost';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Stokta',
            self::InTransit => 'Transferde',
            self::Out => 'Çıktı',
            self::Lost => 'Sayımda bulunamadı',
            self::Void => 'Giriş iptal edildi',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::InStock => 'bg-status-good-bg text-status-good',
            self::InTransit => 'bg-status-warn-bg text-status-warn',
            self::Out => 'bg-line text-ink-muted',
            self::Lost, self::Void => 'bg-status-critical-bg text-status-critical',
        };
    }
}
