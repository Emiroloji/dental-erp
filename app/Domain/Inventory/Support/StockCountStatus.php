<?php

namespace App\Domain\Inventory\Support;

/**
 * proje.md Bölüm 10: sayım başlatılır → girilir → Admin onaylar. Admin sayımı
 * yeniden sayılmak üzere geri gönderebilir; onaylanmadan iptal edilebilir.
 */
enum StockCountStatus: string
{
    case Counting = 'counting';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'Sayılıyor',
            self::PendingApproval => 'Onay Bekliyor',
            self::Approved => 'Onaylandı',
            self::Cancelled => 'İptal',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Counting => 'bg-brand-100 text-brand-600',
            self::PendingApproval => 'bg-status-warn-bg text-status-warn',
            self::Approved => 'bg-status-good-bg text-status-good',
            self::Cancelled => 'bg-line text-ink-muted',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Counting => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Counting, self::Cancelled],
            self::Approved, self::Cancelled => [],
        }, true);
    }
}
