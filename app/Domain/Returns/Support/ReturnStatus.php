<?php

namespace App\Domain\Returns\Support;

/**
 * proje.md Bölüm 10: Talep Edildi / Onaylandı / Kargoya Verildi / Tedarikçi
 * Onayladı / Tamamlandı / Reddedildi. Reddedildi iki yerden gelir: Admin
 * talebi kargoya verilmeden reddeder (stok etkisi yok) ya da tedarikçi kargoya
 * verilmiş iadeyi kabul etmez (ürün geri gelir, düşülen stok geri eklenir).
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case SupplierApproved = 'supplier_approved';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Talep Edildi',
            self::Approved => 'Onaylandı',
            self::Shipped => 'Kargoya Verildi',
            self::SupplierApproved => 'Tedarikçi Onayladı',
            self::Completed => 'Tamamlandı',
            self::Rejected => 'Reddedildi',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Requested => 'bg-status-warn-bg text-status-warn',
            self::Approved, self::Shipped, self::SupplierApproved => 'bg-brand-100 text-brand-600',
            self::Completed => 'bg-status-good-bg text-status-good',
            self::Rejected => 'bg-line text-ink-muted',
        };
    }

    /**
     * Henüz sonuçlanmamış (takipte olan) iadeler.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::Requested, self::Approved, self::Shipped, self::SupplierApproved];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Requested => [self::Approved, self::Rejected],
            self::Approved => [self::Shipped, self::Rejected],
            self::Shipped => [self::SupplierApproved, self::Rejected],
            self::SupplierApproved => [self::Completed],
            self::Completed, self::Rejected => [],
        }, true);
    }
}
