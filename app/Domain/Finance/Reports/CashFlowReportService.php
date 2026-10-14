<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\RecurringFrequency;
use Illuminate\Support\Collection;

/**
 * Gerçekleşen nakit akışı: ay bazında giriş, çıkış ve dönem sonu nakit.
 * Hiçbir toplam saklanmaz; defterden ve hesapların açılış bakiyelerinden türetilir.
 *
 * Giriş/çıkış: hesaplar arası para nakit toplamını değiştirmez, bu yüzden transfer
 * ayakları ve onların iptalleri dışarıda kalır; komisyon gideri ve mutabakat
 * düzeltmesi (gerçek nakit farkı) ve iptaller dahildir. Hesap süzgeci verilirse
 * o hesabın transfer ayakları gerçek giriş/çıkıştır ve dahil edilir.
 * Dönem sonu nakit: ay sonu itibarıyla açılış tarihi gelmiş tüm hesapların
 * (pasifler dahil) açılış bakiyesi ve o güne kadarki bütün hareketler. Şube
 * süzgeci yalnızca giriş/çıkışı daraltır; açılış bakiyesi şubeye bağlı değildir.
 */
class CashFlowReportService
{
    private const TRANSFER_TYPES = [FinanceEntryType::TransferOut->value, FinanceEntryType::TransferIn->value];

    /**
     * @return list<array{month: string, in: int, out: int, net: int, closing: int}>
     */
    public function actual(FinanceReportFilters $filters): array
    {
        $monthEnds = [];

        for ($cursor = $filters->from->startOfMonth(); $cursor->lte($filters->to); $cursor = $cursor->addMonthNoOverflow()) {
            $monthEnds[] = $cursor;
        }

        $lastEnd = end($monthEnds)->endOfMonth()->toDateString();
        $rows = $this->dailyRows($filters, $lastEnd);
        $accounts = FinanceAccount::query()
            ->when($filters->accountId !== null, fn ($q) => $q->whereKey($filters->accountId))
            ->get(['id', 'opening_balance', 'opening_date']);

        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();
        $months = [];

        foreach ($monthEnds as $start) {
            $key = $start->format('Y-m');
            $end = $start->endOfMonth()->toDateString();
            $in = 0;
            $out = 0;
            $movement = 0;

            foreach ($rows as $row) {
                $day = substr((string) $row->occurred_on, 0, 10);
                $signed = (int) $row->signed;

                if ($day <= $end) {
                    $movement += $signed;
                }

                if ($day < $from || $day > $to || substr($day, 0, 7) !== $key || ! $this->counts($row, $filters)) {
                    continue;
                }

                if ($signed > 0) {
                    $in += $signed;
                } else {
                    $out -= $signed;
                }
            }

            $opening = $accounts
                ->filter(fn (FinanceAccount $account) => $account->opening_date->toDateString() <= $end)
                ->sum('opening_balance');

            $months[] = [
                'month' => RecurringFrequency::Monthly->periodLabel($start),
                'in' => $in,
                'out' => $out,
                'net' => $in - $out,
                'closing' => (int) $opening + $movement,
            ];
        }

        return $months;
    }

    /**
     * Dışa aktarım için düz tablo; tutarlar kuruş.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}
     */
    public function table(FinanceReportFilters $filters): array
    {
        $months = $this->actual($filters);
        $rows = array_map(fn (array $m) => [$m['month'], $m['in'], $m['out'], $m['net'], $m['closing']], $months);

        return [
            'headings' => ['Ay', 'Giriş', 'Çıkış', 'Net', 'Dönem sonu nakit'],
            'rows' => $rows,
            'totals' => ['Toplam', array_sum(array_column($months, 'in')), array_sum(array_column($months, 'out')), array_sum(array_column($months, 'net')), $months === [] ? 0 : end($months)['closing']],
        ];
    }

    /**
     * Gün, yön ve tür bazında SQL'de toplanmış satırlar; açılıştan son ay sonuna kadar
     * (dönem sonu nakit geçmişi de gerektirir). Giriş/çıkışa girip girmeyeceğini counts() söyler.
     *
     * @return Collection<int, object>
     */
    private function dailyRows(FinanceReportFilters $filters, string $until)
    {
        return FinanceEntry::query()
            ->leftJoin('finance_entries as original', 'original.id', '=', 'finance_entries.reverses_entry_id')
            ->whereDate('finance_entries.occurred_on', '<=', $until)
            ->when($filters->accountId !== null, fn ($q) => $q->where('finance_entries.account_id', $filters->accountId))
            ->selectRaw('finance_entries.occurred_on as occurred_on, finance_entries.type as type, original.type as original_type, finance_entries.branch_id as branch_id, SUM(finance_entries.direction * finance_entries.amount) as signed')
            ->groupBy('finance_entries.occurred_on', 'finance_entries.type', 'original.type', 'finance_entries.branch_id')
            ->toBase()
            ->get();
    }

    private function counts(object $row, FinanceReportFilters $filters): bool
    {
        if ($filters->branchId !== null && (int) $row->branch_id !== $filters->branchId) {
            return false;
        }

        if ($filters->accountId !== null) {
            return true;
        }

        return ! in_array($row->type, self::TRANSFER_TYPES, true)
            && ! ($row->type === FinanceEntryType::Reversal->value && in_array($row->original_type, self::TRANSFER_TYPES, true));
    }
}
