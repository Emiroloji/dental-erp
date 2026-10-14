<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\ReportType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceReportsScreenTest extends TestCase
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

        $this->bank = FinanceAccount::create(['name' => 'Ziraat TL', 'type' => AccountType::Bank, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $income = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        app(LedgerService::class)->income($this->bank, 500_000, CarbonImmutable::parse('2026-10-05'), $income, $this->admin, 'Ekim hasılatı');

        $abc = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $obligations = app(ObligationService::class);
        $obligations->create(['direction' => ObligationDirection::Receivable, 'amount' => 300_000, 'due_on' => CarbonImmutable::parse('2026-10-01'), 'description' => 'Eylül hakedişi', 'party' => $abc]);
        $obligations->create(['direction' => ObligationDirection::Payable, 'amount' => 2_000_000, 'due_on' => CarbonImmutable::parse('2026-10-20'), 'description' => 'Kira']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_income_expense_tab_shows_totals_and_monthly_table(): void
    {
        Livewire::test('pages::finance.reports')
            ->assertSet('report', 'income_expense')
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-14')
            ->assertSee('Hasılat')
            ->assertSee('5.000,00 ₺')
            ->assertSee('Gelir kategorileri')
            ->assertSee('Ekim 2026');
    }

    public function test_cash_flow_tab_shows_negative_projection_warning(): void
    {
        Livewire::test('pages::finance.reports', ['report' => 'cash_flow'])
            ->call('selectReport', 'cash_flow')
            ->assertSet('days', '90')
            ->assertSee("Nakit 20.10.2026'de eksiye düşüyor.", false)
            ->assertSee('Gerçekleşen nakit akışı')
            ->assertSee('15.000,00 ₺')
            ->assertSee('<svg', false);
    }

    public function test_aging_tab_shows_party_row_with_link(): void
    {
        $party = FinanceParty::where('name', 'ABC Sigorta')->first();

        Livewire::test('pages::finance.reports')
            ->call('selectReport', 'aging')
            ->assertSee('ABC Sigorta')
            ->assertSee('3.000,00 ₺')
            ->assertSee('1–30 gün')
            ->assertSee(route('finance.parties.show', $party), false)
            ->set('direction', 'payable')
            ->assertDontSee('ABC Sigorta')
            ->assertSee('Carisiz')
            ->assertSee('20.000,00 ₺');
    }

    public function test_invalid_query_values_are_normalized(): void
    {
        Livewire::withQueryParams(['report' => 'bogus', 'days' => '45', 'direction' => 'sideways', 'from' => '2026-13-45', 'to' => 'x', 'branch' => '99999', 'account' => 'abc'])
            ->test('pages::finance.reports')
            ->assertSet('report', 'income_expense')
            ->assertSet('days', '90')
            ->assertSet('direction', 'receivable')
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-14')
            ->assertSet('branch', '')
            ->assertSet('account', '');

        Livewire::test('pages::finance.reports')
            ->set('report', 'cash_flow')
            ->set('days', '60')
            ->assertSet('days', '60')
            ->set('days', '9999')
            ->assertSet('days', '90')
            ->assertSet('from', '2026-10-01');
    }

    public function test_foreign_organization_branch_and_account_are_ignored(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $other->id, 'name' => 'Yabancı Şube', 'status' => 'active']);
        $account = FinanceAccount::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Yabancı', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        Livewire::test('pages::finance.reports')
            ->set('branch', (string) $branch->id)
            ->set('account', (string) $account->id)
            ->assertSet('branch', '')
            ->assertSet('account', '')
            ->assertDontSee('Yabancı');
    }

    public function test_staff_without_finance_permission_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']));

        $this->get(route('finance.reports'))->assertForbidden();
    }

    public function test_finance_staff_can_open_page(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->get(route('finance.reports'))->assertOk()->assertSee('Gelir-Gider');
    }

    public function test_export_button_creates_report_export_record(): void
    {
        Queue::fake();

        Livewire::test('pages::finance.reports')
            ->call('selectReport', 'aging')
            ->set('direction', 'payable')
            ->call('export', 'pdf')
            ->assertSee('Rapor hazırlanıyor');

        $export = ReportExport::sole();
        $this->assertSame(ReportType::FinanceAging, $export->report);
        $this->assertSame('pdf', $export->format);
        $this->assertSame('payable', $export->parameters['filters']['direction']);

        Livewire::test('pages::finance.reports')->call('export', 'csv');
        $this->assertSame(1, ReportExport::count());
    }

    public function test_account_page_statement_export_button_queues_statement(): void
    {
        Queue::fake();

        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('from', '2026-10-01')
            ->set('to', '2026-10-31')
            ->call('export', 'xlsx')
            ->assertSee('Ekstre hazırlanıyor');

        $export = ReportExport::sole();
        $this->assertSame(ReportType::FinanceStatement, $export->report);
        $this->assertSame($this->bank->id, $export->parameters['account_id']);
        $this->assertSame('2026-10-31', $export->parameters['filters']['to']);
    }

    public function test_tabs_include_reports_last(): void
    {
        $this->get(route('finance.accounts'))->assertOk()->assertSeeInOrder(['Kategoriler', 'Raporlar']);
    }
}
