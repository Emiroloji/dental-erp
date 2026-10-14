<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use Carbon\CarbonInterface;

/**
 * Hesap ekstresi: dönem başı bakiyesi, dönemdeki hareketler ve yürüyen bakiye.
 * Hiçbir bakiye saklanmaz; açılış ve kapanış FinanceBalanceService'ten türetilir.
 */
class AccountStatementService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    /**
     * @return array{opening: int, rows: list<array{entry: FinanceEntry, balance: int}>, closing: int, in: int, out: int}
     */
    public function statement(FinanceAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        // Çağıranın (değişebilir) Carbon nesnesi bozulmasın: değişmez kopyalarla çalışılır.
        $from = $from->toImmutable();
        $to = $to->toImmutable();

        // Açılış tarihinden önceki bir gün için bakiye servisi açılış bakiyesini döndürür.
        $opening = $this->balances->balance($account, $from->subDay());

        $entries = FinanceEntry::where('account_id', $account->id)
            ->whereDate('occurred_on', '>=', $from->toDateString())
            ->whereDate('occurred_on', '<=', $to->toDateString())
            ->with(['party', 'reversal'])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $in = 0;
        $out = 0;
        $rows = [];

        foreach ($entries as $entry) {
            $signed = $entry->signedAmount();
            $running += $signed;
            if ($signed > 0) {
                $in += $signed;
            } else {
                $out += -$signed;
            }
            $rows[] = ['entry' => $entry, 'balance' => $running];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running, 'in' => $in, 'out' => $out];
    }

    /**
     * Dışa aktarım için düz tablo; tutarlar kuruş.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: array{opening: int, in: int, out: int, closing: int}}
     */
    public function table(FinanceAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $statement = $this->statement($account, $from, $to);

        $rows = array_map(function (array $row) {
            $entry = $row['entry'];
            $signed = $entry->signedAmount();

            return [
                $entry->occurred_on->format('d.m.Y'),
                $entry->label(),
                (string) $entry->description,
                (string) ($entry->party?->name ?? ''),
                $signed > 0 ? $signed : 0,
                $signed < 0 ? -$signed : 0,
                $row['balance'],
            ];
        }, $statement['rows']);

        return [
            'headings' => ['Tarih', 'Tür', 'Açıklama', 'Cari', 'Giriş', 'Çıkış', 'Bakiye'],
            'rows' => $rows,
            'totals' => ['opening' => $statement['opening'], 'in' => $statement['in'], 'out' => $statement['out'], 'closing' => $statement['closing']],
        ];
    }
}
