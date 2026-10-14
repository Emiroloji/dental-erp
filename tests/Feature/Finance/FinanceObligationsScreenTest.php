<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceObligationsScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceAccount $bank;

    private FinanceParty $supplier;

    private FinanceCategory $material;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 5_000_000, 'opening_date' => '2026-10-01']);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier, 'default_term_days' => 30]);
        $this->material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function invoice(string $dueOn = '2026-10-16', array $overrides = []): FinanceObligation
    {
        return FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $this->supplier->id, 'category_id' => $this->material->id, 'amount' => 1_500_000, 'due_on' => $dueOn, 'description' => 'Fatura F-77', ...$overrides]);
    }

    public function test_admin_adds_a_payable_with_party_term(): void
    {
        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('amount', '15.000')->set('partyId', (string) $this->supplier->id)->set('categoryId', (string) $this->material->id)
            ->set('description', 'Fatura F-77')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-11-13', FinanceObligation::firstOrFail()->due_on->toDateString());
    }

    public function test_partial_and_full_payment_from_the_screen(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->assertSet('payAmount', '15000,00')
            ->set('payAccountId', (string) $this->bank->id)->set('payAmount', '5.000')
            ->call('confirmPay')
            ->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->assertSet('payAmount', '10000,00')
            ->set('payAccountId', (string) $this->bank->id)
            ->call('confirmPay')
            ->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(3_500_000, app(FinanceBalanceService::class)->balance($this->bank));
    }

    public function test_overpayment_shows_error(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->set('payAccountId', (string) $this->bank->id)->set('payAmount', '20.000')
            ->call('confirmPay')
            ->assertHasErrors('payAmount');
    }

    public function test_tabs_filter_by_due_date_and_summary_shows_totals(): void
    {
        $this->invoice('2026-10-10', ['description' => 'Geciken fatura']);
        $this->invoice('2026-10-18', ['description' => 'Bu hafta fatura']);
        $this->invoice('2026-11-10', ['description' => 'Gelecek ay fatura']);

        Livewire::test('pages::finance.obligations')
            ->set('tab', 'overdue')->assertSee('Geciken fatura')->assertDontSee('Bu hafta fatura')
            ->set('tab', 'week')->assertSee('Bu hafta fatura')->assertSee('Geciken fatura')->assertDontSee('Gelecek ay fatura')
            ->set('tab', 'all')->assertSee('Gelecek ay fatura')
            ->assertSee('30.000,00 ₺');
    }

    public function test_cancel_requires_reason_and_is_blocked_after_payment(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id)->call('confirmCancel')->assertHasErrors('cancelReason');
        Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id)->set('cancelReason', 'Mükerrer')->call('confirmCancel')->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_staff_without_finance_is_forbidden(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/finans/odemeler')->assertForbidden();
    }
}
