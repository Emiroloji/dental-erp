<?php

namespace App\Domain\Organization\Support;

/**
 * SKT'si geçmiş lotun kullanımı (proje.md Bölüm 7): tenant bazında "sadece
 * uyar" veya "kullanımı tamamen engelle". Engelleme yalnızca kullanım
 * çıkışlarını kapsar; imha çıkışları (SKT geçmiş, hasarlı) ve tedarikçiye
 * iade her iki modda da yapılabilir — yoksa süresi geçmiş stok hiç çıkamazdı.
 */
enum ExpiredLotPolicy: string
{
    case Warn = 'warn';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Warn => 'Sadece uyar',
            self::Block => 'Kullanımı tamamen engelle',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Warn => 'SKT\'si geçmiş lottan çıkış yapılabilir; işlem sonrası uyarı gösterilir.',
            self::Block => 'SKT\'si geçmiş lottan kullanım çıkışı yapılamaz; otomatik (FEFO) seçim bu lotları atlar. İmha için "SKT geçmiş" veya "Hasarlı ürün" nedeniyle çıkış yapılır.',
        };
    }
}
