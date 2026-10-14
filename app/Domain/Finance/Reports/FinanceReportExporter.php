<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Reporting\Services\ReportDownloader;
use App\Domain\Reporting\Support\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finans raporlarının dosya içeriği. Rapor servisleri kuruş taşır; bu sınıf yalnızca
 * gösterim için çevirir (PDF: Money::format, Excel: lira sayısı) — hesap yapmaz.
 * Kuyruktaki iş isteyen kullanıcı adına çalıştığı için kapsamlı modeller (hesap,
 * yükümlülükler) o kullanıcının organizasyonuyla sınırlıdır.
 */
class FinanceReportExporter
{
    public function __construct(
        private readonly IncomeExpenseReportService $incomeExpense,
        private readonly CashFlowReportService $cashFlow,
        private readonly CashFlowProjectionService $projection,
        private readonly AgingReportService $aging,
        private readonly AccountStatementService $statements,
        private readonly ReportDownloader $downloader,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  filters, account_id, direction, days
     */
    public function content(ReportType $type, string $format, array $parameters): string
    {
        [$table, $description] = $this->build($type, $parameters);
        $pdf = $format === 'pdf';
        $table = $this->present($table, $pdf);

        return $pdf
            ? $this->downloader->pdfContentWithDescription($type->label(), $table, $description)
            : $this->downloader->excelContent($type->label(), $table);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: array{headings: list<string>, rows: list<list<mixed>>, totals: list<mixed>}, 1: string}
     */
    private function build(ReportType $type, array $parameters): array
    {
        $today = FinanceCalendar::today();
        $input = (array) ($parameters['filters'] ?? []);

        foreach (['days', 'direction', 'account_id'] as $key) {
            if (isset($parameters[$key])) {
                $input[$key] = $parameters[$key];
            }
        }

        $from = $type === ReportType::FinanceCashFlow ? $today->subMonthsNoOverflow(5)->startOfMonth() : $today->startOfMonth();
        $filters = FinanceReportFilters::fromArray($input, $from, $today);

        return match ($type) {
            ReportType::FinanceIncomeExpense => [$this->incomeExpense->table($filters), $filters->describe()],
            ReportType::FinanceCashFlow => [$this->cashFlowTable($filters), $filters->describe().' · Projeksiyon: '.$filters->days.' gün'],
            ReportType::FinanceAging => $this->agingTable($filters, $today),
            ReportType::FinanceStatement => $this->statementTable($filters),
        };
    }

    /**
     * Aylık gerçekleşen tablo; altına projeksiyon günleri aynı sütunlara (Ay=Tarih, Net boş) eklenir.
     */
    private function cashFlowTable(FinanceReportFilters $filters): array
    {
        $table = $this->cashFlow->table($filters);
        $projected = $this->projection->table($filters->days);

        $table['rows'][] = ['', '', '', '', ''];
        $table['rows'][] = ["Projeksiyon ({$filters->days} gün)", 'Tahsilat', 'Ödeme', '', 'Tahmini nakit'];

        foreach ($projected['rows'] as [$date, $in, $out, $balance]) {
            $table['rows'][] = [$date, $in, $out, '', $balance];
        }

        return $table;
    }

    private function agingTable(FinanceReportFilters $filters, CarbonImmutable $today): array
    {
        $direction = $filters->direction ?? ObligationDirection::Receivable;

        return [$this->aging->table($direction), 'Yön: '.$direction->label().' · '.$today->format('d.m.Y').' itibarıyla'];
    }

    private function statementTable(FinanceReportFilters $filters): array
    {
        // Kapsamlı sorgu: başka organizasyonun veya var olmayan hesap ModelNotFoundException atar.
        $account = FinanceAccount::findOrFail($filters->accountId);
        $statement = $this->statements->table($account, $filters->from, $filters->to);
        $totals = $statement['totals'];

        $table = [
            'headings' => $statement['headings'],
            'rows' => [
                [$filters->from->format('d.m.Y'), '', 'Dönem başı bakiyesi', '', '', '', $totals['opening']],
                ...$statement['rows'],
            ],
            'totals' => ['', '', 'Dönem toplamı', '', $totals['in'], $totals['out'], $totals['closing']],
        ];

        return [$table, $filters->describe()];
    }

    /**
     * Kuruş (int) hücreler gösterime çevrilir; metinler olduğu gibi kalır.
     *
     * @param  array{headings: list<string>, rows: list<list<mixed>>, totals?: list<mixed>}  $table
     * @return array{headings: list<string>, rows: Collection<int, list<mixed>>, totals: list<mixed>}
     */
    private function present(array $table, bool $pdf): array
    {
        $cell = fn ($value) => is_int($value) ? ($pdf ? Money::format($value) : round($value / 100, 2)) : $value;

        return [
            'headings' => $table['headings'],
            'rows' => collect($table['rows'])->map(fn (array $row) => array_map($cell, $row)),
            'totals' => array_map($cell, $table['totals'] ?? []),
        ];
    }
}
