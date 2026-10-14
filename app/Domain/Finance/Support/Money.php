<?php

namespace App\Domain\Finance\Support;

use InvalidArgumentException;

/**
 * Finans tutarları kuruş cinsinden tam sayı tutulur (karar 1.7): float yuvarlama
 * hatası üretir. Bu sınıf yalnızca ekran ile servis arasındaki çeviriyi yapar;
 * servisler her zaman kuruş (int) alır.
 */
final class Money
{
    private const MAX_LIRA_DIGITS = 13;

    /**
     * "1.250,50", "1250,5", "12.500", "12.5", "300 TL" → kuruş. Virgül varsa
     * ondalık ayırıcıdır ve noktalar binliktir; virgül yoksa yalnızca üçerli
     * gruplanmış noktalar binlik sayılır, tek nokta ondalıktır.
     */
    public static function parse(string $input): int
    {
        $value = str_replace([' ', '₺', 'TL', 'tl'], '', trim($input));
        $value = str_replace('−', '-', $value);

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        if (str_contains($value, ',')) {
            if (! preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $value)) {
                throw new InvalidArgumentException('Geçersiz tutar. Örnek: 1.250,50');
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) || strlen($matches[1]) > self::MAX_LIRA_DIGITS) {
            throw new InvalidArgumentException('Geçersiz tutar. Örnek: 1.250,50');
        }

        $kurus = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');

        return $negative ? -$kurus : $kurus;
    }

    public static function format(int $kurus): string
    {
        return ($kurus < 0 ? '−' : '').self::digits(abs($kurus), '.').' ₺';
    }

    /**
     * Form alanına geri yazılacak sade biçim (binlik ayırıcısız).
     */
    public static function input(int $kurus): string
    {
        return ($kurus < 0 ? '-' : '').self::digits(abs($kurus), '');
    }

    private static function digits(int $kurus, string $thousands): string
    {
        return number_format(intdiv($kurus, 100), 0, ',', $thousands).','.str_pad((string) ($kurus % 100), 2, '0', STR_PAD_LEFT);
    }
}
