<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceSetupScreensTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_creates_account_with_turkish_amount(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.accounts')
            ->call('openForm')
            ->set('name', 'Merkez Kasa')
            ->set('type', 'cash')
            ->set('openingBalance', '10.000,00')
            ->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertHasNoErrors();

        $account = FinanceAccount::firstOrFail();
        $this->assertSame(1_000_000, $account->opening_balance);
        $this->assertSame(AccountType::Cash, $account->type);
    }

    public function test_invalid_amount_shows_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.accounts')
            ->set('name', 'Kasa')->set('type', 'cash')->set('openingBalance', 'on bin')->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertHasErrors('openingBalance');
    }

    public function test_accounts_page_shows_balances(): void
    {
        FinanceAccount::create(['organization_id' => $this->organization->id, 'name' => 'Merkez Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_250_050, 'opening_date' => '2026-10-01']);

        $this->actingAs($this->admin)->get('/finans/hesaplar')->assertOk()->assertSee('Merkez Kasa')->assertSee('12.500,50 ₺');
    }

    public function test_admin_adds_suggested_categories(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.categories')->call('addSuggested');

        $this->assertSame(count(FinanceCategoryService::SUGGESTED), FinanceCategory::count());
    }

    public function test_duplicate_category_shows_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.categories')->set('name', 'Kira')->set('direction', 'expense')->call('save')->assertHasNoErrors();
        Livewire::test('pages::finance.categories')->set('name', 'Kira')->set('direction', 'expense')->call('save')->assertHasErrors('name');
    }

    public function test_staff_without_finance_permission_cannot_open_finance_pages(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff);
        $this->get('/finans/hesaplar')->assertForbidden();
        $this->get('/finans/kategoriler')->assertForbidden();
    }

    public function test_read_only_staff_cannot_create_account(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        $this->actingAs($staff)->get('/finans/hesaplar')->assertOk()->assertDontSee('Yeni Hesap');

        Livewire::test('pages::finance.accounts')
            ->set('name', 'Kasa')->set('type', 'cash')->set('openingBalance', '0')->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertForbidden();
    }

    public function test_menu_shows_finance_link_only_with_permission(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee(route('finance.entries'));

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff)->get('/dashboard')->assertDontSee(route('finance.entries'));
    }
}
