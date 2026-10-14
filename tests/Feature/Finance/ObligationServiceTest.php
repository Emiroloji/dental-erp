<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceParty;
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

class ObligationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ObligationService $obligations;

    private FinanceParty $supplier;

    private FinanceCategory $rent;

    private FinanceCategory $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->obligations = app(ObligationService::class);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier, 'default_term_days' => 30]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $this->revenue = FinanceCategory::create(['name' => 'Kurum Tahsilatı', 'direction' => CategoryDirection::Income]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function payable(array $overrides = []): array
    {
        return [
            'direction' => ObligationDirection::Payable, 'amount' => 1_500_000,
            'due_on' => CarbonImmutable::parse('2026-10-30'), 'party' => $this->supplier,
            'category' => $this->rent, 'description' => ' Fatura F-77 ', 'document_no' => 'F-77', ...$overrides,
        ];
    }

    public function test_create_records_an_open_obligation(): void
    {
        $obligation = $this->obligations->create($this->payable());

        $this->assertSame(ObligationStatus::Open, $obligation->status);
        $this->assertSame('Fatura F-77', $obligation->description);
        $this->assertSame($this->supplier->id, $obligation->party_id);
        $this->assertSame('2026-10-30', $obligation->due_on->toDateString());
    }

    public function test_due_date_defaults_to_party_term(): void
    {
        $obligation = $this->obligations->create($this->payable(['due_on' => null]));

        $this->assertSame('2026-11-13', $obligation->due_on->toDateString());
    }

    public function test_due_date_is_required_without_party_term(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('Vade tarihini');

        $this->obligations->create($this->payable(['due_on' => null, 'party' => null]));
    }

    public function test_past_due_date_is_allowed_for_old_invoices(): void
    {
        $this->assertSame('2026-09-01', $this->obligations->create($this->payable(['due_on' => CarbonImmutable::parse('2026-09-01')]))->due_on->toDateString());
    }

    public function test_category_direction_must_match(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gider kategorisi');

        $this->obligations->create($this->payable(['category' => $this->revenue]));
    }

    public function test_amount_description_and_active_party_are_required(): void
    {
        foreach ([['amount' => 0], ['description' => '  ']] as $overrides) {
            try {
                $this->obligations->create($this->payable($overrides));
                $this->fail('FinanceRuleException bekleniyordu.');
            } catch (FinanceRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->supplier->update(['status' => 'passive']);
        $this->expectException(FinanceRuleException::class);
        $this->obligations->create($this->payable());
    }

    public function test_records_from_another_organization_are_rejected(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreignCategory = FinanceCategory::create(['organization_id' => $other->id, 'name' => 'Yabancı', 'direction' => CategoryDirection::Expense]);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('aynı organizasyon');

        $this->obligations->create($this->payable(['category' => $foreignCategory]));
    }

    public function test_cancel_and_update_leave_the_callers_instance_current(): void
    {
        $obligation = $this->obligations->create($this->payable());

        $this->obligations->update($obligation, $this->payable(['amount' => 777]));
        $this->assertSame(777, $obligation->amount);

        $this->obligations->cancel($obligation, 'Yanlış girildi');
        $this->assertSame(ObligationStatus::Cancelled, $obligation->status);
        $this->assertSame('Yanlış girildi', $obligation->cancel_reason);
    }

    public function test_update_only_without_payments(): void
    {
        $obligation = $this->obligations->create($this->payable());
        $this->obligations->update($obligation, $this->payable(['amount' => 1_200_000]));
        $this->assertSame(1_200_000, $obligation->fresh()->amount);

        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 1, 'occurred_on' => '2026-10-14', 'obligation_id' => $obligation->id]);

        $this->expectException(FinanceRuleException::class);
        $this->obligations->update($obligation->fresh(), $this->payable(['amount' => 5]));
    }

    public function test_cancel_requires_reason_and_no_net_payment(): void
    {
        $obligation = $this->obligations->create($this->payable());

        try {
            $this->obligations->cancel($obligation, ' ');
            $this->fail('Gerekçesiz iptal reddedilmeliydi.');
        } catch (FinanceRuleException) {
        }

        $cancelled = $this->obligations->cancel($obligation, 'Yanlış girildi');
        $this->assertSame(ObligationStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Yanlış girildi', $cancelled->fresh()->cancel_reason);

        $paidOne = $this->obligations->create($this->payable());
        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 100, 'occurred_on' => '2026-10-14', 'obligation_id' => $paidOne->id]);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('ödemeleri iptal');
        $this->obligations->cancel($paidOne, 'x');
    }
}
