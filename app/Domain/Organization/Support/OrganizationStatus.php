<?php

namespace App\Domain\Organization\Support;

/**
 * Organizasyon (tenant) durumu. Salt-okunur ve pasif, yalnızca Platform
 * Sahibi'nin manuel kararıdır — ödeme gecikmesi gibi otomatik bir geçiş yoktur
 * (proje.md Bölüm 12, Faz 3 kullanıcı kararı).
 *
 * - Aktif: tam erişim.
 * - Salt-okunur: kullanıcılar giriş yapar ve her şeyi görür, hiçbir kayıt ekleyemez/değiştiremez.
 * - Pasif: tüm kullanıcılar erişimi kaybeder (proje.md Bölüm 4); veri silinmez.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Passive = 'passive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::ReadOnly => 'Salt-okunur',
            self::Passive => 'Pasif',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-status-good-bg text-status-good',
            self::ReadOnly => 'bg-status-warn-bg text-status-warn',
            self::Passive => 'bg-line text-ink-muted',
        };
    }

    public function allowsLogin(): bool
    {
        return $this !== self::Passive;
    }

    public function allowsWrites(): bool
    {
        return $this === self::Active;
    }
}
