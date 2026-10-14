<?php

namespace App\Domain\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * Uygulama UTC çalışır; finans tarihleri ise kliniğin takvim günüdür. Türkiye'de
 * 00:00–03:00 arasında UTC hâlâ dündür — "bugün" UTC'den alınsaydı bugünün
 * tarihli hareketi "ileri tarihli" diye reddedilirdi.
 */
final class FinanceCalendar
{
    public const TIMEZONE = 'Europe/Istanbul';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
