<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceReconciliation;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReconciliationService $reconciliations;

    private LedgerService $ledger;

    private FinanceBalanceService $balances;

    private Organization $organization;

    private FinanceAccount $cash;

    private FinanceCategory $revenue;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $now = CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $this->reconciliations = app(ReconciliationService::class);
        $this->ledger = app(LedgerService::class);
        $this->balances = app(FinanceBalanceService::class);

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);

        $this->ledger->income($this->cash, 250_000, $this->day('2026-10-10'), $this->revenue, $this->actor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_matching_statement_records_zero_difference_without_entry(): void
    {
        $preview = $this->reconciliations->preview($this->cash, $this->day('2026-10-10'), 1_250_000);
        $this->assertSame(['system_balance' => 1_250_000, 'difference' => 0], $preview);

        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-10'), 1_250_000, $this->actor, true);

        $this->assertSame(0, $record->difference);
        $this->assertSame(1_250_000, $record->system_balance);
        $this->assertSame(1_250_000, $record->statement_balance);
        $this->assertNull($record->adjustment_entry_id);
        $this->assertSame(1, FinanceEntry::count());
    }

    public function test_adjustment_description_is_cut_to_the_column_length(): void
    {
        // PostgreSQL varchar(500) taşmayı hata sayar; SQLite saymaz, bu yüzden uzunluk açıkça sınanır.
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, true, str_repeat('Ş', 600));

        $description = FinanceEntry::findOrFail($record->adjustment_entry_id)->description;
        $this->assertLessThanOrEqual(500, mb_strlen($description));
        $this->assertStringStartsWith('Mutabakat:', $description);
    }

    public function test_difference_is_closed_with_an_adjustment_entry_dated_at_the_statement(): void
    {
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, true, 'Banka masrafı');

        $this->assertSame(1_250_000, $record->system_balance);
        $this->assertSame(-5_000, $record->difference);

        $entry = FinanceEntry::findOrFail($record->adjustment_entry_id);
        $this->assertSame(FinanceEntryType::Adjustment, $entry->type);
        $this->assertSame(-1, $entry->direction);
        $this->assertSame(5_000, $entry->amount);
        $this->assertSame('2026-10-12', $entry->occurred_on->toDateString());
        $this->assertNull($entry->category_id);
        $this->assertStringContainsString('Mutabakat', $entry->description);
        $this->assertStringContainsString('Banka masrafı', $entry->description);
        $this->assertSame(1_245_000, $this->balances->balance($this->cash, $this->day('2026-10-12')));
        $this->assertSame($this->actor->id, $record->actor_id);
    }

    public function test_positive_difference_writes_an_incoming_adjustment(): void
    {
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_251_000, $this->actor, true);

        $entry = FinanceEntry::findOrFail($record->adjustment_entry_id);
        $this->assertSame(1, $entry->direction);
        $this->assertSame(1_000, $entry->amount);
        $this->assertSame(1_251_000, $this->balances->balance($this->cash));
    }

    public function test_difference_can_be_recorded_without_adjusting(): void
    {
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, false);

        $this->assertSame(-5_000, $record->difference);
        $this->assertNull($record->adjustment_entry_id);
        $this->assertSame(1, FinanceEntry::count());
        $this->assertSame(1_250_000, $this->balances->balance($this->cash));
    }

    public function test_future_or_pre_opening_statement_date_is_rejected(): void
    {
        foreach (['2026-10-15', '2026-09-30'] as $date) {
            try {
                $this->reconciliations->reconcile($this->cash, $this->day($date), 1_000_000, $this->actor, true);
                $this->fail("{$date} reddedilmeliydi.");
            } catch (FinanceRuleException $e) {
                $this->assertSame('date', $e->field);
            }
        }

        $this->assertSame(0, FinanceReconciliation::count());
    }

    public function test_passive_account_is_rejected_on_account_field(): void
    {
        $this->cash->update(['status' => 'passive']);

        try {
            $this->reconciliations->reconcile($this->cash->fresh(), $this->day('2026-10-12'), 1_000_000, $this->actor, true);
            $this->fail('Pasif hesap reddedilmeliydi.');
        } catch (FinanceRuleException $e) {
            $this->assertSame('account', $e->field);
        }
    }

    public function test_second_reconcile_with_same_statement_finds_no_difference(): void
    {
        $first = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, true);
        $second = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, true);

        $this->assertSame(-5_000, $first->difference);
        $this->assertSame(0, $second->difference);
        $this->assertNull($second->adjustment_entry_id);
        $this->assertSame(2, FinanceEntry::count());
    }

    public function test_long_note_is_truncated_to_500_characters(): void
    {
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, false, str_repeat('ş', 600));

        $this->assertSame(500, mb_strlen($record->note));
    }

    public function test_zero_adjustment_is_rejected_by_the_ledger(): void
    {
        try {
            $this->ledger->adjust($this->cash, 0, $this->day('2026-10-12'), $this->actor, 'Mutabakat');
            $this->fail('Sıfır düzeltme reddedilmeliydi.');
        } catch (FinanceRuleException $e) {
            $this->assertSame('amount', $e->field);
        }
    }

    public function test_adjustment_can_be_reversed_like_any_entry(): void
    {
        $record = $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, true);

        $this->ledger->reverse($record->adjustment, 'Yanlış ekstre', $this->actor);

        $this->assertSame(1_250_000, $this->balances->balance($this->cash));
    }

    public function test_reconciliations_are_isolated_per_organization(): void
    {
        $this->reconciliations->reconcile($this->cash, $this->day('2026-10-12'), 1_245_000, $this->actor, false);
        $this->assertSame(1, FinanceReconciliation::count());

        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, FinanceReconciliation::count());
    }
}
