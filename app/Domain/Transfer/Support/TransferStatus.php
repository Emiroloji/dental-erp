<?php

namespace App\Domain\Transfer\Support;

/**
 * proje.md Bölüm 8 durum diyagramı:
 * Bekliyor → Onaylandı | Reddedildi | İptal
 * Onaylandı → Hazırlanıyor → Gönderildi → Teslim Alındı
 * kurallar.md Bölüm 1: teslim alınmadan önce her aşamada iptal edilebilir.
 */
enum TransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::Preparing => 'Hazırlanıyor',
            self::Shipped => 'Gönderildi',
            self::Received => 'Teslim Alındı',
            self::Cancelled => 'İptal Edildi',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-status-warn-bg text-status-warn',
            self::Approved, self::Preparing, self::Shipped => 'bg-brand-100 text-brand-600',
            self::Received => 'bg-status-good-bg text-status-good',
            self::Rejected, self::Cancelled => 'bg-line text-ink-muted',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Received, self::Cancelled],
            self::Received, self::Rejected, self::Cancelled => [],
        }, true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Received, self::Rejected, self::Cancelled], true);
    }
}
