<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public static function parsable(): array
    {
        return [
            'Türkçe binlik ve kuruş' => ['1.250,50', 125050],
            'yalnızca virgül' => ['1250,5', 125050],
            'tam sayı' => ['1250', 125000],
            'binlik nokta' => ['12.500', 1250000],
            'ondalık nokta' => ['12.5', 1250],
            'iki haneli ondalık nokta' => ['99.99', 9999],
            'para simgesi ve boşluk' => [' 1.000,00 ₺ ', 100000],
            'TL eki' => ['300 TL', 30000],
            'sıfır' => ['0', 0],
            'eksi' => ['-150,25', -15025],
            'tipografik eksi' => ['−150', -15000],
        ];
    }

    #[DataProvider('parsable')]
    public function test_parses_turkish_amounts_into_kurus(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public static function invalid(): array
    {
        return [[''], ['abc'], ['1,234,5'], ['12,345'], ['1.2.3'], ['99999999999999']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($input);
    }

    public function test_formats_kurus_in_turkish(): void
    {
        $this->assertSame('1.250,50 ₺', Money::format(125050));
        $this->assertSame('0,05 ₺', Money::format(5));
        $this->assertSame('−1.250,00 ₺', Money::format(-125000));
        $this->assertSame('1.234.567,89 ₺', Money::format(123456789));
    }

    public function test_input_value_round_trips(): void
    {
        $this->assertSame('1250,50', Money::input(125050));
        $this->assertSame(125050, Money::parse(Money::input(125050)));
    }

    public function test_today_is_istanbul_date_even_when_utc_is_yesterday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'));

        $this->assertSame('2026-10-15', FinanceCalendar::today()->toDateString());

        CarbonImmutable::setTestNow();
    }
}
