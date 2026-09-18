<?php

namespace App\Domain\Platform\Support;

/**
 * Abonelik paketleri (proje.md Bölüm 12). Paketler yalnızca sayısal limitlerle
 * ayrışır — şube, kullanıcı, depolama; tüm modüller her pakette açıktır
 * (Faz 3 kullanıcı kararı). null = sınırsız.
 */
enum Plan: string
{
    case Starter = 'starter';
    case Professional = 'professional';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Başlangıç',
            self::Professional => 'Profesyonel',
            self::Enterprise => 'Kurumsal',
        };
    }

    public function maxBranches(): ?int
    {
        return match ($this) {
            self::Starter => 1,
            self::Professional => 5,
            self::Enterprise => null,
        };
    }

    public function maxUsers(): ?int
    {
        return match ($this) {
            self::Starter => 5,
            self::Professional => 25,
            self::Enterprise => null,
        };
    }

    /**
     * Kurumsal pakette depolama "Özel"dir: varsayılan sınırsız, Platform
     * Sahibi organizasyona özel bir değer girebilir.
     */
    public function maxStorageMb(): ?int
    {
        return match ($this) {
            self::Starter => 1024,
            self::Professional => 10240,
            self::Enterprise => null,
        };
    }
}
