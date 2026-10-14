<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Reports\CashFlowReportService;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowReportService $reports;

    private User $actor;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinClock('2026-09-20 09:00:00');

        $this->reports = app(CashFlowReportService::class);
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 100_000, 'opening_date' => '2026-09-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);

        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 50_000, $this->day('2026-09-05'), $revenue, $this->actor);
        $septExpense = $ledger->expense($this->cash, 20_000, $this->day('2026-09-06'), $rent, $this->actor);
        $ledger->transfer($this->cash, $this->bank, 30_000, $this->day('2026-09-07'), $this->actor);

        $this->pinClock('2026-10-14 09:00:00');
        $ledger->expense($this->cash, 10_000, $this->day('2026-10-03'), $material, $this->actor);
        $ledger->reverse($septExpense, 'Yanlış kayıt', $this->actor);
        $ledger->adjust($this->cash, -1_000, $this->day('2026-10-09'), $this->actor, 'Sayım farkı');
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

    private function filters(array $extra = []): FinanceReportFilters
    {
        return FinanceReportFilters::fromArray(
            ['from' => '2026-09-01', 'to' => '2026-10-31', ...$extra],
            $this->day('2026-09-01'),
            $this->day('2026-10-31'),
        );
    }

    public function test_actual_lists_each_month_without_transfers_and_derives_closing(): void
    {
        $this->assertSame([
            ['month' => 'Eylül 2026', 'in' => 50_000, 'out' => 20_000, 'net' => 30_000, 'closing' => 130_000],
            ['month' => 'Ekim 2026', 'in' => 20_000, 'out' => 11_000, 'net' => 9_000, 'closing' => 139_000],
        ], $this->reports->actual($this->filters()));
    }

    public function test_account_filter_counts_that_accounts_transfer_legs(): void
    {
        $months = $this->reports->actual($this->filters(['account_id' => $this->bank->id]));

        $this->assertSame(30_000, $months[0]['in']);
        $this->assertSame(0, $months[0]['out']);
        $this->assertSame(30_000, $months[0]['closing']);
        $this->assertSame(30_000, $months[1]['closing']);
    }

    public function test_closing_includes_inactive_accounts_and_accounts_opened_later(): void
    {
        $this->bank->update(['status' => 'inactive']);
        FinanceAccount::create(['name' => 'Yeni', 'type' => AccountType::Cash, 'opening_balance' => 5_000, 'opening_date' => '2026-10-02']);

        $months = $this->reports->actual($this->filters());

        $this->assertSame(130_000, $months[0]['closing']);
        $this->assertSame(144_000, $months[1]['closing']);
    }

    public function test_table_has_headings_rows_and_totals(): void
    {
        $table = $this->reports->table($this->filters());

        $this->assertSame(['Ay', 'Giriş', 'Çıkış', 'Net', 'Dönem sonu nakit'], $table['headings']);
        $this->assertSame(['Ekim 2026', 20_000, 11_000, 9_000, 139_000], $table['rows'][1]);
        $this->assertSame(['Toplam', 70_000, 31_000, 39_000, 139_000], $table['totals']);
    }
}
