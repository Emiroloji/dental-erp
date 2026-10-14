<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bakiye hiçbir tabloda saklanmaz (karar 1.7): hesap bakiyesi = açılış bakiyesi
 * + defterdeki işaretli tutarların toplamı. Tüm ekranlar ve raporlar bakiyeyi
 * buradan alır; ikinci bir hesaplama yolu açılmaz.
 */
class FinanceBalanceService
{
    public function balance(FinanceAccount $account, ?CarbonInterface $asOf = null): int
    {
        $sum = FinanceEntry::where('account_id', $account->id)
            ->when($asOf, fn ($query) => $query->whereDate('occurred_on', '<=', $asOf->toDateString()))
            ->sum(DB::raw('direction * amount'));

        return $account->opening_balance + (int) $sum;
    }

    /**
     * @param  iterable<FinanceAccount>  $accounts
     * @return array<int, int> hesap id → bakiye
     */
    public function balances(iterable $accounts): array
    {
        $accounts = collect($accounts);

        $sums = FinanceEntry::whereIn('account_id', $accounts->pluck('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(direction * amount) as total')
            ->pluck('total', 'account_id');

        return $accounts->mapWithKeys(fn (FinanceAccount $account) => [
            $account->id => $account->opening_balance + (int) ($sums[$account->id] ?? 0),
        ])->all();
    }

    /**
     * Aktif hesaplardaki toplam nakit.
     */
    public function total(): int
    {
        return array_sum($this->balances(FinanceAccount::where('status', 'active')->get()));
    }

    /**
     * Yükümlülüğe yapılmış net ödeme: bağlı payment kayıtları ve onların
     * iptalleri toplamı, yükümlülüğün yönüyle pozitife çevrilir.
     */
    public function paid(FinanceObligation $obligation): int
    {
        return $this->paidAmounts([$obligation])[$obligation->id];
    }

    public function remaining(FinanceObligation $obligation): int
    {
        if ($obligation->status === ObligationStatus::Cancelled) {
            return 0;
        }

        return $obligation->amount - $this->paid($obligation);
    }

    /**
     * @param  iterable<FinanceObligation>  $obligations
     * @return array<int, int> yükümlülük id → ödenen
     */
    public function paidAmounts(iterable $obligations): array
    {
        $obligations = collect($obligations);

        $sums = FinanceEntry::whereIn('obligation_id', $obligations->pluck('id'))
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, SUM(direction * amount) as total')
            ->pluck('total', 'obligation_id');

        return $obligations->mapWithKeys(fn (FinanceObligation $obligation) => [
            $obligation->id => (int) ($sums[$obligation->id] ?? 0) * $obligation->direction->entryDirection(),
        ])->all();
    }

    /**
     * Cari bakiye: açık ve kısmi yükümlülüklerin kalanı. net > 0: cari bize
     * borçlu; net < 0: biz cariye borçluyuz. Yükümlülüğe bağlanmamış cari
     * hareketleri ekstrede görünür ama bakiyeyi değiştirmez (spec bölüm 1).
     *
     * @param  iterable<FinanceParty>  $parties
     * @return array<int, array{receivable: int, payable: int, net: int}>
     */
    public function partyBalances(iterable $parties): array
    {
        $parties = collect($parties);
        $result = $parties->mapWithKeys(fn (FinanceParty $party) => [$party->id => ['receivable' => 0, 'payable' => 0, 'net' => 0]])->all();

        $open = FinanceObligation::whereIn('party_id', $parties->pluck('id'))
            ->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial])
            ->get();
        $paid = $this->paidAmounts($open);

        foreach ($open as $obligation) {
            $key = $obligation->direction === ObligationDirection::Receivable ? 'receivable' : 'payable';
            $result[$obligation->party_id][$key] += $obligation->amount - $paid[$obligation->id];
        }

        foreach ($result as $id => $row) {
            $result[$id]['net'] = $row['receivable'] - $row['payable'];
        }

        return $result;
    }
}
