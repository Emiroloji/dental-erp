<?php

namespace App\Domain\Platform\Support;

/**
 * Tanıtım sitesinden gelen talebin elle yürütülen takip durumu (Aşama 29.2).
 * Otomatik bir geçiş yoktur; her adımı Platform Sahibi işaretler.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Converted = 'converted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Yeni',
            self::Contacted => 'İletişime Geçildi',
            self::Converted => 'Organizasyon Açıldı',
            self::Closed => 'Kapatıldı',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::New => 'bg-status-warn-bg text-status-warn',
            self::Contacted => 'bg-brand-100 text-brand-600',
            self::Converted => 'bg-status-good-bg text-status-good',
            self::Closed => 'bg-line text-ink-muted',
        };
    }
}
