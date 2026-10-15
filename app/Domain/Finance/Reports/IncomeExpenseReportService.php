<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Gelir-Gider raporu: kategori ve ay kırılımı. Hiçbir toplam saklanmaz; defterden türetilir.
 *
 * Dahil: gelir, gider, ödeme/tahsilat ve bunların iptalleri. Transfer ayakları,
 * mutabakat düzeltmesi ve bunların iptalleri dışarıda kalır (transfer komisyonu
 * gider türünde olduğu için girer). Bir satır, iptal ise iptal ettiği kaydın
 * yönüne göre gelir veya gider kovasına düşer; iptal kendi kovasında düşürür.
 */
class IncomeExpenseReportService
{
    private const UNCATEGORIZED = 'Kategorisiz';

    /**
     * @return array{income: list<array{category: string, total: int}>, expense: list<array{category: string, total: int}>, income_total: int, expense_total: int, net: int, months: list<array{month: string, income: int, expense: int, net: int}>}
     */
    public function report(FinanceReportFilters $filters): array
    {
        $rows = $this->dailyRows($filters);

        $names = FinanceCategory::whereIn('id', $rows->pluck('category_id')->filter()->unique())
            ->pluck('name', 'id');

        $income = [];
        $expense = [];
        $monthly = [];

        foreach ($rows as $row) {
            $bucket = (int) $row->bucket;
            $signed = (int) $row->signed;
            // Gelir kovasında +Σ, gider kovasında −Σ: iptaller kendiliğinden düşer.
            $amount = $bucket * $signed;
            $category = $row->category_id === null ? self::UNCATEGORIZED : ($names[$row->category_id] ?? self::UNCATEGORIZED);
            $month = CarbonImmutable::parse(substr((string) $row->occurred_on, 0, 10))->format('Y-m');

            if ($bucket > 0) {
                $income[$category] = ($income[$category] ?? 0) + $amount;
                $monthly[$month]['income'] = ($monthly[$month]['income'] ?? 0) + $amount;
            } else {
                $expense[$category] = ($expense[$category] ?? 0) + $amount;
                $monthly[$month]['expense'] = ($monthly[$month]['expense'] ?? 0) + $amount;
            }
        }

        $incomeTotal = array_sum($income);
        $expenseTotal = array_sum($expense);

        return [
            'income' => $this->ranked($income),
            'expense' => $this->ranked($expense),
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net' => $incomeTotal - $expenseTotal,
            'months' => $this->months($filters, $monthly),
        ];
    }

    /**
     * Dışa aktarım için düz tablo; tutarlar kuruş.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}
     */
    public function table(FinanceReportFilters $filters): array
    {
        $report = $this->report($filters);
        $rows = [];

        foreach ($report['income'] as $line) {
            $rows[] = ['Gelir', $line['category'], $line['total']];
        }

        foreach ($report['expense'] as $line) {
            $rows[] = ['Gider', $line['category'], $line['total']];
        }

        return ['headings' => ['Tür', 'Kategori', 'Tutar'], 'rows' => $rows, 'totals' => ['', 'Net', $report['net']]];
    }

    /**
     * Gün, kategori ve kova bazında SQL'de toplanmış satırlar.
     *
     * @return Collection<int, object>
     */
    private function dailyRows(FinanceReportFilters $filters)
    {
        $included = [FinanceEntryType::Income->value, FinanceEntryType::Expense->value, FinanceEntryType::Payment->value];

        return FinanceEntry::query()
            ->leftJoin('finance_entries as original', 'original.id', '=', 'finance_entries.reverses_entry_id')
            ->where(function ($query) use ($included) {
                $query->whereIn('finance_entries.type', $included)
                    ->orWhere(function ($reversal) use ($included) {
                        $reversal->where('finance_entries.type', FinanceEntryType::Reversal->value)
                            ->whereIn('original.type', $included);
                    });
            })
            ->whereDate('finance_entries.occurred_on', '>=', $filters->from->toDateString())
            ->whereDate('finance_entries.occurred_on', '<=', $filters->to->toDateString())
            ->when($filters->branchId !== null, fn ($q) => $q->where('finance_entries.branch_id', $filters->branchId))
            ->when($filters->accountId !== null, fn ($q) => $q->where('finance_entries.account_id', $filters->accountId))
            ->when($filters->categoryId !== null, fn ($q) => $q->where('finance_entries.category_id', $filters->categoryId))
            ->selectRaw('finance_entries.occurred_on as occurred_on, finance_entries.category_id as category_id, COALESCE(original.direction, finance_entries.direction) as bucket, SUM(finance_entries.direction * finance_entries.amount) as signed')
            ->groupBy('finance_entries.occurred_on', 'finance_entries.category_id')
            ->groupByRaw('COALESCE(original.direction, finance_entries.direction)')
            ->toBase()
            ->get();
    }

    /**
     * Büyükten küçüğe; eşitlikte ada göre. Sıfıra inen kategori listelenmez.
     *
     * @param  array<string, int>  $totals
     * @return list<array{category: string, total: int}>
     */
    private function ranked(array $totals): array
    {
        $lines = [];

        foreach ($totals as $category => $total) {
            if ($total !== 0) {
                $lines[] = ['category' => (string) $category, 'total' => $total];
            }
        }

        usort($lines, fn (array $a, array $b) => [$b['total'], $a['category']] <=> [$a['total'], $b['category']]);

        return $lines;
    }

    /**
     * Dönemdeki her ay, boş aylar 0 ile.
     *
     * @param  array<string, array{income?: int, expense?: int}>  $monthly
     * @return list<array{month: string, income: int, expense: int, net: int}>
     */
    private function months(FinanceReportFilters $filters, array $monthly): array
    {
        $months = [];

        for ($cursor = $filters->from->startOfMonth(); $cursor->lte($filters->to); $cursor = $cursor->addMonthNoOverflow()) {
            $income = $monthly[$cursor->format('Y-m')]['income'] ?? 0;
            $expense = $monthly[$cursor->format('Y-m')]['expense'] ?? 0;

            $months[] = [
                'month' => RecurringFrequency::Monthly->periodLabel($cursor),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];
        }

        return $months;
    }
}
