<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cari yaşlandırma: açık ve kısmi yükümlülüklerin kalanı, vadesi geçen gün sayısına
 * göre kovalara ayrılır. Hiçbir toplam saklanmaz; kalan defterden türetilir.
 *
 * Gecikme günü iki saf Y-m-d tarihinin farkıdır (bugün Europe/Istanbul, vade tarih
 * cast'i); anlık karşılaştırması yoktur. Satırlar cari id'sine göre anahtarlanır
 * (aynı adlı iki cari karışmaz); pasif cariler açık kalemi varsa listelenir; cari
 * bağı olmayan kayıtlar "Carisiz" satırındadır.
 */
class AgingReportService
{
    public const BUCKETS = ['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus'];

    private const LABELS = [
        'not_due' => 'Vadesi gelmemiş',
        'd0_30' => '1–30 gün',
        'd31_60' => '31–60 gün',
        'd61_90' => '61–90 gün',
        'd90_plus' => '90+ gün',
    ];

    private const NO_PARTY = 'Carisiz';

    public function __construct(private readonly FinanceBalanceService $balances) {}

    public function bucketLabel(string $bucket): string
    {
        return self::LABELS[$bucket];
    }

    /**
     * @return array{rows: list<array{party: string, party_id: ?int, buckets: array<string, int>, total: int}>, totals: array<string, int>, total: int}
     */
    public function report(ObligationDirection $direction, ?CarbonInterface $today = null): array
    {
        $todayDate = ($today === null ? FinanceCalendar::today() : CarbonImmutable::instance($today))->toDateString();

        $obligations = FinanceObligation::query()
            ->where('direction', $direction->value)
            ->whereIn('status', [ObligationStatus::Open->value, ObligationStatus::Partial->value])
            ->get();
        $paid = $this->balances->paidAmounts($obligations);
        $names = FinanceParty::whereIn('id', $obligations->pluck('party_id')->filter()->unique())->pluck('name', 'id');

        $empty = array_fill_keys(self::BUCKETS, 0);
        $rows = [];
        $totals = $empty;

        foreach ($obligations as $obligation) {
            $remaining = $obligation->amount - $paid[$obligation->id];

            if ($remaining <= 0) {
                continue;
            }

            $key = $obligation->party_id ?? 0;
            $rows[$key] ??= [
                'party' => $obligation->party_id === null ? self::NO_PARTY : (string) ($names[$obligation->party_id] ?? self::NO_PARTY),
                'party_id' => $obligation->party_id,
                'buckets' => $empty,
                'total' => 0,
            ];

            $bucket = $this->bucket($this->daysOverdue($obligation->due_on->toDateString(), $todayDate));
            $rows[$key]['buckets'][$bucket] += $remaining;
            $rows[$key]['total'] += $remaining;
            $totals[$bucket] += $remaining;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$b['total'], $a['party'], $a['party_id']] <=> [$a['total'], $b['party'], $b['party_id']]);

        return ['rows' => $rows, 'totals' => $totals, 'total' => array_sum($totals)];
    }

    /**
     * Dışa aktarım için düz tablo; tutarlar kuruş.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}
     */
    public function table(ObligationDirection $direction): array
    {
        $report = $this->report($direction);
        $rows = array_map(
            fn (array $row) => [$row['party'], ...array_values($row['buckets']), $row['total']],
            $report['rows'],
        );

        return [
            'headings' => ['Cari', ...array_values(self::LABELS), 'Toplam'],
            'rows' => $rows,
            'totals' => ['Toplam', ...array_values($report['totals']), $report['total']],
        ];
    }

    /**
     * İki Y-m-d tarihi arasındaki tam gün farkı (bugün − vade); gelecekteki vade negatiftir.
     */
    private function daysOverdue(string $dueOn, string $today): int
    {
        return intdiv(strtotime($today.' UTC') - strtotime($dueOn.' UTC'), 86400);
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => 'not_due',
            $days <= 30 => 'd0_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
