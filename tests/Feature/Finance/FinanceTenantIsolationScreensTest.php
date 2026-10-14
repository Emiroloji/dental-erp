<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceTenantIsolationScreensTest extends TestCase
{
    use RefreshDatabase;

    private FinanceAccount $account;

    private FinanceCategory $category;

    private FinanceEntry $entry;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $orgA = Organization::create(['name' => 'Klinik A', 'status' => 'active', 'plan' => 'starter']);
        $orgB = Organization::create(['name' => 'Klinik B', 'status' => 'active', 'plan' => 'starter']);
        $adminA = User::factory()->create(['organization_id' => $orgA->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->otherAdmin = User::factory()->create(['organization_id' => $orgB->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($adminA);
        $this->account = FinanceAccount::create(['name' => 'Kasa A', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->category = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $this->entry = app(LedgerService::class)->expense($this->account, 50_000, CarbonImmutable::parse('2026-10-14'), $this->category, $adminA);

        $this->actingAs($this->otherAdmin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Kapsamlı findOrFail, HTTP katmanında 404'e çevrilen ModelNotFoundException
     * fırlatır; Livewire testi istisnayı olduğu gibi yüzeye çıkarır.
     */
    private function assertNotFound(callable $action): void
    {
        try {
            $action();
            $this->fail('Yabancı kiracı kaydı için 404 (ModelNotFoundException) bekleniyordu.');
        } catch (ModelNotFoundException $e) {
            $this->assertInstanceOf(ModelNotFoundException::class, $e);
        }
    }

    public function test_other_organization_cannot_write_entry_to_foreign_account(): void
    {
        $this->assertNotFound(fn () => Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', (string) $this->account->id)
            ->set('amount', '100')
            ->set('categoryId', (string) $this->category->id)
            ->call('save'));

        $this->assertSame(1, FinanceEntry::withoutGlobalScopes()->count());
    }

    public function test_other_organization_cannot_reverse_foreign_entry(): void
    {
        $this->assertNotFound(fn () => Livewire::test('pages::finance.entries')
            ->set('reversingId', $this->entry->id)
            ->set('reverseReason', 'x')
            ->call('confirmReverse'));

        $this->assertFalse(FinanceEntry::withoutGlobalScopes()->where('reverses_entry_id', $this->entry->id)->exists());
    }

    public function test_other_organization_cannot_toggle_foreign_account_or_category(): void
    {
        $this->assertNotFound(fn () => Livewire::test('pages::finance.accounts')->call('deactivate', $this->account->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.accounts')->call('activate', $this->account->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.categories')->call('deactivate', $this->category->id));
        $this->assertNotFound(fn () => Livewire::test('pages::finance.categories')->call('activate', $this->category->id));

        $this->assertTrue(FinanceAccount::withoutGlobalScopes()->findOrFail($this->account->id)->isActive());
        $this->assertTrue(FinanceCategory::withoutGlobalScopes()->findOrFail($this->category->id)->isActive());
    }
}
