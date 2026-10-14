<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceEntriesScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private FinanceCategory $revenue;

    private FinanceCategory $rent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_income_is_saved_from_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'income')
            ->assertSet('date', '2026-10-14')
            ->set('accountId', $this->cash->id)
            ->set('amount', '2.500')
            ->set('categoryId', $this->revenue->id)
            ->set('description', 'Günlük hasılat')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1_250_000, app(FinanceBalanceService::class)->balance($this->cash));
    }

    public function test_transfer_is_saved_from_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'transfer')
            ->set('accountId', $this->cash->id)
            ->set('toAccountId', $this->bank->id)
            ->set('amount', '3.000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(300_000, app(FinanceBalanceService::class)->balance($this->bank));
    }

    public function test_service_rule_errors_are_shown_on_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', $this->cash->id)
            ->set('amount', '100')
            ->set('categoryId', $this->rent->id)
            ->set('date', '2026-10-20')
            ->call('save')
            ->assertHasErrors('date');

        $this->assertSame(0, FinanceEntry::count());
    }

    public function test_negative_balance_warning_is_flashed(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', $this->bank->id)
            ->set('amount', '500')
            ->set('categoryId', $this->rent->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('eksiye düştü');
    }

    public function test_entry_is_reversed_with_reason(): void
    {
        $entry = app(LedgerService::class)->expense($this->cash, 100_000, CarbonImmutable::parse('2026-10-10'), $this->rent, $this->admin);

        Livewire::test('pages::finance.entries')
            ->call('startReverse', $entry->id)
            ->set('reverseReason', 'Yanlış hesap')
            ->call('confirmReverse')
            ->assertHasNoErrors();

        $this->assertTrue($entry->reversal()->exists());
        $this->assertSame(1_000_000, app(FinanceBalanceService::class)->balance($this->cash));
    }

    public function test_list_filters_by_account_and_type(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 11_100, CarbonImmutable::parse('2026-10-10'), $this->revenue, $this->admin, 'Kasa geliri');
        $ledger->income($this->bank, 22_200, CarbonImmutable::parse('2026-10-10'), $this->revenue, $this->admin, 'Banka geliri');

        Livewire::test('pages::finance.entries')
            ->set('filterAccount', (string) $this->bank->id)
            ->assertSee('Banka geliri')
            ->assertDontSee('Kasa geliri')
            ->set('filterAccount', '')
            ->set('filterType', FinanceEntryType::Income->value)
            ->assertSee('Kasa geliri');
    }

    public function test_page_explains_when_no_account_exists(): void
    {
        FinanceEntry::query()->toBase()->delete();
        FinanceAccount::query()->toBase()->delete();

        $this->get('/finans/hareketler')->assertOk()->assertSee('Önce bir kasa veya banka hesabı');
    }

    public function test_tampered_filters_do_not_break_the_list(): void
    {
        Livewire::test('pages::finance.entries')
            ->set('filterAccount', 'abc')
            ->set('filterCategory', 'x')
            ->set('filterFrom', 'not-a-date')
            ->set('filterTo', '2026-13-99 bad')
            ->set('filterType', 'bogus')
            ->assertOk();
    }

    public function test_commission_parse_error_is_reported_on_commission(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'transfer')
            ->set('accountId', $this->cash->id)
            ->set('toAccountId', $this->bank->id)
            ->set('amount', '3.000')
            ->set('commission', 'abc')
            ->call('save')
            ->assertHasErrors('commission')
            ->assertHasNoErrors('amount');
    }
}
