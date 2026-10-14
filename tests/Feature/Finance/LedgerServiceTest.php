<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private FinanceBalanceService $balances;

    private Organization $organization;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private FinanceCategory $revenue;

    private FinanceCategory $rent;

    private FinanceCategory $commission;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $this->ledger = app(LedgerService::class);
        $this->balances = app(FinanceBalanceService::class);

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $this->commission = FinanceCategory::create(['name' => 'Banka/POS Komisyonu', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_income_and_expense_change_the_balance(): void
    {
        $this->ledger->income($this->cash, 250_000, $this->day('2026-10-10'), $this->revenue, $this->actor, 'Günlük hasılat');
        $this->ledger->expense($this->cash, 100_000, $this->day('2026-10-11'), $this->rent, $this->actor);

        $this->assertSame(1_150_000, $this->balances->balance($this->cash));
        $this->assertSame(1_250_000, $this->balances->balance($this->cash, $this->day('2026-10-10')));
    }

    public function test_income_records_all_fields(): void
    {
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);

        $entry = $this->ledger->income($this->cash, 5_000, $this->day('2026-10-14'), $this->revenue, $this->actor, 'Not', $branch, 'F-1');

        $this->assertSame(FinanceEntryType::Income, $entry->type);
        $this->assertSame(1, $entry->direction);
        $this->assertSame($this->organization->id, $entry->organization_id);
        $this->assertSame($branch->id, $entry->branch_id);
        $this->assertSame('F-1', $entry->document_no);
        $this->assertSame($this->actor->id, $entry->actor_id);
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->income($this->cash, 0, $this->day('2026-10-14'), $this->revenue, $this->actor);
    }

    public function test_category_direction_must_match(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gelir kategorisi seçin');

        $this->ledger->income($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_future_date_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('İleri tarihli');

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-15'), $this->rent, $this->actor);
    }

    public function test_date_before_opening_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('açılış tarihinden önce');

        $this->ledger->expense($this->cash, 100, $this->day('2026-09-30'), $this->rent, $this->actor);
    }

    public function test_inactive_account_or_category_is_rejected(): void
    {
        $this->cash->update(['status' => 'passive']);

        $this->expectException(FinanceRuleException::class);

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_inactive_category_is_rejected(): void
    {
        $this->rent->update(['status' => 'passive']);

        $this->expectException(FinanceRuleException::class);

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_negative_balance_is_allowed(): void
    {
        $this->ledger->expense($this->bank, 50_000, $this->day('2026-10-14'), $this->rent, $this->actor);

        $this->assertSame(-50_000, $this->balances->balance($this->bank));
    }

    public function test_balances_and_total(): void
    {
        $this->ledger->income($this->bank, 30_000, $this->day('2026-10-14'), $this->revenue, $this->actor);
        $passive = FinanceAccount::create(['name' => 'Eski', 'type' => AccountType::Cash, 'opening_balance' => 99_999, 'opening_date' => '2026-01-01', 'status' => 'passive']);

        $this->assertSame([$this->cash->id => 1_000_000, $this->bank->id => 30_000], $this->balances->balances([$this->cash, $this->bank]));
        $this->assertSame(1_030_000, $this->balances->total());
        $this->assertSame(99_999, $this->balances->balance($passive));
    }

    public function test_entries_are_written_only_inside_one_transaction_per_call(): void
    {
        $this->ledger->income($this->cash, 1, $this->day('2026-10-14'), $this->revenue, $this->actor);

        $this->assertSame(1, FinanceEntry::count());
    }

    public function test_transfer_moves_money_between_accounts_atomically(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 300_000, $this->day('2026-10-14'), $this->actor, 'Bankaya yatırıldı');

        $this->assertCount(2, $entries);
        $this->assertSame(700_000, $this->balances->balance($this->cash));
        $this->assertSame(300_000, $this->balances->balance($this->bank));
        $this->assertSame(1, $entries->pluck('transfer_group')->unique()->count());
        $this->assertNotNull($entries->first()->transfer_group);
    }

    public function test_transfer_with_commission_records_expense_on_target(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, 'POS günsonu', 2_500, $this->commission);

        $this->assertCount(3, $entries);
        $this->assertSame(97_500, $this->balances->balance($this->bank));
        $fee = $entries->firstWhere('type', FinanceEntryType::Expense);
        $this->assertSame($this->bank->id, $fee->account_id);
        $this->assertSame($this->commission->id, $fee->category_id);
    }

    public function test_commission_requires_expense_category(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, null, 2_500);
    }

    public function test_transfer_to_same_account_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->transfer($this->cash, $this->cash, 100, $this->day('2026-10-14'), $this->actor);
    }

    public function test_reverse_restores_balance_and_is_dated_today(): void
    {
        $expense = $this->ledger->expense($this->cash, 100_000, $this->day('2026-10-02'), $this->rent, $this->actor);

        $reversals = $this->ledger->reverse($expense, 'Yanlış tutar', $this->actor);

        $this->assertSame(1_000_000, $this->balances->balance($this->cash));
        $reversal = $reversals->first();
        $this->assertSame(FinanceEntryType::Reversal, $reversal->type);
        $this->assertSame(1, $reversal->direction);
        $this->assertSame($expense->id, $reversal->reverses_entry_id);
        $this->assertSame($this->rent->id, $reversal->category_id);
        $this->assertSame('2026-10-14', $reversal->occurred_on->toDateString());
        $this->assertStringContainsString('Yanlış tutar', $reversal->description);
        $this->assertSame(900_000, $this->balances->balance($this->cash, $this->day('2026-10-13')));
    }

    public function test_reversing_one_leg_reverses_the_whole_transfer(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, null, 2_500, $this->commission);

        $reversals = $this->ledger->reverse($entries->last(), 'Yanlış hesap', $this->actor);

        $this->assertCount(3, $reversals);
        $this->assertSame(1_000_000, $this->balances->balance($this->cash));
        $this->assertSame(0, $this->balances->balance($this->bank));
    }

    public function test_entry_cannot_be_reversed_twice(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
        $this->ledger->reverse($expense, 'İlk', $this->actor);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('zaten iptal');

        $this->ledger->reverse($expense->fresh(), 'İkinci', $this->actor);
    }

    public function test_reversal_itself_cannot_be_reversed(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
        $reversal = $this->ledger->reverse($expense, 'İlk', $this->actor)->first();

        $this->expectException(FinanceRuleException::class);

        $this->ledger->reverse($reversal, 'Geri al', $this->actor);
    }

    public function test_reverse_requires_reason(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gerekçe');

        $this->ledger->reverse($expense, '   ', $this->actor);
    }
}
