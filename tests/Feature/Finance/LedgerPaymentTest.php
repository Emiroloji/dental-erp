<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private FinanceBalanceService $balances;

    private FinanceAccount $bank;

    private FinanceParty $supplier;

    private FinanceCategory $material;

    private FinanceObligation $invoice;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->ledger = app(LedgerService::class);
        $this->balances = app(FinanceBalanceService::class);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 5_000_000, 'opening_date' => '2026-10-01']);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $this->material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
        $this->invoice = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Payable, 'amount' => 1_500_000, 'due_on' => CarbonImmutable::parse('2026-10-30'),
            'party' => $this->supplier, 'category' => $this->material, 'description' => 'Fatura F-77',
        ]);
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

    public function test_partial_then_full_payment_moves_status_and_balance(): void
    {
        $first = $this->ledger->pay($this->invoice, $this->bank, 500_000, $this->day('2026-10-14'), $this->actor);

        $this->assertSame(FinanceEntryType::Payment, $first->type);
        $this->assertSame(-1, $first->direction);
        $this->assertSame($this->supplier->id, $first->party_id);
        $this->assertSame($this->material->id, $first->category_id);
        $this->assertSame('Fatura F-77', $first->description);
        $this->assertSame('Ödeme', $first->label());
        $this->assertSame(ObligationStatus::Partial, $this->invoice->fresh()->status);
        $this->assertSame(4_500_000, $this->balances->balance($this->bank));

        $this->ledger->pay($this->invoice->fresh(), $this->bank, 1_000_000, $this->day('2026-10-14'), $this->actor, 'Kalan');

        $this->assertSame(ObligationStatus::Paid, $this->invoice->fresh()->status);
        $this->assertSame(0, $this->balances->remaining($this->invoice->fresh()));
        $this->assertSame(3_500_000, $this->balances->balance($this->bank));
    }

    public function test_receivable_payment_increases_balance(): void
    {
        $customer = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $claim = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Receivable, 'amount' => 200_000, 'due_on' => $this->day('2026-10-20'),
            'party' => $customer, 'description' => 'Ekim',
        ]);

        $entry = $this->ledger->pay($claim, $this->bank, 200_000, $this->day('2026-10-14'), $this->actor);

        $this->assertSame(1, $entry->direction);
        $this->assertSame('Tahsilat', $entry->label());
        $this->assertSame(5_200_000, $this->balances->balance($this->bank));
        $this->assertSame(ObligationStatus::Paid, $claim->fresh()->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('Kalan tutardan');

        $this->ledger->pay($this->invoice, $this->bank, 1_500_001, $this->day('2026-10-14'), $this->actor);
    }

    public function test_paid_or_cancelled_obligation_cannot_be_paid(): void
    {
        $this->ledger->pay($this->invoice, $this->bank, 1_500_000, $this->day('2026-10-14'), $this->actor);

        try {
            $this->ledger->pay($this->invoice->fresh(), $this->bank, 1, $this->day('2026-10-14'), $this->actor);
            $this->fail('Kapanmış kayda ödeme reddedilmeliydi.');
        } catch (FinanceRuleException) {
        }

        $other = app(ObligationService::class)->create(['direction' => ObligationDirection::Payable, 'amount' => 10, 'due_on' => $this->day('2026-10-30'), 'description' => 'x']);
        app(ObligationService::class)->cancel($other, 'yanlış');

        $this->expectException(FinanceRuleException::class);
        $this->ledger->pay($other->fresh(), $this->bank, 1, $this->day('2026-10-14'), $this->actor);
    }

    public function test_payment_follows_ledger_rules(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('İleri tarihli');

        $this->ledger->pay($this->invoice, $this->bank, 100, $this->day('2026-10-15'), $this->actor);
    }

    public function test_reversing_a_payment_reopens_the_obligation(): void
    {
        $this->ledger->pay($this->invoice, $this->bank, 500_000, $this->day('2026-10-14'), $this->actor);
        $second = $this->ledger->pay($this->invoice->fresh(), $this->bank, 1_000_000, $this->day('2026-10-14'), $this->actor);

        $reversal = $this->ledger->reverse($second, 'Yanlış hesap', $this->actor)->first();

        $this->assertSame($this->invoice->id, $reversal->obligation_id);
        $this->assertSame($this->supplier->id, $reversal->party_id);
        $this->assertSame(ObligationStatus::Partial, $this->invoice->fresh()->status);
        $this->assertSame(1_000_000, $this->balances->remaining($this->invoice->fresh()));
        $this->assertSame(4_500_000, $this->balances->balance($this->bank));
    }

    public function test_reversing_a_payment_nets_the_paid_amount_down(): void
    {
        $this->ledger->pay($this->invoice, $this->bank, 500_000, $this->day('2026-10-14'), $this->actor);
        $second = $this->ledger->pay($this->invoice->fresh(), $this->bank, 1_000_000, $this->day('2026-10-14'), $this->actor);

        $this->assertSame(1_500_000, $this->balances->paid($this->invoice->fresh()));

        $this->ledger->reverse($second, 'Yanlış hesap', $this->actor);

        $this->assertSame(500_000, $this->balances->paid($this->invoice->fresh()));
    }

    public function test_income_and_expense_can_carry_a_party(): void
    {
        $entry = $this->ledger->expense($this->bank, 100, $this->day('2026-10-14'), $this->material, $this->actor, 'Peşin alım', null, null, $this->supplier);

        $this->assertSame($this->supplier->id, $entry->party_id);
    }

    public function test_records_from_another_organization_are_rejected(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreignCategory = FinanceCategory::create(['organization_id' => $otherOrganization->id, 'name' => 'Yabancı', 'direction' => CategoryDirection::Expense]);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('aynı organizasyon');

        $this->ledger->expense($this->bank, 100, $this->day('2026-10-14'), $foreignCategory, $this->actor);
    }
}
