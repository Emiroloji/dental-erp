<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Support\Carbon;

/**
 * GS1 yardımcıları (ÜTS'deki ürünler GS1 GTIN ve DataMatrix kullanır).
 *
 * - GTIN: 8, 12, 13 veya 14 hane; 14 haneye soldan sıfırla tamamlanır ve
 *   kontrol hanesi (mod 10) doğrulanır.
 * - DataMatrix / GS1-128 içeriği: (01) GTIN, (17) SKT, (11) üretim tarihi,
 *   (10) lot, (21) seri no. Hem parantezli insan-okunur biçim
 *   "(01)…(17)…(10)…" hem de okuyucunun gönderdiği ham biçim (FNC1 = ASCII 29,
 *   isteğe bağlı "]d2"/"]C1" öneki) çözülür.
 */
final class Gs1
{
    private const GROUP_SEPARATOR = "\x1D";

    /** Sabit uzunluklu uygulama tanımlayıcıları. */
    private const FIXED = ['01' => 14, '11' => 6, '17' => 6];

    /** Değişken uzunluklu (en fazla 20 karakter) tanımlayıcılar. */
    private const VARIABLE = ['10', '21'];

    public static function normalizeGtin(?string $value): ?string
    {
        $digits = preg_replace('/\s+/', '', (string) $value);

        if (! preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $digits)) {
            return null;
        }

        $gtin = str_pad($digits, 14, '0', STR_PAD_LEFT);

        return self::checkDigit(substr($gtin, 0, 13)) === (int) $gtin[13] ? $gtin : null;
    }

    public static function checkDigit(string $first13): int
    {
        $sum = 0;

        foreach (str_split(strrev($first13)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @return array{gtin: string, lot_no: ?string, expiry_date: ?string, serial: ?string}|null
     */
    public static function parse(string $scanned): ?array
    {
        $code = trim($scanned);
        $code = preg_replace('/^\](d2|C1|Q3|e0)/', '', $code);

        $fields = str_contains($code, '(') ? self::parseParenthesized($code) : self::parseRaw($code);

        $gtin = self::normalizeGtin($fields['01'] ?? null);

        if ($gtin === null) {
            return null;
        }

        return [
            'gtin' => $gtin,
            'lot_no' => $fields['10'] ?? null,
            'expiry_date' => isset($fields['17']) ? self::date($fields['17']) : null,
            'serial' => $fields['21'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseParenthesized(string $code): array
    {
        preg_match_all('/\((\d{2})\)([^(]*)/', $code, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match) => [$match[1] => trim($match[2])])->all();
    }

    /**
     * @return array<string, string>
     */
    private static function parseRaw(string $code): array
    {
        $fields = [];
        $position = 0;
        $length = strlen($code);

        while ($position < $length) {
            if ($code[$position] === self::GROUP_SEPARATOR) {
                $position++;

                continue;
            }

            $ai = substr($code, $position, 2);
            $position += 2;

            if (isset(self::FIXED[$ai])) {
                $fields[$ai] = substr($code, $position, self::FIXED[$ai]);
                $position += self::FIXED[$ai];
            } elseif (in_array($ai, self::VARIABLE, true)) {
                $end = strpos($code, self::GROUP_SEPARATOR, $position);
                $end = $end === false ? $length : $end;
                $fields[$ai] = substr($code, $position, min(20, $end - $position));
                $position = $end;
            } else {
                // Tanınmayan tanımlayıcı: güvenle ayrıştırılamaz, bu noktada durulur.
                break;
            }
        }

        return $fields;
    }

    /**
     * GS1 tarihi YYMMDD; gün "00" ise ayın son günü kabul edilir.
     */
    private static function date(string $value): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $match) || (int) $match[2] < 1 || (int) $match[2] > 12) {
            return null;
        }

        $month = Carbon::createFromDate(2000 + (int) $match[1], (int) $match[2], 1);

        return ((int) $match[3] === 0 ? $month->endOfMonth() : $month->setDay(min((int) $match[3], $month->daysInMonth)))->toDateString();
    }
}
