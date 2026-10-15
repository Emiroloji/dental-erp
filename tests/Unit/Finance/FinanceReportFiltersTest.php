<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Support\ObligationDirection;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FinanceReportFiltersTest extends TestCase
{
    private function make(array $input): FinanceReportFilters
    {
        return FinanceReportFilters::fromArray($input, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    }

    public function test_valid_input_is_used(): void
    {
        $f = $this->make(['from' => '2026-09-05', 'to' => '2026-10-10', 'branch_id' => '3', 'account_id' => 4, 'direction' => 'payable', 'days' => '60']);

        $this->assertSame('2026-09-05', $f->from->toDateString());
        $this->assertSame('2026-10-10', $f->to->toDateString());
        $this->assertSame(3, $f->branchId);
        $this->assertSame(4, $f->accountId);
        $this->assertSame(ObligationDirection::Payable, $f->direction);
        $this->assertSame(60, $f->days);
    }

    public function test_invalid_dates_fall_back_to_defaults(): void
    {
        foreach ([['2026-02-30', '2026-10-10'], ['abc', '2026-10-10'], ['2026-10-20', '2026-10-10'], ['2026-1-5', '2026-10-10'], ['2023-01-01', '2026-10-10']] as [$from, $to]) {
            $f = $this->make(['from' => $from, 'to' => $to]);
            $this->assertSame('2026-10-01', $f->from->toDateString(), "from: {$from}");
            $this->assertSame('2026-10-31', $f->to->toDateString());
        }
    }

    public function test_days_only_accepts_30_60_90_and_defaults_to_90(): void
    {
        $this->assertSame(90, $this->make([])->days);
        $this->assertSame(90, $this->make(['days' => '45'])->days);
        $this->assertSame(30, $this->make(['days' => 30])->days);
    }

    public function test_unknown_direction_and_ids_become_null(): void
    {
        $f = $this->make(['direction' => 'x', 'branch_id' => 'abc', 'account_id' => '']);

        $this->assertNull($f->direction);
        $this->assertNull($f->branchId);
        $this->assertNull($f->accountId);
    }

    public function test_to_array_round_trips(): void
    {
        $f = $this->make(['from' => '2026-09-05', 'to' => '2026-10-10', 'branch_id' => 3, 'direction' => 'receivable', 'days' => 30]);

        $this->assertEquals($f, $this->make($f->toArray()));
    }

    public function test_category_id_round_trips_and_rejects_garbage(): void
    {
        $from = CarbonImmutable::parse('2026-10-01');
        $to = CarbonImmutable::parse('2026-10-31');

        $filters = FinanceReportFilters::fromArray(['category_id' => '7'], $from, $to);
        $this->assertSame(7, $filters->categoryId);
        $this->assertSame(7, $filters->toArray()['category_id']);
        $this->assertSame(7, FinanceReportFilters::fromArray($filters->toArray(), $from, $to)->categoryId);

        $this->assertNull(FinanceReportFilters::fromArray(['category_id' => 'abc'], $from, $to)->categoryId);
        $this->assertNull(FinanceReportFilters::fromArray(['category_id' => '-3'], $from, $to)->categoryId);
        $this->assertNull(FinanceReportFilters::fromArray([], $from, $to)->categoryId);
    }
}
