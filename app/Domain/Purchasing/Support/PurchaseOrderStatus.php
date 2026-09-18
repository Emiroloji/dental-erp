<?php

namespace App\Domain\Purchasing\Support;

/**
 * proje.md Bölüm 9: Taslak / Onay Bekliyor / Onaylandı / Sipariş Verildi /
 * Kısmi Teslim / Tamamlandı / İptal; akış şemasındaki "Reddedildi → Kapatıldı".
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::PendingApproval => 'Onay Bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::Ordered => 'Sipariş Verildi',
            self::PartiallyReceived => 'Kısmi Teslim',
            self::Completed => 'Tamamlandı',
            self::Cancelled => 'İptal',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-line text-ink-muted',
            self::PendingApproval => 'bg-status-warn-bg text-status-warn',
            self::Approved, self::Ordered, self::PartiallyReceived => 'bg-brand-100 text-brand-600',
            self::Completed => 'bg-status-good-bg text-status-good',
            self::Rejected, self::Cancelled => 'bg-line text-ink-muted',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Completed, self::Cancelled],
            // Kısmi teslimden sonra iptal yok: teslim alınan stok geri alınmaz;
            // kalan miktar gelmeyecekse sipariş "kalanı kapatılarak" tamamlanır.
            self::PartiallyReceived => [self::PartiallyReceived, self::Completed],
            self::Rejected, self::Completed, self::Cancelled => [],
        }, true);
    }

    /**
     * Tedarikçiden teslimat beklenen ("açık") siparişler.
     */
    public function isOpen(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }

    public function canReceive(): bool
    {
        return $this->isOpen();
    }

    /**
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::Ordered, self::PartiallyReceived];
    }
}
