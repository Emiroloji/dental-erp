<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use Carbon\CarbonImmutable;

/**
 * Vade bazlı nakit projeksiyonu: aktif hesaplardaki bugünkü nakitten başlar, açık ve
 * kısmi yükümlülüklerin kalanını vade gününe ekler. Vadesi geçmişler bugüne yığılır.
 * Hiçbir şey saklanmaz; her çağrıda defterden türetilir.
 */
class CashFlowProjectionService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    /**
     * @return array{start: int, points: list<array{date: string, balance: int, in: int, out: int}>, first_negative: ?string, receivable: int, payable: int}
     */
    public function project(int $days, ?CarbonImmutable $today = null, ?int $start = null): array
    {
        // Savunma amaçlı sınır: ekran girdisi zaten 30/60/90'a indirilir.
        $days = max(1, min($days, 365));
        $today = ($today ?? FinanceCalendar::today())->startOfDay();
        $todayKey = $today->toDateString();
        $lastKey = $today->addDays($days)->toDateString();

        $obligations = FinanceObligation::query()
            ->whereIn('status', [ObligationStatus::Open->value, ObligationStatus::Partial->value])
            ->whereDate('due_on', '<=', $lastKey)
            ->get();
        $paid = $this->balances->paidAmounts($obligations);

        $perDay = [];
        $receivable = 0;
        $payable = 0;

        foreach ($obligations as $obligation) {
            $remaining = $obligation->amount - $paid[$obligation->id];

            if ($remaining <= 0) {
                continue;
            }

            // Tarihler Y-m-d metin olarak karşılaştırılır; geciken bugüne yığılır.
            $key = max($obligation->due_on->toDateString(), $todayKey);

            if ($obligation->direction === ObligationDirection::Receivable) {
                $perDay[$key]['in'] = ($perDay[$key]['in'] ?? 0) + $remaining;
                $receivable += $remaining;
            } else {
                $perDay[$key]['out'] = ($perDay[$key]['out'] ?? 0) + $remaining;
                $payable += $remaining;
            }
        }

        // Çağıran toplam nakdi zaten hesapladıysa tekrar sorgulanmaz.
        $start ??= $this->balances->total();
        $balance = $start;
        $points = [];
        $firstNegative = null;

        for ($i = 0; $i <= $days; $i++) {
            $date = $today->addDays($i)->toDateString();
            $in = $perDay[$date]['in'] ?? 0;
            $out = $perDay[$date]['out'] ?? 0;
            $balance += $in - $out;

            if ($balance < 0 && $firstNegative === null) {
                $firstNegative = $date;
            }

            $points[] = ['date' => $date, 'balance' => $balance, 'in' => $in, 'out' => $out];
        }

        return ['start' => $start, 'points' => $points, 'first_negative' => $firstNegative, 'receivable' => $receivable, 'payable' => $payable];
    }

    /**
     * Dışa aktarım için düz tablo: yalnız hareketli günler ve son gün; tutarlar kuruş.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}
     */
    public function table(int $days): array
    {
        $result = $this->project($days);
        $lastIndex = count($result['points']) - 1;
        $rows = [];

        foreach ($result['points'] as $index => $point) {
            if ($point['in'] !== 0 || $point['out'] !== 0 || $index === $lastIndex) {
                $rows[] = [CarbonImmutable::parse($point['date'])->format('d.m.Y'), $point['in'], $point['out'], $point['balance']];
            }
        }

        return [
            'headings' => ['Tarih', 'Tahsilat', 'Ödeme', 'Tahmini nakit'],
            'rows' => $rows,
            'totals' => ['Toplam', $result['receivable'], $result['payable'], $result['points'][$lastIndex]['balance']],
        ];
    }
}
