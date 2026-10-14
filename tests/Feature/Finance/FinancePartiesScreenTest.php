<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancePartiesScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_creates_and_edits_a_party(): void
    {
        Livewire::test('pages::finance.parties')
            ->call('openForm')
            ->set('name', 'ABC Sigorta')->set('kind', 'customer')->set('defaultTermDays', '30')
            ->call('save')
            ->assertHasNoErrors();

        $party = FinanceParty::firstOrFail();
        $this->assertSame(30, $party->default_term_days);

        Livewire::test('pages::finance.parties')
            ->call('openForm', $party->id)
            ->assertSet('name', 'ABC Sigorta')
            ->set('phone', '0212 111 11 11')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0212 111 11 11', $party->fresh()->phone);
    }

    public function test_duplicate_name_shows_error(): void
    {
        FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        Livewire::test('pages::finance.parties')->set('name', 'Depo')->set('kind', 'supplier')->call('save')->assertHasErrors('name');
    }

    public function test_list_shows_derived_balance_and_party_page_shows_records(): void
    {
        $party = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $obligation = FinanceObligation::create(['direction' => ObligationDirection::Receivable, 'party_id' => $party->id, 'amount' => 500_000, 'due_on' => '2026-10-20', 'description' => 'Ekim faturası']);
        $account = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        app(LedgerService::class)->pay($obligation, $account, 200_000, CarbonImmutable::parse('2026-10-14'), $this->admin);

        $this->get('/finans/cariler')->assertOk()->assertSee('ABC Sigorta')->assertSee('3.000,00 ₺');
        $this->get('/finans/cariler/'.$party->id)->assertOk()->assertSee('Ekim faturası')->assertSee('Tahsilat')->assertSee('Cari size borçlu');
    }

    public function test_party_of_another_organization_is_not_found(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreign = FinanceParty::create(['organization_id' => $other->id, 'name' => 'Yabancı', 'kind' => PartyKind::Other]);

        $this->get('/finans/cariler/'.$foreign->id)->assertNotFound();
    }

    public function test_read_only_staff_sees_but_cannot_change(): void
    {
        $party = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->get('/finans/cariler')->assertOk()->assertDontSee('Yeni Cari');
        Livewire::test('pages::finance.parties')->call('deactivate', $party->id)->assertForbidden();
        Livewire::test('pages::finance.parties')->set('name', 'X')->set('kind', 'other')->call('save')->assertForbidden();
    }

    public function test_deactivate_and_activate(): void
    {
        $party = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        Livewire::test('pages::finance.parties')->call('deactivate', $party->id);
        $this->assertSame('passive', $party->fresh()->status);

        Livewire::test('pages::finance.parties')->call('activate', $party->id);
        $this->assertSame('active', $party->fresh()->status);
    }
}
