<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\RecurringService;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecurringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecurringService $recurrings;

    private Organization $organization;

    private FinanceCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->recurrings = app(RecurringService::class);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function monthlyRent(array $overrides = []): array
    {
        return [
            'direction' => ObligationDirection::Payable, 'name' => 'Kira', 'amount' => 2_000_000,
            'category' => $this->rent, 'frequency' => RecurringFrequency::Monthly,
            'starts_on' => CarbonImmutable::parse('2026-10-05'), ...$overrides,
        ];
    }

    public function test_create_derives_day_and_first_due_date(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());

        $this->assertSame(5, $recurring->day);
        $this->assertSame('2026-10-05', $recurring->next_due_on->toDateString());
    }

    public function test_generation_covers_the_horizon_once_and_is_idempotent(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());

        // Bugün 14.10, ufuk 18.11: 05.10 (geçmiş ama tanımdan sonra ilk) ve 05.11 üretilir.
        $this->assertSame(2, $this->recurrings->generateDue());
        $this->assertSame(0, $this->recurrings->generateDue());

        $dates = FinanceObligation::orderBy('due_on')->pluck('due_on')->map->toDateString()->all();
        $this->assertSame(['2026-10-05', '2026-11-05'], $dates);
        $this->assertSame('2026-12-05', $recurring->fresh()->next_due_on->toDateString());

        $first = FinanceObligation::orderBy('due_on')->first();
        $this->assertSame('Kira — Ekim 2026', $first->description);
        $this->assertSame(2_000_000, $first->amount);
        $this->assertSame($this->rent->id, $first->category_id);
        $this->assertSame(ObligationStatus::Open, $first->status);
        $this->assertSame($this->organization->id, $first->organization_id);
    }

    public function test_generation_runs_without_a_logged_in_user(): void
    {
        $this->recurrings->create($this->monthlyRent());
        auth()->logout();

        Artisan::call('finance:generate-recurring');

        $this->assertSame(2, FinanceObligation::withoutGlobalScopes()->count());
    }

    public function test_ends_on_stops_generation_and_marks_definition_passive(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent(['ends_on' => CarbonImmutable::parse('2026-10-31')]));

        $this->assertSame(1, $this->recurrings->generateDue());
        $this->assertSame('passive', $recurring->fresh()->status);
    }

    public function test_passive_definition_and_inactive_organization_are_skipped(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $this->recurrings->deactivate($recurring);
        $this->assertSame(0, $this->recurrings->generateDue());

        $this->recurrings->activate($recurring->fresh());
        $this->organization->update(['status' => OrganizationStatus::Passive]);
        $this->assertSame(0, $this->recurrings->generateDue());
    }

    public function test_activation_skips_missed_periods_instead_of_backfilling(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent(['starts_on' => CarbonImmutable::parse('2026-06-05')]));
        $this->recurrings->deactivate($recurring);

        $this->recurrings->activate($recurring->fresh());

        $this->assertSame('2026-11-05', $recurring->fresh()->next_due_on->toDateString());
    }

    public function test_update_changes_future_amount_only(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $this->recurrings->generateDue();

        $this->recurrings->update($recurring->fresh(), ['name' => 'Kira', 'amount' => 2_200_000, 'category' => $this->rent]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 09:00:00', 'Europe/Istanbul'));
        $this->recurrings->generateDue();

        $this->assertSame([2_000_000, 2_000_000, 2_200_000], FinanceObligation::orderBy('due_on')->pluck('amount')->all());
    }

    public function test_validation(): void
    {
        foreach ([
            ['amount' => 0],
            ['name' => ' '],
            ['category' => FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income])],
            ['ends_on' => CarbonImmutable::parse('2026-10-01')],
            ['party' => FinanceParty::create(['name' => 'Ev Sahibi', 'kind' => PartyKind::Other, 'status' => 'passive'])],
        ] as $overrides) {
            try {
                $this->recurrings->create($this->monthlyRent($overrides));
                $this->fail('FinanceRuleException bekleniyordu: '.json_encode(array_keys($overrides)));
            } catch (FinanceRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
