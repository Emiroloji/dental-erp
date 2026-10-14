<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class FinanceModelsTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): FinanceEntry
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $account = FinanceAccount::create([
            'organization_id' => $organization->id, 'name' => 'Kasa', 'type' => AccountType::Cash,
            'opening_balance' => 0, 'opening_date' => '2026-01-01', 'status' => 'active',
        ]);

        return FinanceEntry::create([
            'organization_id' => $organization->id, 'account_id' => $account->id,
            'type' => FinanceEntryType::Income, 'direction' => 1, 'amount' => 5000,
            'occurred_on' => '2026-10-01',
        ]);
    }

    public function test_entry_casts_and_signed_amount(): void
    {
        $entry = $this->entry()->fresh();

        $this->assertSame(FinanceEntryType::Income, $entry->type);
        $this->assertSame(5000, $entry->amount);
        $this->assertSame(5000, $entry->signedAmount());
        $this->assertSame('TRY', $entry->account->currency);
    }

    public function test_entries_cannot_be_updated(): void
    {
        $entry = $this->entry();

        $this->expectException(LogicException::class);

        $entry->update(['amount' => 1]);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = $this->entry();

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_entry_type_directions(): void
    {
        $this->assertSame(1, FinanceEntryType::Income->direction());
        $this->assertSame(-1, FinanceEntryType::Expense->direction());
        $this->assertSame(-1, FinanceEntryType::TransferOut->direction());
        $this->assertSame(1, FinanceEntryType::TransferIn->direction());
        $this->assertNull(FinanceEntryType::Reversal->direction());
    }

    public function test_finance_records_are_isolated_per_organization(): void
    {
        $this->entry();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        FinanceCategory::create(['organization_id' => $other->id, 'name' => 'Kira', 'direction' => CategoryDirection::Expense, 'status' => 'active']);

        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, FinanceAccount::count());
        $this->assertSame(0, FinanceEntry::count());
        $this->assertSame(1, FinanceCategory::count());
    }
}
