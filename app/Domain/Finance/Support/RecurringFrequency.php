<?php

namespace App\Domain\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * Tekrarlayan kalemin sıklığı. "day" tanım anında ilk vade tarihinden alınır
 * ve saklanır: 31'inde ödenen bir kalem Şubat'ta 28'ine kayar, Mart'ta yeniden
 * 31'ine döner (ayın son gününe sabitlenip kalmaz).
 */
enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    private const MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Haftalık',
            self::Monthly => 'Aylık',
            self::Yearly => 'Yıllık',
        };
    }

    /**
     * Haftalıkta ISO hafta günü (1 = Pazartesi), aylık ve yıllıkta ayın günü.
     */
    public function dayFor(CarbonImmutable $startsOn): int
    {
        return $this === self::Weekly ? $startsOn->dayOfWeekIso : $startsOn->day;
    }

    public function next(CarbonImmutable $from, int $day): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $from->addWeek(),
            self::Monthly => $this->onDay($from->startOfMonth()->addMonthNoOverflow(), $day),
            self::Yearly => $this->onDay($from->startOfMonth()->addYearNoOverflow(), $day),
        };
    }

    public function periodLabel(CarbonImmutable $dueOn): string
    {
        return match ($this) {
            self::Weekly => $dueOn->format('d.m.Y'),
            self::Monthly => self::MONTHS[$dueOn->month].' '.$dueOn->year,
            self::Yearly => (string) $dueOn->year,
        };
    }

    private function onDay(CarbonImmutable $monthStart, int $day): CarbonImmutable
    {
        return $monthStart->setDay(min($day, $monthStart->daysInMonth));
    }
}
