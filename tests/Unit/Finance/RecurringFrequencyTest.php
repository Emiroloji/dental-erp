<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Support\RecurringFrequency;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RecurringFrequencyTest extends TestCase
{
    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_monthly_keeps_day_and_clamps_short_months(): void
    {
        $day = RecurringFrequency::Monthly->dayFor($this->d('2026-01-31'));

        $this->assertSame(31, $day);
        $this->assertSame('2026-02-28', RecurringFrequency::Monthly->next($this->d('2026-01-31'), $day)->toDateString());
        $this->assertSame('2026-03-31', RecurringFrequency::Monthly->next($this->d('2026-02-28'), $day)->toDateString());
        $this->assertSame('2028-02-29', RecurringFrequency::Monthly->next($this->d('2028-01-31'), $day)->toDateString());
        $this->assertSame('2026-11-05', RecurringFrequency::Monthly->next($this->d('2026-10-05'), 5)->toDateString());
    }

    public function test_weekly_adds_seven_days(): void
    {
        $this->assertSame(3, RecurringFrequency::Weekly->dayFor($this->d('2026-10-14')));
        $this->assertSame('2026-10-21', RecurringFrequency::Weekly->next($this->d('2026-10-14'), 3)->toDateString());
    }

    public function test_yearly_clamps_leap_day_and_returns(): void
    {
        $day = RecurringFrequency::Yearly->dayFor($this->d('2028-02-29'));

        $this->assertSame('2029-02-28', RecurringFrequency::Yearly->next($this->d('2028-02-29'), $day)->toDateString());
        $this->assertSame('2032-02-29', RecurringFrequency::Yearly->next($this->d('2031-02-28'), $day)->toDateString());
    }

    public function test_period_labels_are_turkish(): void
    {
        $this->assertSame('Kasım 2026', RecurringFrequency::Monthly->periodLabel($this->d('2026-11-05')));
        $this->assertSame('14.10.2026', RecurringFrequency::Weekly->periodLabel($this->d('2026-10-14')));
        $this->assertSame('2027', RecurringFrequency::Yearly->periodLabel($this->d('2027-03-01')));
    }
}
