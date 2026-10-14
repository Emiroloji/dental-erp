<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    private function assertNotFound(callable $action): void
    {
        try {
            $action();
            $this->fail('Yabancı kiracı kaydı için ModelNotFoundException bekleniyordu.');
        } catch (ModelNotFoundException $e) {
            $this->assertInstanceOf(ModelNotFoundException::class, $e);
        }
    }

    private function otherOrgAdmin(): User
    {
        $orgB = Organization::create(['name' => 'Klinik B', 'status' => 'active', 'plan' => 'starter']);

        return User::factory()->create(['organization_id' => $orgB->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_changing_direction_resets_the_category(): void
    {
        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('categoryId', (string) $this->material->id)
            ->set('formDirection', 'receivable')
            ->assertSet('categoryId', '');
    }

    public function test_rule_errors_are_routed_to_their_field(): void
    {
        $passive = FinanceParty::create(['name' => 'Eski Depo', 'kind' => PartyKind::Supplier, 'status' => 'passive']);

        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('amount', '100')->set('description', 'X')->set('dueOn', '2026-10-20')->set('partyId', (string) $passive->id)
            ->call('save')
            ->assertHasErrors('partyId');
    }

    public function test_other_organization_cannot_touch_foreign_obligations_or_accounts(): void
    {
        $invoice = $this->invoice();
        $mine = $this->actingAs($this->otherOrgAdmin())->invoice();
        $foreignAccount = $this->bank;

        $this->assertNotFound(fn () => Livewire::test('pages::finance.obligations')->call('startPay', $invoice->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.obligations')->call('openForm', $invoice->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.obligations')->set('payingId', $invoice->id)->set('payAccountId', (string) $foreignAccount->id)->set('payAmount', '10')->set('payDate', '2026-10-14')->call('confirmPay'));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.obligations')->set('payingId', $mine->id)->set('payAccountId', (string) $foreignAccount->id)->set('payAmount', '10')->set('payDate', '2026-10-14')->call('confirmPay'));

        $this->assertSame(0, FinanceEntry::withoutGlobalScopes()->count());
        $this->assertSame(ObligationStatus::Open, $invoice->fresh()->status);
    }

    public function test_read_only_finance_staff_cannot_mutate(): void
    {
        $invoice = $this->invoice();
        $staff = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        Livewire::test('pages::finance.obligations')->call('startPay', $invoice->id)->assertForbidden();
        Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id)->assertForbidden();
        Livewire::test('pages::finance.obligations')->call('openForm', $invoice->id)->assertForbidden();
        Livewire::test('pages::finance.obligations')->call('save')->assertForbidden();
    }

    public function test_cancel_is_blocked_after_a_payment(): void
    {
        $invoice = $this->invoice();
        app(LedgerService::class)->pay($invoice, $this->bank, 500_000, CarbonImmutable::parse('2026-10-14'), $this->admin, '');

        Livewire::test('pages::finance.obligations')
            ->call('startCancel', $invoice->id)->set('cancelReason', 'Hata')->call('confirmCancel')
            ->assertHasErrors('cancelReason');

        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);
    }

    public function test_edit_updates_amount_but_not_after_payment_or_with_tampered_direction(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')
            ->call('openForm', $invoice->id)
            ->assertSet('amount', '15000,00')
            ->set('amount', '12.000')->set('formDirection', 'receivable')
            ->call('save')->assertHasNoErrors();

        $fresh = $invoice->fresh();
        $this->assertSame(1_200_000, $fresh->amount);
        $this->assertSame(ObligationDirection::Payable, $fresh->direction);

        app(LedgerService::class)->pay($fresh, $this->bank, 100_000, CarbonImmutable::parse('2026-10-14'), $this->admin, '');

        Livewire::test('pages::finance.obligations')
            ->call('openForm', $invoice->id)->set('amount', '1')->call('save')
            ->assertHasErrors('amount');

        $this->assertSame(1_200_000, $invoice->fresh()->amount);
    }

    public function test_start_pay_refuses_closed_obligation_and_invalid_filters_are_normalized(): void
    {
        $invoice = $this->invoice(overrides: ['status' => ObligationStatus::Cancelled]);

        Livewire::test('pages::finance.obligations')->call('startPay', $invoice->id)->assertNotFound();

        Livewire::withQueryParams(['tab' => 'zzz', 'direction' => 'x'])->test('pages::finance.obligations')
            ->assertSet('tab', 'week')->assertSet('direction', '');
    }

    public function test_edit_is_hidden_once_a_payment_was_ever_linked(): void
    {
        $invoice = $this->invoice();
        $plain = $this->invoice('2026-10-17', ['description' => 'Dokunulmamış fatura']);
        $payment = app(LedgerService::class)->pay($invoice, $this->bank, 500_000, CarbonImmutable::parse('2026-10-14'), $this->admin);
        app(LedgerService::class)->reverse($payment, 'Yanlış hesap', $this->admin);

        Livewire::test('pages::finance.obligations')
            ->assertSee('startCancel('.$invoice->id.')', false)
            ->assertDontSee('openForm('.$invoice->id.')', false)
            ->assertSee('openForm('.$plain->id.')', false);
    }

    public function test_passive_account_error_is_shown_on_the_account_field(): void
    {
        $invoice = $this->invoice();
        $this->bank->update(['status' => 'passive']);

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->set('payAccountId', (string) $this->bank->id)
            ->call('confirmPay')
            ->assertHasErrors('payAccountId');
    }

    public function test_payment_can_be_reversed_from_entries_and_reopens_the_obligation(): void
    {
        $invoice = $this->invoice();
        $payment = app(LedgerService::class)->pay($invoice, $this->bank, 500_000, CarbonImmutable::parse('2026-10-14'), $this->admin);
        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);

        Livewire::test('pages::finance.entries')
            ->assertSee('Ödeme')
            ->assertSee('startReverse('.$payment->id.')', false)
            ->call('startReverse', $payment->id)
            ->set('reverseReason', 'Yanlış hesap')
            ->call('confirmReverse')
            ->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Open, $invoice->fresh()->status);
    }

    public function test_party_name_containing_category_does_not_misroute_the_error(): void
    {
        $party = FinanceParty::create(['name' => 'Kategori Ltd.', 'kind' => PartyKind::Supplier, 'status' => 'passive']);

        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('amount', '100')->set('partyId', (string) $party->id)->set('categoryId', (string) $this->material->id)
            ->set('description', 'Fatura')->set('dueOn', '2026-10-30')
            ->call('save')
            ->assertHasErrors('partyId')
            ->assertHasNoErrors('categoryId');
    }
}
