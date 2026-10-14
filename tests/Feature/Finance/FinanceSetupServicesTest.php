<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceAccountService;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceSetupServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_account_is_created_with_normalized_iban(): void
    {
        $account = app(FinanceAccountService::class)->create([
            'name' => 'Ziraat', 'type' => AccountType::Bank, 'iban' => 'tr12 0001 0000 0000 0000 0000 01',
            'opening_balance' => -50_000, 'opening_date' => CarbonImmutable::parse('2026-10-01'),
        ]);

        $this->assertSame('TR120001000000000000000001', $account->iban);
        $this->assertSame(-50_000, $account->opening_balance);
        $this->assertTrue($account->isActive());
    }

    public function test_account_opening_date_cannot_be_in_future(): void
    {
        $this->expectException(FinanceRuleException::class);

        app(FinanceAccountService::class)->create([
            'name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-15'),
        ]);
    }

    public function test_account_can_be_deactivated(): void
    {
        $service = app(FinanceAccountService::class);
        $account = $service->create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-01')]);

        $this->assertFalse($service->deactivate($account)->isActive());
    }

    public function test_duplicate_category_is_rejected_per_direction(): void
    {
        $service = app(FinanceCategoryService::class);
        $service->create('Diğer', CategoryDirection::Income);
        $service->create('Diğer', CategoryDirection::Expense);

        $this->expectException(FinanceRuleException::class);

        $service->create(' Diğer ', CategoryDirection::Income);
    }

    public function test_suggested_categories_are_added_once(): void
    {
        $service = app(FinanceCategoryService::class);
        $service->create('Kira', CategoryDirection::Expense);

        $added = $service->addSuggested();

        $this->assertSame(count(FinanceCategoryService::SUGGESTED) - 1, $added);
        $this->assertSame(0, $service->addSuggested());
        $this->assertSame(1, FinanceCategory::where('name', 'Kira')->count());
    }

    public function test_account_can_be_reactivated(): void
    {
        $service = app(FinanceAccountService::class);
        $account = $service->create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-01')]);
        $service->deactivate($account);

        $this->assertTrue($service->activate($account)->isActive());
        $this->assertTrue($account->fresh()->isActive());
    }

    public function test_category_can_be_reactivated(): void
    {
        $service = app(FinanceCategoryService::class);
        $category = $service->create('Kira', CategoryDirection::Expense);
        $service->deactivate($category);

        $this->assertTrue($service->activate($category)->isActive());
        $this->assertTrue($category->fresh()->isActive());
    }

    public function test_duplicate_of_passive_category_tells_user_to_reactivate(): void
    {
        $service = app(FinanceCategoryService::class);
        $service->deactivate($service->create('Kira', CategoryDirection::Expense));

        try {
            $service->create('Kira', CategoryDirection::Expense);
            $this->fail('Yinelenen pasif kategori reddedilmeliydi.');
        } catch (FinanceRuleException $e) {
            $this->assertStringContainsString('pasif', $e->getMessage());
            $this->assertStringContainsString('aktifleştirin', $e->getMessage());
        }
    }

    public function test_account_and_category_errors_carry_field_hints(): void
    {
        try {
            app(FinanceAccountService::class)->create([
                'name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-15'),
            ]);
            $this->fail('Hata bekleniyordu.');
        } catch (FinanceRuleException $e) {
            $this->assertSame('opening_date', $e->field);
        }

        $service = app(FinanceCategoryService::class);
        $service->create('Diğer', CategoryDirection::Income);

        try {
            $service->create('Diğer', CategoryDirection::Income);
            $this->fail('Hata bekleniyordu.');
        } catch (FinanceRuleException $e) {
            $this->assertSame('name', $e->field);
        }
    }
}
