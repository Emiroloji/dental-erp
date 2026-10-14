<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceObligationModelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
    }

    private function party(): FinanceParty
    {
        return FinanceParty::create(['organization_id' => $this->organization->id, 'name' => 'Dental Depo A.Ş.', 'kind' => PartyKind::Supplier]);
    }

    public function test_obligation_defaults_casts_and_relations(): void
    {
        $party = $this->party();
        $obligation = FinanceObligation::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable,
            'party_id' => $party->id, 'amount' => 1_500_000, 'due_on' => '2026-10-30', 'description' => 'Fatura F-77',
        ])->fresh();

        $this->assertSame(ObligationStatus::Open, $obligation->status);
        $this->assertSame(1_500_000, $obligation->amount);
        $this->assertSame('2026-10-30', $obligation->due_on->toDateString());
        $this->assertTrue($obligation->party->is($party));
        $this->assertTrue($party->obligations->first()->is($obligation));
        $this->assertTrue($obligation->isOpen());
        $this->assertSame('active', $party->fresh()->status);
    }

    public function test_entry_links_party_and_obligation_and_labels_payments(): void
    {
        $party = $this->party();
        $obligation = FinanceObligation::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable,
            'party_id' => $party->id, 'amount' => 100, 'due_on' => '2026-10-30', 'description' => 'x',
        ]);
        $account = FinanceAccount::create(['organization_id' => $this->organization->id, 'name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        $payment = FinanceEntry::create([
            'organization_id' => $this->organization->id, 'account_id' => $account->id, 'type' => FinanceEntryType::Payment,
            'direction' => -1, 'amount' => 100, 'occurred_on' => '2026-10-14', 'party_id' => $party->id, 'obligation_id' => $obligation->id,
        ]);

        $this->assertTrue($payment->party->is($party));
        $this->assertTrue($payment->obligation->is($obligation));
        $this->assertSame('Ödeme', $payment->label());
        $this->assertSame(1, $obligation->payments()->count());
        $this->assertSame('Gelir', (new FinanceEntry(['type' => FinanceEntryType::Income, 'direction' => 1]))->label());
    }

    public function test_recurring_generates_each_due_date_at_most_once(): void
    {
        $recurring = FinanceRecurring::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable, 'name' => 'Kira',
            'amount' => 2_000_000, 'frequency' => RecurringFrequency::Monthly, 'day' => 5,
            'starts_on' => '2026-11-05', 'next_due_on' => '2026-11-05',
        ]);
        $attributes = ['organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable, 'amount' => 1, 'due_on' => '2026-11-05', 'description' => 'Kira', 'recurring_id' => $recurring->id];
        FinanceObligation::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        FinanceObligation::create($attributes);
    }

    public function test_new_records_are_isolated_per_organization(): void
    {
        $this->party();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, FinanceParty::count());
        $this->assertSame(0, FinanceObligation::count());
        $this->assertSame(0, FinanceRecurring::count());
    }
}
