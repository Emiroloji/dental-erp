<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancePartyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancePartyService $parties;

    private FinanceBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $this->parties = app(FinancePartyService::class);
        $this->balances = app(FinanceBalanceService::class);
    }

    public function test_party_is_created_trimmed_and_can_be_deactivated_and_activated(): void
    {
        $party = $this->parties->create(['name' => '  ABC Sigorta ', 'kind' => PartyKind::Customer, 'email' => ' info@abc.test ', 'default_term_days' => 30]);

        $this->assertSame('ABC Sigorta', $party->name);
        $this->assertSame('info@abc.test', $party->email);
        $this->assertSame(30, $party->default_term_days);
        $this->assertFalse($this->parties->deactivate($party)->fresh()->isActive());
        $this->assertTrue($this->parties->activate($party)->fresh()->isActive());
    }

    public function test_duplicate_name_is_rejected_and_passive_duplicate_explains_reactivation(): void
    {
        $party = $this->parties->create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $this->parties->deactivate($party);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('aktifleştirin');

        $this->parties->create(['name' => 'abc sigorta', 'kind' => PartyKind::Customer]);
    }

    public function test_blank_name_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->parties->create(['name' => '   ', 'kind' => PartyKind::Other]);
    }

    public function test_update_changes_contact_fields(): void
    {
        $party = $this->parties->create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        $this->parties->update($party, ['name' => 'Depo Ltd.', 'kind' => PartyKind::Supplier, 'phone' => '0212 000 00 00', 'default_term_days' => null]);

        $this->assertSame('Depo Ltd.', $party->fresh()->name);
        $this->assertSame('0212 000 00 00', $party->fresh()->phone);
    }

    public function test_paid_remaining_and_party_balances_are_derived_from_the_ledger(): void
    {
        $customer = $this->parties->create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $supplier = $this->parties->create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        $receivable = FinanceObligation::create(['direction' => ObligationDirection::Receivable, 'party_id' => $customer->id, 'amount' => 500_000, 'due_on' => '2026-10-20', 'description' => 'Ekim faturası']);
        $payable = FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $supplier->id, 'amount' => 300_000, 'due_on' => '2026-10-20', 'description' => 'F-1']);
        FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $supplier->id, 'amount' => 999, 'due_on' => '2026-10-20', 'description' => 'iptal', 'status' => ObligationStatus::Cancelled]);

        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => 1, 'amount' => 200_000, 'occurred_on' => '2026-10-14', 'obligation_id' => $receivable->id, 'party_id' => $customer->id]);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 100_000, 'occurred_on' => '2026-10-14', 'obligation_id' => $payable->id, 'party_id' => $supplier->id]);

        $this->assertSame(200_000, $this->balances->paid($receivable));
        $this->assertSame(300_000, $this->balances->remaining($receivable));
        $this->assertSame(100_000, $this->balances->paid($payable));
        $this->assertSame([$receivable->id => 200_000, $payable->id => 100_000], $this->balances->paidAmounts([$receivable, $payable]));

        $result = $this->balances->partyBalances([$customer, $supplier]);
        $this->assertSame(['receivable' => 300_000, 'payable' => 0, 'net' => 300_000], $result[$customer->id]);
        $this->assertSame(['receivable' => 0, 'payable' => 200_000, 'net' => -200_000], $result[$supplier->id]);
    }

    public function test_cancelled_obligation_has_nothing_remaining(): void
    {
        $obligation = FinanceObligation::create(['direction' => ObligationDirection::Payable, 'amount' => 999, 'due_on' => '2026-10-20', 'description' => 'x', 'status' => ObligationStatus::Cancelled]);

        $this->assertSame(0, $this->balances->remaining($obligation));
    }

    public function test_duplicate_and_blank_name_errors_point_to_the_name_field(): void
    {
        $this->parties->create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);

        foreach (['abc sigorta', '   '] as $name) {
            try {
                $this->parties->create(['name' => $name, 'kind' => PartyKind::Customer]);
                $this->fail('Hata bekleniyordu.');
            } catch (FinanceRuleException $e) {
                $this->assertSame('name', $e->field);
            }
        }
    }
}
