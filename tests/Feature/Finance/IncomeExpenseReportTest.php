<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Reports\IncomeExpenseReportService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    private IncomeExpenseReportService $reports;

    private Organization $organization;

    private User $actor;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private Branch $branchA;

    private FinanceCategory $revenue;

    private FinanceCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinClock('2026-10-14 09:00:00');

        $this->reports = app(IncomeExpenseReportService::class);
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->branchA = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-09-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
        $commission = FinanceCategory::create(['name' => 'Banka/POS Komisyonu', 'direction' => CategoryDirection::Expense]);

        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 500_000, $this->day('2026-10-02'), $this->revenue, $this->actor);
        $ledger->expense($this->cash, 200_000, $this->day('2026-10-03'), $this->rent, $this->actor);
        $toReverse = $ledger->expense($this->cash, 100_000, $this->day('2026-10-04'), $material, $this->actor);
        $ledger->transfer($this->cash, $this->bank, 300_000, $this->day('2026-10-05'), $this->actor, null, 5_000, $commission);

        $this->pinClock('2026-10-06 09:00:00');
        $ledger->reverse($toReverse, 'Yanlış kayıt', $this->actor);
        $this->pinClock('2026-10-14 09:00:00');

        $payable = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Payable, 'amount' => 400_000, 'due_on' => $this->day('2026-10-30'),
            'category' => $material, 'description' => 'Fatura',
        ]);
        $ledger->pay($payable, $this->cash, 150_000, $this->day('2026-10-07'), $this->actor);

        $receivable = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Receivable, 'amount' => 100_000, 'due_on' => $this->day('2026-10-30'),
            'description' => 'Alacak',
        ]);
        $ledger->pay($receivable, $this->cash, 70_000, $this->day('2026-10-08'), $this->actor);

        $preview = app(ReconciliationService::class)->preview($this->cash, $this->day('2026-10-09'), 0);
        app(ReconciliationService::class)->reconcile($this->cash, $this->day('2026-10-09'), $preview['system_balance'] - 2_000, $this->actor, true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pinClock(string $at): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse($at, 'Europe/Istanbul'));
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function filters(string $from = '2026-10-01', string $to = '2026-10-31', array $extra = []): FinanceReportFilters
    {
        return FinanceReportFilters::fromArray(
            ['from' => $from, 'to' => $to, ...$extra],
            $this->day('2026-10-01'),
            $this->day('2026-10-31'),
        );
    }

    public function test_report_buckets_categories_and_months(): void
    {
        $report = $this->reports->report($this->filters());

        $this->assertSame([
            ['category' => 'Hasılat', 'total' => 500_000],
            ['category' => 'Kategorisiz', 'total' => 70_000],
        ], $report['income']);
        $this->assertSame(570_000, $report['income_total']);
        $this->assertSame([
            ['category' => 'Kira', 'total' => 200_000],
            ['category' => 'Malzeme', 'total' => 150_000],
            ['category' => 'Banka/POS Komisyonu', 'total' => 5_000],
        ], $report['expense']);
        $this->assertSame(355_000, $report['expense_total']);
        $this->assertSame(215_000, $report['net']);
        $this->assertSame([['month' => 'Ekim 2026', 'income' => 570_000, 'expense' => 355_000, 'net' => 215_000]], $report['months']);
    }

    public function test_multi_month_period_lists_empty_months(): void
    {
        $report = $this->reports->report($this->filters('2026-09-01'));

        $this->assertSame([
            ['month' => 'Eylül 2026', 'income' => 0, 'expense' => 0, 'net' => 0],
            ['month' => 'Ekim 2026', 'income' => 570_000, 'expense' => 355_000, 'net' => 215_000],
        ], $report['months']);
    }

    public function test_branch_filter_narrows_rows(): void
    {
        app(LedgerService::class)->income($this->cash, 30_000, $this->day('2026-10-10'), $this->revenue, $this->actor, null, $this->branchA);

        $report = $this->reports->report($this->filters(extra: ['branch_id' => $this->branchA->id]));

        $this->assertSame([['category' => 'Hasılat', 'total' => 30_000]], $report['income']);
        $this->assertSame(30_000, $report['net']);
        $this->assertSame([], $report['expense']);
    }

    public function test_account_filter_narrows_rows(): void
    {
        $report = $this->reports->report($this->filters(extra: ['account_id' => $this->bank->id]));

        $this->assertSame([], $report['income']);
        $this->assertSame([['category' => 'Banka/POS Komisyonu', 'total' => 5_000]], $report['expense']);
    }

    public function test_other_organization_entries_are_excluded(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherUser = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherUser);
        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
        $category = FinanceCategory::create(['name' => 'Yabancı', 'direction' => CategoryDirection::Income]);
        app(LedgerService::class)->income($account, 999_000, $this->day('2026-10-02'), $category, $otherUser);

        $this->actingAs($this->actor);
        $report = $this->reports->report($this->filters());

        $this->assertSame(570_000, $report['income_total']);
        $this->assertNotContains('Yabancı', array_column($report['income'], 'category'));
    }

    public function test_table_lists_categories_then_months_with_one_total_row(): void
    {
        $table = $this->reports->table($this->filters());

        $this->assertSame(['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net'], $table['headings']);
        $this->assertSame(['Gelir', 'Hasılat', 500_000, '', ''], $table['rows'][0]);
        $this->assertSame(['Gider', 'Kira', '', 200_000, ''], $table['rows'][2]);

        $this->assertSame(['Gider', 'Banka/POS Komisyonu', '', 5_000, ''], $table['rows'][4]);
        $this->assertSame(['', '', '', '', ''], $table['rows'][5]);
        $this->assertSame(['Ay', 'Ekim 2026', 570_000, 355_000, 215_000], $table['rows'][6]);
        $this->assertCount(7, $table['rows']);
        $this->assertSame(['Toplam', '', 570_000, 355_000, 215_000], $table['totals']);
    }

    public function test_table_lists_every_month_of_a_multi_month_period(): void
    {
        $table = $this->reports->table($this->filters('2026-09-01'));

        $this->assertSame(['Ay', 'Eylül 2026', 0, 0, 0], $table['rows'][6]);
        $this->assertSame(['Ay', 'Ekim 2026', 570_000, 355_000, 215_000], $table['rows'][7]);
    }

    public function test_describe_names_the_category(): void
    {
        $this->assertStringContainsString('Kategori: Hasılat', $this->filters(extra: ['category_id' => (string) $this->revenue->id])->describe());
    }

    public function test_category_filter_limits_report_to_that_category(): void
    {
        $report = $this->reports->report($this->filters(extra: ['category_id' => $this->rent->id]));

        $this->assertSame([], $report['income']);
        $this->assertSame(0, $report['income_total']);
        $this->assertSame([['category' => 'Kira', 'total' => 200_000]], $report['expense']);
        $this->assertSame(200_000, $report['expense_total']);
        $this->assertSame([['month' => 'Ekim 2026', 'income' => 0, 'expense' => 200_000, 'net' => -200_000]], $report['months']);
    }

    public function test_category_filter_covers_payments_and_reversals(): void
    {
        // Malzeme: 100.000 gider + onun iptali (net 0) ve yükümlülük kategorisiyle yazılan 150.000 ödeme.
        $material = FinanceCategory::where('name', 'Malzeme')->firstOrFail();

        $report = $this->reports->report($this->filters(extra: ['category_id' => $material->id]));

        $this->assertSame([['category' => 'Malzeme', 'total' => 150_000]], $report['expense']);
        $this->assertSame(0, $report['income_total']);
    }

    public function test_category_filter_on_income_category_shows_only_income(): void
    {
        $report = $this->reports->report($this->filters(extra: ['category_id' => $this->revenue->id]));

        $this->assertSame([['category' => 'Hasılat', 'total' => 500_000]], $report['income']);
        $this->assertSame([], $report['expense']);
        $this->assertSame(500_000, $report['net']);
    }

    public function test_same_name_income_and_expense_categories_stay_in_their_own_buckets(): void
    {
        $otherIncome = FinanceCategory::create(['name' => 'Diğer', 'direction' => CategoryDirection::Income]);
        $otherExpense = FinanceCategory::create(['name' => 'Diğer', 'direction' => CategoryDirection::Expense]);
        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 7_000, $this->day('2026-10-10'), $otherIncome, $this->actor);
        $ledger->expense($this->cash, 3_000, $this->day('2026-10-10'), $otherExpense, $this->actor);

        $report = $this->reports->report($this->filters());

        $this->assertContains(['category' => 'Diğer', 'total' => 7_000], $report['income']);
        $this->assertContains(['category' => 'Diğer', 'total' => 3_000], $report['expense']);
    }
}
