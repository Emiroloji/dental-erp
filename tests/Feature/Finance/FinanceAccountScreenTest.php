<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceReconciliation;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceAccountScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private FinanceAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->bank = FinanceAccount::create(['name' => 'Ziraat TL', 'type' => AccountType::Bank, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01', 'iban' => 'TR330006100519786457841326']);
        $income = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        app(LedgerService::class)->income($this->bank, 250_000, CarbonImmutable::parse('2026-10-05'), $income, $this->admin, 'Ekim hasılatı');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function readOnlyStaff(): User
    {
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    public function test_page_shows_account_header_and_statement_rows(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->assertSee('Ziraat TL')
            ->assertSee('TR330006100519786457841326')
            ->assertSee('Ekim hasılatı')
            ->assertSee('12.500,00 ₺')
            ->assertSee('Banka ekstresindeki bakiye');

        $this->get(route('finance.accounts.show', $this->bank))->assertOk()->assertSee('Ekim hasılatı');
    }

    public function test_other_organizations_account_is_404(): void
    {
        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $foreign = FinanceAccount::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Yabancı', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        $this->get(route('finance.accounts.show', $foreign->id))->assertNotFound();
    }

    public function test_read_only_staff_sees_no_reconcile_form_and_cannot_reconcile(): void
    {
        $this->actingAs($this->readOnlyStaff());

        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->assertDontSee('Banka ekstresindeki bakiye')
            ->set('statementBalance', '12.000')
            ->call('compare')
            ->assertForbidden();

        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementBalance', '12.000')
            ->call('reconcile', true)
            ->assertForbidden();

        $this->assertSame(0, FinanceReconciliation::count());
    }

    public function test_difference_is_shown_and_closed_with_adjustment(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementDate', '2026-10-14')
            ->set('statementBalance', '12.450')
            ->call('compare')
            ->assertSet('previewDifference', -5_000)
            ->assertSee('−50,00 ₺')
            ->assertSee('Farkı düzeltme hareketiyle kapat')
            ->assertSee('Yalnızca kaydet')
            ->call('reconcile', true)
            ->assertHasNoErrors()
            ->assertSet('previewDifference', null);

        $this->assertSame(1_245_000, app(FinanceBalanceService::class)->balance($this->bank));
        $this->assertNotNull(FinanceReconciliation::firstOrFail()->adjustment_entry_id);
    }

    public function test_matching_statement_offers_plain_record_button(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementBalance', '12.500')
            ->call('compare')
            ->assertSet('previewDifference', 0)
            ->assertSee('Kayıt et')
            ->assertDontSee('Farkı düzeltme hareketiyle kapat');
    }

    public function test_save_only_records_difference_without_adjustment(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementBalance', '12.450')
            ->call('reconcile', false)
            ->assertHasNoErrors();

        $this->assertNull(FinanceReconciliation::firstOrFail()->adjustment_entry_id);
        $this->assertSame(1_250_000, app(FinanceBalanceService::class)->balance($this->bank));
    }

    public function test_future_statement_date_error_is_shown_on_date_field(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementDate', '2026-10-20')
            ->set('statementBalance', '12.500')
            ->call('compare')
            ->assertHasErrors('statementDate');
    }

    public function test_invalid_amount_is_an_error_on_balance_field(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementBalance', 'abc')
            ->call('compare')
            ->assertHasErrors('statementBalance');
    }

    public function test_history_marks_reversed_adjustment(): void
    {
        $record = app(ReconciliationService::class)->reconcile($this->bank, CarbonImmutable::parse('2026-10-14'), 1_245_000, $this->admin, true);

        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->assertSee('Düzeltme yapıldı')
            ->assertDontSee('Düzeltme iptal edildi');

        app(LedgerService::class)->reverse($record->adjustment, 'Hatalı mutabakat', $this->admin);

        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->assertSee('Düzeltme iptal edildi')
            ->assertDontSee('Düzeltme yapıldı');
    }

    public function test_invalid_or_oversized_period_falls_back_to_defaults(): void
    {
        foreach ([['x', '2026-10-10'], ['2026-10-12', '2026-10-01'], ['2020-01-01', '2026-10-14']] as [$from, $to]) {
            Livewire::withQueryParams(['from' => $from, 'to' => $to])
                ->test('pages::finance.account', ['account' => $this->bank])
                ->assertSet('from', '2026-10-01')
                ->assertSet('to', '2026-10-14');
        }

        Livewire::withQueryParams(['from' => '2026-09-01', 'to' => '2026-10-10'])
            ->test('pages::finance.account', ['account' => $this->bank])
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-10-10');
    }

    public function test_negative_statement_balance_previews_correctly(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementBalance', '-2.500')
            ->call('compare')
            ->assertHasNoErrors()
            ->assertSet('previewSystem', 1_250_000)
            ->assertSet('previewStatement', -250_000)
            ->assertSet('previewDifference', -1_500_000);
    }

    public function test_reconcile_routes_future_date_to_statement_date_field(): void
    {
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementDate', '2026-10-20')
            ->set('statementBalance', '12.500')
            ->call('reconcile', true)
            ->assertHasErrors('statementDate');

        $this->assertSame(0, FinanceReconciliation::count());
    }
}
