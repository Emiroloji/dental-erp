<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Reports\AccountStatementService;
use App\Domain\Finance\Reports\CashFlowProjectionService;
use App\Domain\Finance\Reports\CashFlowReportService;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Reports\IncomeExpenseReportService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reporting\Services\ReportExportService;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 36 Doğrulama: ekstre farkı düzeltme hareketiyle kapanır; projeksiyon elle
 * hesaplanmış senaryoyla birebir; Excel dökümü açılır; rakamlar özet ile raporlar
 * arasında tutarlı. Ek: izolasyon.
 */
class Stage36FinanceReportsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stage_36_verification(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Europe/Istanbul'));

        $clinic = Organization::create(['name' => 'Gülüş Kliniği', 'status' => 'active', 'plan' => 'professional']);
        $admin = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $ledger = app(LedgerService::class);
        $balances = app(FinanceBalanceService::class);
        $day = fn (string $date) => CarbonImmutable::parse($date);

        // 1) Hesaplar ve hareketler
        $cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);

        $ledger->income($cash, 250_000, $day('2026-10-02'), $revenue, $admin, 'Hasılat');
        $ledger->expense($cash, 100_000, $day('2026-10-03'), $rent, $admin, 'Kira');
        $ledger->transfer($cash, $bank, 300_000, $day('2026-10-04'), $admin);

        $this->assertSame(850_000, $balances->balance($cash));
        $this->assertSame(300_000, $balances->balance($bank));

        // 2) Mutabakat: ekstre 2.950, sistem 3.000 → −5.000 kuruş fark düzeltmeyle kapanır
        app(ReconciliationService::class)->reconcile($bank, $day('2026-10-04'), 295_000, $admin, true, 'Ekstre farkı');
        $this->assertSame(295_000, $balances->balance($bank));
        $statement = app(AccountStatementService::class)->statement($bank, $day('2026-10-04'), $day('2026-10-14'));
        $this->assertSame(295_000, $statement['closing']);

        // 3) Yükümlülükler ve 30 günlük projeksiyon
        FinanceObligation::create(['direction' => ObligationDirection::Receivable, 'amount' => 400_000, 'due_on' => '2026-10-16', 'description' => 'Sigorta hakedişi']);
        FinanceObligation::create(['direction' => ObligationDirection::Payable, 'amount' => 1_500_000, 'due_on' => '2026-10-20', 'description' => 'Laboratuvar faturası']);

        $total = $balances->total();
        $this->assertSame(1_145_000, $total);

        $projection = app(CashFlowProjectionService::class)->project(30, $day('2026-10-14'), $total);
        $this->assertSame(1_145_000, $projection['start']);
        $points = collect($projection['points'])->keyBy('date');
        $this->assertSame(1_545_000, $points['2026-10-16']['balance']);
        $this->assertSame(45_000, $points['2026-10-20']['balance']);
        $this->assertNull($projection['first_negative']);

        // 4) Gelir-Gider (Ekim): düzeltme ve transfer hariç
        $filters = FinanceReportFilters::fromArray(['from' => '2026-10-01', 'to' => '2026-10-31'], $day('2026-10-01'), $day('2026-10-31'));
        $report = app(IncomeExpenseReportService::class)->report($filters);
        $this->assertSame(250_000, $report['income_total']);
        $this->assertSame(100_000, $report['expense_total']);
        $this->assertSame(150_000, $report['net']);

        // 5) Tutarlılık: Özet ile raporlar aynı rakamları gösterir
        Livewire::test('pages::finance.overview')
            ->assertSee('Toplam nakit')
            ->assertSee('11.450,00 ₺')
            ->assertSee('2.500,00 ₺')
            ->assertSee('1.000,00 ₺');
        $this->get(route('finance.overview'))->assertOk()->assertSee('11.450,00 ₺');

        $actual = app(CashFlowReportService::class)->actual($filters);
        $this->assertSame($total, end($actual)['closing']);
        $component = Livewire::test('pages::finance.reports', ['report' => 'cash_flow'])
            ->call('selectReport', 'cash_flow')
            ->assertSee('11.450,00 ₺');
        $shown = $component->viewData('cash');
        $this->assertSame(1_145_000, end($shown)['closing']);

        // 6) Dışa aktarım: Gelir-Gider Excel
        $export = app(ReportExportService::class)->request($admin, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $filters->toArray()])->refresh();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        Storage::disk('local')->assertExists($export->file_path);
        $this->get(route('reports.exports.download', $export))->assertOk();

        // 7) İzolasyon
        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        Livewire::test('pages::finance.overview')->assertDontSee('11.450,00 ₺')->assertSee('Önce bir kasa veya banka hesabı tanımla');
        $this->get(route('finance.overview'))->assertOk()->assertDontSee('11.450,00 ₺');
        $this->get(route('finance.accounts.show', $bank))->assertNotFound();
    }
}
