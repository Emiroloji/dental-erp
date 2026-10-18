<?php

namespace App\Domain\EInvoice\Support;

use App\Domain\EInvoice\Exceptions\EInvoiceException;

/**
 * UBL tutarı → kuruş. UBL'de nokta her zaman ondalık ayırıcıdır ve üç ya da
 * daha fazla ondalık olabilir ("190.000" = 190 TL); bu yüzden Finance\Money
 * (Türkçe giriş biçimi) kullanılamaz. Kuruşa yarım yukarı yuvarlanır.
 */
final class UblAmount
{
    public static function toKurus(string $value): int
    {
        $value = trim($value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new EInvoiceException("Faturada geçersiz tutar: {$value}");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $kurus = (int) $m[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$kurus : $kurus;
    }
}
