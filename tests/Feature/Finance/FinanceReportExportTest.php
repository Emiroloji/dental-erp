<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\Money;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ReportDownloader;
use App\Domain\Reporting\Services\ReportExportService;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Finans raporlarının kuyruktan Excel/PDF dışa aktarımı (Aşama 36, Görev 9).
 */
class FinanceReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $staff;

    private FinanceAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $this->staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        $this->actingAs($this->admin);
        $this->bank = FinanceAccount::create(['name' => 'Ziraat TL', 'type' => AccountType::Bank, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $income = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        app(LedgerService::class)->income($this->bank, 500_000, CarbonImmutable::parse('2026-10-05'), $income, $this->admin, 'Ekim hasılatı');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function filterInput(): array
    {
        return FinanceReportFilters::fromArray(['from' => '2026-10-01', 'to' => '2026-10-31'], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'))->toArray();
    }

    /** @return list<list<mixed>> */
    private function sheet(ReportExport $export): array
    {
        return IOFactory::load(Storage::disk('local')->path($export->file_path))->getActiveSheet()->toArray();
    }

    public function test_finance_staff_without_reports_permission_exports_finance_report(): void
    {
        $this->actingAs($this->staff);

        $export = app(ReportExportService::class)->request($this->staff, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $this->filterInput()]);

        $export->refresh();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        Storage::disk('local')->assertExists($export->file_path);
        $this->assertStringStartsWith('gelir-gider-raporu-', $export->file_name);

        $this->get(route('reports.exports.download', $export))->assertOk();
    }

    public function test_excel_has_lira_numbers_not_kurus(): void
    {
        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $this->filterInput()]);

        $rows = $this->sheet($export->refresh());

        $this->assertSame(['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net'], $rows[0]);
        $this->assertSame(['Gelir', 'Hasılat'], array_slice($rows[1], 0, 2));
        $this->assertEquals(5000, $rows[1][2]);
        // Toplam satırı (en altta) da liradır.
        $total = end($rows);
        $this->assertSame('Toplam', $total[0]);
        $this->assertEquals(5000, $total[2]);
    }

    public function test_excel_has_monthly_breakdown_rows_with_lira_numbers(): void
    {
        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $this->filterInput()]);

        $month = collect($this->sheet($export->refresh()))->first(fn (array $row) => $row[0] === 'Ay');

        $this->assertNotNull($month);
        $this->assertSame('Ekim 2026', $month[1]);
        $this->assertTrue(is_numeric($month[2]) && is_numeric($month[4]));
        $this->assertEquals(5000, $month[2]);
        // Sıfır tutar boş değil, sayı olarak 0 yazılır.
        $this->assertNotNull($month[3]);
        $this->assertTrue(is_numeric($month[3]));
        $this->assertEquals(0, $month[3]);
        $this->assertEquals(5000, $month[4]);
    }

    public function test_excel_writes_empty_month_as_zero_numbers(): void
    {
        $input = [...$this->filterInput(), 'from' => '2026-09-01'];
        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $input]);

        $months = collect($this->sheet($export->refresh()))->filter(fn (array $row) => $row[0] === 'Ay')->values();

        $this->assertSame('Eylül 2026', $months[0][1]);
        foreach ([2, 3, 4] as $column) {
            $this->assertNotNull($months[0][$column]);
            $this->assertEquals(0, $months[0][$column]);
        }
        $this->assertEquals(5000, $months[1][2]);
    }

    public function test_income_expense_pdf_contains_month_label_and_formatted_amounts(): void
    {
        $captured = null;
        $this->mock(ReportDownloader::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('pdfContentWithDescription')->once()
                ->andReturnUsing(function (string $title, array $table) use (&$captured) {
                    $captured = $table;

                    return 'pdf';
                });
        });

        app(ReportExportService::class)->request($this->admin, ReportType::FinanceIncomeExpense, 'pdf', ['filters' => $this->filterInput()]);

        $month = collect($captured['rows'])->first(fn (array $row) => $row[0] === 'Ay');
        $this->assertSame('Ekim 2026', $month[1]);
        $this->assertSame(Money::format(500_000), $month[2]);
    }

    public function test_pdf_exports_for_every_finance_report_type(): void
    {
        $service = app(ReportExportService::class);
        $parameters = [
            ReportType::FinanceIncomeExpense->value => ['filters' => $this->filterInput()],
            ReportType::FinanceCashFlow->value => ['filters' => $this->filterInput(), 'days' => 60],
            ReportType::FinanceAging->value => ['filters' => [...$this->filterInput(), 'direction' => 'receivable']],
            ReportType::FinanceStatement->value => ['filters' => $this->filterInput(), 'account_id' => $this->bank->id],
        ];

        foreach ($parameters as $type => $params) {
            foreach (['pdf', 'xlsx'] as $format) {
                $export = $service->request($this->admin, ReportType::from($type), $format, $params)->refresh();

                $this->assertSame(ReportExportStatus::Completed, $export->status, "{$type} {$format}: {$export->error}");
                $this->assertNotSame('', Storage::disk('local')->get($export->file_path));
            }
        }
    }

    public function test_statement_excel_has_opening_row_and_lira_balances(): void
    {
        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceStatement, 'xlsx', ['filters' => $this->filterInput(), 'account_id' => $this->bank->id])->refresh();

        $rows = $this->sheet($export);

        $this->assertSame('Dönem başı bakiyesi', $rows[1][2]);
        $this->assertEquals(10000, $rows[1][6]);
        $this->assertEquals(5000, $rows[2][4]);
        $this->assertEquals(15000, $rows[2][6]);
    }

    public function test_finance_staff_cannot_export_stock_report(): void
    {
        $this->actingAs($this->staff);

        $export = app(ReportExportService::class)->request($this->staff, ReportType::Stock, 'xlsx', ['filters' => []])->refresh();

        $this->assertSame(ReportExportStatus::Failed, $export->status);
    }

    public function test_pending_finance_export_fails_when_finance_permission_is_revoked(): void
    {
        $export = ReportExport::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->staff->id, 'report' => ReportType::FinanceCashFlow,
            'format' => 'pdf', 'parameters' => ['filters' => $this->filterInput()], 'status' => ReportExportStatus::Pending,
        ]);
        Permission::where('user_id', $this->staff->id)->delete();

        app(ReportExportService::class)->generate($export);

        $export->refresh();
        $this->assertSame(ReportExportStatus::Failed, $export->status);
        $this->assertSame(route('finance.reports'), $this->staff->notifications->first()->data['url']);
    }

    public function test_download_route_rejects_user_without_any_report_permission(): void
    {
        $nobody = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($nobody);

        $this->get(route('reports.exports.download', 1))->assertForbidden();
    }

    public function test_other_organizations_account_cannot_be_exported_as_statement(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        $foreign = FinanceAccount::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Yabancı', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        try {
            app(ReportExportService::class)->request($this->admin, ReportType::FinanceStatement, 'pdf', ['filters' => $this->filterInput(), 'account_id' => $foreign->id]);
            $this->fail('Yabancı hesap için ekstre üretilmemeliydi.');
        } catch (ModelNotFoundException) {
            // Eşzamanlı kuyrukta istisna yukarı çıkar; kayıt "failed" olarak işaretlenir.
        }

        $this->assertSame(ReportExportStatus::Failed, ReportExport::sole()->status);
    }

    public function test_cash_flow_excel_keeps_actual_total_before_projection_section(): void
    {
        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceCashFlow, 'xlsx', ['filters' => [...$this->filterInput(), 'days' => 30]])->refresh();

        $rows = array_map(fn ($r) => $r[0], $this->sheet($export));
        $totalAt = array_search('Gerçekleşen toplam', $rows, true);
        $headingAt = array_search('Projeksiyon (30 gün, tüm aktif hesaplar)', $rows, true);
        $summaryAt = array_search('Projeksiyon toplamı', $rows, true);

        $this->assertNotFalse($totalAt);
        $this->assertNotFalse($headingAt);
        $this->assertNotFalse($summaryAt);
        // Aylık satırlar -> gerçekleşen toplam -> boş satır -> başlık -> projeksiyon günleri -> özet (en sonda).
        $this->assertSame('Ekim 2026', $rows[$totalAt - 1]);
        $this->assertNull($rows[$totalAt + 1]);
        $this->assertSame($totalAt + 2, $headingAt);
        $this->assertGreaterThan($headingAt, $summaryAt);
        $this->assertSame(count($rows) - 1, $summaryAt);
    }

    public function test_direction_is_ignored_outside_aging_report(): void
    {
        $this->mock(ReportDownloader::class, function ($mock) {
            $mock->shouldReceive('pdfContentWithDescription')->once()
                ->withArgs(fn (string $title, array $table, string $description) => ! str_contains($description, 'Yön:'))
                ->andReturn('pdf');
        });

        $export = app(ReportExportService::class)->request($this->admin, ReportType::FinanceIncomeExpense, 'pdf', ['filters' => [...$this->filterInput(), 'direction' => 'payable']])->refresh();

        $this->assertSame(ReportExportStatus::Completed, $export->status);
    }

    public function test_download_requires_current_read_permission_of_the_reports_module(): void
    {
        Permission::create(['user_id' => $this->staff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($this->staff);
        $export = app(ReportExportService::class)->request($this->staff, ReportType::FinanceIncomeExpense, 'pdf', ['filters' => $this->filterInput()])->refresh();
        $this->get(route('reports.exports.download', $export))->assertOk();

        Permission::where('user_id', $this->staff->id)->where('module', Module::Finance->value)->delete();
        $this->actingAs($this->staff->fresh());

        $this->get(route('reports.exports.download', $export))->assertNotFound();
    }

    public function test_category_filter_reaches_income_expense_export_but_not_cash_flow(): void
    {
        $rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        app(LedgerService::class)->expense($this->bank, 200_000, CarbonImmutable::parse('2026-10-06'), $rent, $this->admin);
        $input = [...$this->filterInput(), 'category_id' => $rent->id];
        $service = app(ReportExportService::class);

        $export = $service->request($this->admin, ReportType::FinanceIncomeExpense, 'xlsx', ['filters' => $input])->refresh();
        $cells = array_merge(...$this->sheet($export));
        $this->assertContains('Kira', $cells);
        $this->assertNotContains('Hasılat', $cells);

        $descriptions = [];
        $this->mock(ReportDownloader::class, function ($mock) use (&$descriptions) {
            $mock->shouldReceive('pdfContentWithDescription')->twice()
                ->andReturnUsing(function (string $title, array $table, string $description) use (&$descriptions) {
                    $descriptions[] = $description;

                    return 'pdf';
                });
        });
        $service->request($this->admin, ReportType::FinanceIncomeExpense, 'pdf', ['filters' => $input]);
        $service->request($this->admin, ReportType::FinanceCashFlow, 'pdf', ['filters' => $input]);

        $this->assertStringContainsString('Kategori: Kira', $descriptions[0]);
        $this->assertStringNotContainsString('Kategori:', $descriptions[1]);
    }
}
