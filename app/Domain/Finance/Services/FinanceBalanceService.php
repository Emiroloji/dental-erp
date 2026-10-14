<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
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
}
