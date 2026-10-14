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
use Illuminate\Support\Facades\DB;
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

    public function test_reactivated_organization_does_not_backfill_beyond_horizon(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $recurring->update(['next_due_on' => '2026-06-05']);

        $this->organization->update(['status' => OrganizationStatus::Passive]);
        $this->assertSame(0, $this->recurrings->generateDue());
        $this->assertSame('2026-06-05', $recurring->fresh()->next_due_on->toDateString());

        // Bugün 14.10 → taban 09.09; ilk dönem 05.10, ufuk 18.11 → 05.10 ve 05.11.
        $this->organization->update(['status' => OrganizationStatus::Active]);
        $this->assertSame(2, $this->recurrings->generateDue());

        $dates = FinanceObligation::orderBy('due_on')->pluck('due_on')->map->toDateString()->all();
        $this->assertSame(['2026-10-05', '2026-11-05'], $dates);
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

    public function test_due_date_exactly_at_horizon_is_generated(): void
    {
        $this->recurrings->create($this->monthlyRent(['starts_on' => CarbonImmutable::parse('2026-11-18')]));

        $this->assertSame(1, $this->recurrings->generateDue());
        $this->assertSame('2026-11-18', FinanceObligation::first()->due_on->toDateString());
    }

    public function test_old_start_date_is_not_backfilled_beyond_the_horizon(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent(['starts_on' => CarbonImmutable::parse('2025-01-05')]));

        $this->assertSame(5, $recurring->day);
        $this->assertSame('2025-01-05', $recurring->starts_on->toDateString());
        $this->assertSame('2026-10-05', $recurring->next_due_on->toDateString());
        $this->assertSame(2, $this->recurrings->generateDue());
        $this->assertSame('2026-10-05', FinanceObligation::orderBy('due_on')->first()->due_on->toDateString());
    }

    public function test_rerun_after_resetting_next_due_hits_unique_index_safely(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $this->assertSame(2, $this->recurrings->generateDue());

        DB::table('finance_recurrings')->where('id', $recurring->id)->update(['next_due_on' => '2026-10-05']);

        $this->assertSame(0, $this->recurrings->generateDue());
        $this->assertSame(2, FinanceObligation::count());
    }

    public function test_a_broken_definition_does_not_stop_the_others(): void
    {
        $bad = $this->recurrings->create($this->monthlyRent(['name' => 'Bozuk']));
        $good = $this->recurrings->create($this->monthlyRent(['name' => 'Sağlam']));
        DB::table('finance_recurrings')->where('id', $bad->id)->update(['frequency' => 'invalid']);

        $this->assertSame(2, $this->recurrings->generateDue());
        $this->assertSame(2, FinanceObligation::where('recurring_id', $good->id)->count());
        $this->assertSame(0, FinanceObligation::where('recurring_id', $bad->id)->count());
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

    public function test_update_keeps_working_when_party_or_category_became_passive_but_is_unchanged(): void
    {
        $party = FinanceParty::create(['name' => 'Ev Sahibi', 'kind' => PartyKind::Other]);
        $recurring = $this->recurrings->create($this->monthlyRent(['party' => $party]));
        $party->update(['status' => 'passive']);
        $this->rent->update(['status' => 'passive']);

        $this->recurrings->update($recurring->fresh(), ['name' => 'Kira', 'amount' => 2_500_000, 'category' => $this->rent->fresh(), 'party' => $party->fresh()]);

        $this->assertSame(2_500_000, $recurring->fresh()->amount);
    }

    public function test_update_still_rejects_switching_to_a_passive_party_or_category(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $passiveParty = FinanceParty::create(['name' => 'Eski', 'kind' => PartyKind::Other, 'status' => 'passive']);
        $passiveCategory = FinanceCategory::create(['name' => 'Eski kira', 'direction' => CategoryDirection::Expense, 'status' => 'passive']);

        foreach ([['party' => $passiveParty], ['category' => $passiveCategory]] as $change) {
            try {
                $this->recurrings->update($recurring->fresh(), ['name' => 'Kira', 'amount' => 2_000_000, 'category' => $this->rent, ...$change]);
                $this->fail('FinanceRuleException bekleniyordu.');
            } catch (FinanceRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_end_before_start_points_to_the_ends_on_field(): void
    {
        try {
            $this->recurrings->create($this->monthlyRent(['ends_on' => CarbonImmutable::parse('2026-10-01')]));
            $this->fail('Hata bekleniyordu.');
        } catch (FinanceRuleException $e) {
            $this->assertSame('ends_on', $e->field);
        }
    }
}
