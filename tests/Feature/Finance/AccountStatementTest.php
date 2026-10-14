<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Reports\AccountStatementService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private AccountStatementService $statements;

    private FinanceBalanceService $balances;

    private FinanceAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinClock('2026-10-14 09:00:00');

        $this->statements = app(AccountStatementService::class);
        $this->balances = app(FinanceBalanceService::class);

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $other = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $income = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $expense = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);

        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 250_000, $this->day('2026-10-05'), $income, $actor);
        $toBeReversed = $ledger->expense($this->cash, 100_000, $this->day('2026-10-08'), $expense, $actor);
        $ledger->transfer($this->cash, $other, 300_000, $this->day('2026-10-09'), $actor);

        // İptal "bugün" tarihlenir; 11.10'a alınıp saat geri döndürülür.
        $this->pinClock('2026-10-11 09:00:00');
        $ledger->reverse($toBeReversed, 'Yanlış kayıt', $actor);
        $this->pinClock('2026-10-14 09:00:00');
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

    public function test_period_statement_has_opening_running_balance_and_totals(): void
    {
        $statement = $this->statements->statement($this->cash, $this->day('2026-10-06'), $this->day('2026-10-10'));

        $this->assertSame(1_250_000, $statement['opening']);
        $this->assertCount(2, $statement['rows']);
        $this->assertSame(-100_000, $statement['rows'][0]['entry']->signedAmount());
        $this->assertSame(1_150_000, $statement['rows'][0]['balance']);
        $this->assertSame(-300_000, $statement['rows'][1]['entry']->signedAmount());
        $this->assertSame(850_000, $statement['rows'][1]['balance']);
        $this->assertSame(850_000, $statement['closing']);
        $this->assertSame($this->balances->balance($this->cash, $this->day('2026-10-10')), $statement['closing']);
        $this->assertSame(0, $statement['in']);
        $this->assertSame(400_000, $statement['out']);
    }

    public function test_period_before_opening_date_starts_from_opening_balance_and_ends_at_current_balance(): void
    {
        $statement = $this->statements->statement($this->cash, $this->day('2026-10-01'), $this->day('2026-10-14'));

        $this->assertSame(1_000_000, $statement['opening']);
        $this->assertSame($this->balances->balance($this->cash), $statement['closing']);
        $this->assertSame($statement['opening'] + $statement['in'] - $statement['out'], $statement['closing']);
        $this->assertCount(4, $statement['rows']);
    }

    public function test_table_export_uses_fixed_headings_and_kurus_amounts(): void
    {
        $table = $this->statements->table($this->cash, $this->day('2026-10-06'), $this->day('2026-10-10'));

        $this->assertSame(['Tarih', 'Tür', 'Açıklama', 'Cari', 'Giriş', 'Çıkış', 'Bakiye'], $table['headings']);
        $this->assertCount(2, $table['rows']);
        $this->assertSame('08.10.2026', $table['rows'][0][0]);
        $this->assertSame(0, $table['rows'][0][4]);
        $this->assertSame(100_000, $table['rows'][0][5]);
        $this->assertSame(1_150_000, $table['rows'][0][6]);
        $this->assertSame(['opening' => 1_250_000, 'in' => 0, 'out' => 400_000, 'closing' => 850_000], $table['totals']);
    }
}
