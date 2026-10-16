<?php

namespace App\Domain\Import\Support;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Excel/CSV hücre metnini uygulamanın beklediği biçime çevirir. Türk
 * kullanıcıların yazdığı biçimler (1.250,50 — 31.03.2027 — Evet/Hayır)
 * ve Excel'in kendi hücre biçimleri (nokta ondalık, tarih seri sayısı)
 * birlikte kabul edilir.
 */
final class ImportValue
{
    /**
     * Sayıyı nokta ondalıklı metne çevirir. Ayrıştırılamazsa girdiyi aynen
     * döndürür; geçersizliği doğrulamadaki `numeric` kuralı bildirir.
     */
    public static function number(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return $value;
    }

    /**
     * E/Evet/1 → true, boş/H/Hayır/0 → false, diğer → null (geçersiz).
     */
    public static function boolean(string $value): ?bool
    {
        return match (self::key($value)) {
            '', 'h', 'hayır', 'hayir', '0' => false,
            'e', 'evet', '1' => true,
            default => null,
        };
    }

    /**
     * GG.AA.YYYY, YYYY-AA-GG veya Excel tarih seri sayısı → Y-m-d.
     * Boş → '' (tarih girilmemiş), geçersiz → null.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{1,6}$/', $value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((int) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            // Taşan tarih (31.02 → 03.03) biçim karşılaştırmasında yakalanır.
            if ($date !== null && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * Ad/kod karşılaştırma anahtarı: büyük/küçük harf ve baş/son boşluk yok sayılır.
     */
    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
