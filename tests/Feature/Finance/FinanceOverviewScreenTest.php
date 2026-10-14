<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceOverviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedScenario(): FinanceAccount
    {
        // Nakit: 8.500 açılış + 2.500 gelir − 1.000 gider = 10.000.
        $account = FinanceAccount::create(['name' => 'Merkez Kasa', 'type' => AccountType::Cash, 'opening_balance' => 850_000, 'opening_date' => '2026-09-01']);
        $ledger = app(LedgerService::class);
        $ledger->income($account, 250_000, CarbonImmutable::parse('2026-10-12'), FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]), $this->admin, 'Hasılat');
        $ledger->expense($account, 100_000, CarbonImmutable::parse('2026-10-05'), FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]), $this->admin, 'Gider');

        $make = fn (ObligationDirection $d, int $amount, string $due, string $desc) => FinanceObligation::create(['direction' => $d, 'amount' => $amount, 'due_on' => $due, 'description' => $desc]);
        $make(ObligationDirection::Payable, 100_000, '2026-10-18', 'Depo faturası');
        $make(ObligationDirection::Payable, 100_000, '2026-10-01', 'Geciken kira');
        $make(ObligationDirection::Receivable, 50_000, '2026-10-20', 'Sigorta hakedişi');

        return $account;
    }

    public function test_empty_state_without_accounts(): void
    {
        Livewire::test('pages::finance.overview')
            ->assertSee('Önce bir kasa veya banka hesabı tanımla')
            ->assertSeeHtml(route('finance.accounts'))
            ->assertDontSee('Toplam nakit');
    }

    public function test_cards_show_derived_figures(): void
    {
        $account = $this->seedScenario();

        $component = Livewire::test('pages::finance.overview')
            ->assertSee('Toplam nakit')
            ->assertSee('aktif hesaplar')
            ->assertSee('gecikenler dahil')
            ->assertSee('Merkez Kasa')
            ->assertSee('Depo faturası')
            ->assertSee('Sigorta hakedişi')
            ->assertSeeHtml(route('finance.accounts.show', $account))
            ->assertSeeHtml(route('finance.obligations', ['tab' => 'overdue']))
            ->assertSeeHtml(route('finance.reports', ['report' => 'cash_flow']));

        $this->assertSame(1_000_000, $component->viewData('total'));
        $this->assertSame(250_000, $component->viewData('month')['income_total']);
        $this->assertSame(100_000, $component->viewData('month')['expense_total']);
        $this->assertSame(150_000, $component->viewData('month')['net']);
        $this->assertSame(200_000, $component->viewData('summary')['weekPayable']);
        $this->assertSame(50_000, $component->viewData('summary')['weekReceivable']);
        $this->assertSame(100_000, $component->viewData('summary')['overduePayable']);
        $this->assertSame(0, $component->viewData('summary')['overdueReceivable']);
        $this->assertSame(1, $component->viewData('summary')['overdueCount']);
        $this->assertCount(3, $component->viewData('summary')['upcoming']);
        $this->assertNull($component->viewData('projection')['first_negative']);
    }

    public function test_negative_projection_shows_warning(): void
    {
        $this->seedScenario();
        FinanceObligation::create(['direction' => ObligationDirection::Payable, 'amount' => 3_000_000, 'due_on' => '2026-10-30', 'description' => 'Büyük alım']);

        Livewire::test('pages::finance.overview')
            ->assertSee('Nakit 30.10.2026 tarihinde eksiye düşüyor')
            ->assertSee('−21.500,00 ₺')
            ->assertDontSee('Nakit eksiye düşmüyor');
    }

    public function test_upcoming_list_is_limited_to_five_in_due_order(): void
    {
        $this->seedScenario();

        foreach (range(21, 25) as $day) {
            FinanceObligation::create(['direction' => ObligationDirection::Payable, 'amount' => 10_000, 'due_on' => "2026-10-{$day}", 'description' => "Kalem {$day}"]);
        }

        $upcoming = Livewire::test('pages::finance.overview')->viewData('summary')['upcoming'];

        $this->assertSame(
            ['Geciken kira', 'Depo faturası', 'Sigorta hakedişi', 'Kalem 21', 'Kalem 22'],
            array_map(fn ($row) => $row['obligation']->description, $upcoming),
        );
    }

    public function test_menu_links_to_overview_and_unauthorized_staff_gets_403(): void
    {
        $this->get('/finans')->assertOk()->assertSee('href="'.route('finance.overview').'"', false);
        $this->assertSame('/finans', route('finance.overview', absolute: false));

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff)->get('/finans')->assertForbidden();

        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff->fresh())->get('/finans')->assertOk();
    }

    public function test_other_organization_data_is_not_visible(): void
    {
        $this->seedScenario();
        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        Livewire::test('pages::finance.overview')
            ->assertSee('Önce bir kasa veya banka hesabı tanımla')
            ->assertDontSee('Merkez Kasa')
            ->assertDontSee('Depo faturası');
    }
}
