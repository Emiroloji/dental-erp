<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Reports\CashFlowProjectionService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowProjectionTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowProjectionService $projection;

    private User $actor;

    private FinanceAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinClock('2026-10-14 09:00:00');

        $this->projection = app(CashFlowProjectionService::class);
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-09-01']);
        FinanceAccount::create(['name' => 'Eski', 'type' => AccountType::Bank, 'opening_balance' => 777_000, 'opening_date' => '2026-09-01', 'status' => 'inactive']);

        $this->obligation(ObligationDirection::Payable, 200_000, '2026-10-10');
        $partial = $this->obligation(ObligationDirection::Receivable, 500_000, '2026-10-16');
        $this->obligation(ObligationDirection::Payable, 1_500_000, '2026-10-20');

        $ledger = app(LedgerService::class);
        $ledger->pay($partial, $this->cash, 100_000, $this->day('2026-10-14'), $this->actor);

        $cancelled = $this->obligation(ObligationDirection::Payable, 900_000, '2026-10-17');
        app(ObligationService::class)->cancel($cancelled, 'Vazgeçildi');
        $closed = $this->obligation(ObligationDirection::Payable, 300_000, '2026-10-18');
        $ledger->pay($closed, $this->cash, 300_000, $this->day('2026-10-14'), $this->actor);

        // Tahsilat +100.000, kapanan ödeme −300.000: güncel aktif nakit 1.000.000 olsun.
        $this->cash->update(['opening_balance' => 1_200_000]);
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

    private function obligation(ObligationDirection $direction, int $amount, string $due)
    {
        return app(ObligationService::class)->create([
            'direction' => $direction, 'amount' => $amount, 'due_on' => $this->day($due), 'description' => 'Kayıt',
        ]);
    }

    public function test_projection_piles_overdue_on_today_and_uses_remaining_of_partial_obligation(): void
    {
        $result = $this->projection->project(30, $this->day('2026-10-14'));
        $points = collect($result['points'])->keyBy('date');

        $this->assertSame(1_000_000, $result['start']);
        $this->assertCount(31, $result['points']);
        $this->assertSame(800_000, $points['2026-10-14']['balance']);
        $this->assertSame(200_000, $points['2026-10-14']['out']);
        $this->assertSame(400_000, $points['2026-10-16']['in']);
        $this->assertSame(1_200_000, $points['2026-10-16']['balance']);
        $this->assertSame(-300_000, $points['2026-10-20']['balance']);
        $this->assertSame('2026-10-20', $result['first_negative']);
        $this->assertSame('2026-11-13', $result['points'][30]['date']);
        $this->assertSame(-300_000, $result['points'][30]['balance']);
        $this->assertSame(400_000, $result['receivable']);
        $this->assertSame(1_700_000, $result['payable']);
    }

    public function test_obligations_beyond_horizon_and_no_negative_give_null(): void
    {
        $result = $this->projection->project(30, $this->day('2026-10-14'));
        $short = $this->projection->project(5, $this->day('2026-10-14'));

        $this->assertCount(6, $short['points']);
        $this->assertNull($short['first_negative']);
        $this->assertSame(400_000, $short['receivable']);
        $this->assertSame(200_000, $short['payable']);
        $this->assertNotNull($result['first_negative']);
    }

    public function test_table_lists_only_days_with_movement_plus_last_day(): void
    {
        $table = $this->projection->table(30);

        $this->assertSame(['Tarih', 'Tahsilat', 'Ödeme', 'Tahmini nakit'], $table['headings']);
        $this->assertSame([
            ['14.10.2026', 0, 200_000, 800_000],
            ['16.10.2026', 400_000, 0, 1_200_000],
            ['20.10.2026', 0, 1_500_000, -300_000],
            ['13.11.2026', 0, 0, -300_000],
        ], $table['rows']);
    }
}
