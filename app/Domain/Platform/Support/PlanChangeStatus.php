<?php

namespace App\Domain\Platform\Support;

enum PlanChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Onay Bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::Cancelled => 'Geri Çekildi',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-status-warn-bg text-status-warn',
            self::Approved => 'bg-status-good-bg text-status-good',
            self::Rejected => 'bg-status-critical-bg text-status-critical',
            self::Cancelled => 'bg-line text-ink-muted',
        };
    }
}
