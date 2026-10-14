<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Reports\AgingReportService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    private AgingReportService $aging;

    private User $actor;

    private FinanceAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinClock('2026-10-14 09:00:00');

        $this->aging = app(AgingReportService::class);
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pinClock(string $at): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse($at, 'Europe/Istanbul'));
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function obligation(ObligationDirection $direction, int $amount, string $due, ?FinanceParty $party = null)
    {
        return app(ObligationService::class)->create([
            'direction' => $direction, 'amount' => $amount, 'due_on' => $this->day($due), 'description' => 'Kayıt',
            'party' => $party,
        ]);
    }

    private function seedScenario(): void
    {
        $abc = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $xyz = FinanceParty::create(['name' => 'XYZ Kurum', 'kind' => PartyKind::Customer]);
        $r = ObligationDirection::Receivable;

        $partial = $this->obligation($r, 500_000, '2026-10-20', $abc);
        app(LedgerService::class)->pay($partial, $this->cash, 200_000, $this->day('2026-10-14'), $this->actor);
        $this->obligation($r, 100_000, '2026-10-13', $abc);
        $this->obligation($r, 200_000, '2026-09-14', $abc);
        $this->obligation($r, 50_000, '2026-09-13', $abc);

        $this->obligation($r, 400_000, '2026-07-15', $xyz);
        $closed = $this->obligation($r, 900_000, '2026-07-01', $xyz);
        app(LedgerService::class)->pay($closed, $this->cash, 900_000, $this->day('2026-10-14'), $this->actor);
        $cancelled = $this->obligation($r, 800_000, '2026-07-02', $xyz);
        app(ObligationService::class)->cancel($cancelled, 'Vazgeçildi');

        $this->obligation($r, 70_000, '2026-10-01');
        $this->obligation(ObligationDirection::Payable, 999_000, '2026-09-01', $abc);
    }

    public function test_receivable_report_buckets_remaining_by_days_overdue(): void
    {
        $this->seedScenario();

        $report = $this->aging->report(ObligationDirection::Receivable);

        $this->assertSame(['ABC Sigorta', 'XYZ Kurum', 'Carisiz'], array_column($report['rows'], 'party'));
        $this->assertSame(
            ['not_due' => 300_000, 'd0_30' => 300_000, 'd31_60' => 50_000, 'd61_90' => 0, 'd90_plus' => 0],
            $report['rows'][0]['buckets'],
        );
        $this->assertSame(650_000, $report['rows'][0]['total']);
        $this->assertSame(400_000, $report['rows'][1]['buckets']['d90_plus']);
        $this->assertSame(400_000, $report['rows'][1]['total']);
        $this->assertNull($report['rows'][2]['party_id']);
        $this->assertSame(70_000, $report['rows'][2]['buckets']['d0_30']);
        $this->assertSame(
            ['not_due' => 300_000, 'd0_30' => 370_000, 'd31_60' => 50_000, 'd61_90' => 0, 'd90_plus' => 400_000],
            $report['totals'],
        );
        $this->assertSame(1_120_000, $report['total']);
    }

    public function test_payable_report_excludes_receivables(): void
    {
        $this->seedScenario();

        $report = $this->aging->report(ObligationDirection::Payable);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('ABC Sigorta', $report['rows'][0]['party']);
        $this->assertSame(999_000, $report['rows'][0]['buckets']['d31_60']);
        $this->assertSame(999_000, $report['total']);
    }

    public function test_bucket_boundaries_are_exact(): void
    {
        $r = ObligationDirection::Receivable;
        // bugün 14.10.2026: vade → gecikme günü
        $cases = [
            '2026-10-15' => 'not_due', // -1
            '2026-10-14' => 'not_due', // 0
            '2026-10-13' => 'd0_30',   // 1
            '2026-09-14' => 'd0_30',   // 30
            '2026-09-13' => 'd31_60',  // 31
            '2026-08-15' => 'd31_60',  // 60
            '2026-08-14' => 'd61_90',  // 61
            '2026-07-16' => 'd61_90',  // 90
            '2026-07-15' => 'd90_plus', // 91
        ];

        foreach ($cases as $due => $bucket) {
            $party = FinanceParty::create(['name' => "Cari $due", 'kind' => PartyKind::Customer]);
            $this->obligation($r, 1_000, $due, $party);
        }

        $rows = collect($this->aging->report($r)['rows'])->keyBy('party');

        foreach ($cases as $due => $bucket) {
            $buckets = $rows["Cari $due"]['buckets'];
            $this->assertSame(1_000, $buckets[$bucket], "$due → $bucket");
            $this->assertSame(1_000, array_sum($buckets), "$due tek kovada");
        }
    }

    public function test_passive_party_with_open_items_is_listed_keyed_by_party_id(): void
    {
        $passive = FinanceParty::create(['name' => 'Eski Cari', 'kind' => PartyKind::Customer]);
        $active = FinanceParty::create(['name' => 'Yeni Cari', 'kind' => PartyKind::Customer]);
        $this->obligation(ObligationDirection::Receivable, 10_000, '2026-10-01', $passive);
        $this->obligation(ObligationDirection::Receivable, 20_000, '2026-10-01', $active);
        $passive->update(['status' => 'inactive']);

        $rows = $this->aging->report(ObligationDirection::Receivable)['rows'];

        $this->assertSame([$active->id, $passive->id], array_column($rows, 'party_id'));
        $this->assertSame(['Yeni Cari', 'Eski Cari'], array_column($rows, 'party'));
        $this->assertSame([20_000, 10_000], array_column($rows, 'total'));
    }

    public function test_today_argument_is_not_mutated_and_shifts_buckets(): void
    {
        $party = FinanceParty::create(['name' => 'ABC', 'kind' => PartyKind::Customer]);
        $this->obligation(ObligationDirection::Receivable, 5_000, '2026-10-20', $party);
        $today = $this->day('2026-11-20');

        $report = $this->aging->report(ObligationDirection::Receivable, $today);

        $this->assertSame(5_000, $report['rows'][0]['buckets']['d31_60']);
        $this->assertSame('2026-11-20', $today->toDateString());
    }

    public function test_other_organization_obligations_are_excluded(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherUser = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherUser);
        $foreign = FinanceParty::create(['name' => 'Yabancı', 'kind' => PartyKind::Customer]);
        $this->obligation(ObligationDirection::Receivable, 999_000, '2026-09-01', $foreign);

        $this->actingAs($this->actor);
        $this->seedScenario();
        $report = $this->aging->report(ObligationDirection::Receivable);

        $this->assertNotContains('Yabancı', array_column($report['rows'], 'party'));
        $this->assertSame(1_120_000, $report['total']);
    }

    public function test_table_has_headings_rows_and_totals_in_kurus(): void
    {
        $this->seedScenario();

        $table = $this->aging->table(ObligationDirection::Receivable);

        $this->assertSame(['Cari', 'Vadesi gelmemiş', '1–30 gün', '31–60 gün', '61–90 gün', '90+ gün', 'Toplam'], $table['headings']);
        $this->assertSame(['ABC Sigorta', 300_000, 300_000, 50_000, 0, 0, 650_000], $table['rows'][0]);
        $this->assertSame(['Carisiz', 0, 70_000, 0, 0, 0, 70_000], $table['rows'][2]);
        $this->assertSame(['Toplam', 300_000, 370_000, 50_000, 0, 400_000, 1_120_000], $table['totals']);
    }
}
