<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use Carbon\CarbonImmutable;

/**
 * Açık yükümlülüklerin özeti: geciken ve 7 gün içindeki (gecikenler dahil) kalanlar
 * ile vadesi en yakın kayıtlar. Ödemeler ekranı ve Finans Özeti aynı hesabı kullanır.
 * Tek açık-kayıt sorgusu ve tek toplu ödenen sorgusu yapılır.
 */
class ObligationSummaryService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    /**
     * @return array{overduePayable: int, overdueReceivable: int, overdueCount: int, weekPayable: int, weekReceivable: int, upcoming: list<array{obligation: FinanceObligation, remaining: int}>}
     */
    public function summarize(int $upcomingLimit = 5, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? FinanceCalendar::today())->toDateString();
        $weekEnd = CarbonImmutable::parse($today)->addDays(7)->toDateString();

        $open = FinanceObligation::query()
            ->when($upcomingLimit > 0, fn ($query) => $query->with('party'))
            ->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial])
            ->orderBy('due_on')->orderBy('id')
            ->get();
        $paid = $this->balances->paidAmounts($open);

        $summary = ['overduePayable' => 0, 'overdueReceivable' => 0, 'overdueCount' => 0, 'weekPayable' => 0, 'weekReceivable' => 0, 'upcoming' => []];

        foreach ($open as $obligation) {
            $remaining = $obligation->amount - $paid[$obligation->id];
            $due = $obligation->due_on->toDateString();
            $payable = $obligation->direction === ObligationDirection::Payable;

            if ($due < $today) {
                $summary[$payable ? 'overduePayable' : 'overdueReceivable'] += $remaining;
                $summary['overdueCount']++;
            }

            if ($due <= $weekEnd) {
                $summary[$payable ? 'weekPayable' : 'weekReceivable'] += $remaining;
            }

            if (count($summary['upcoming']) < $upcomingLimit) {
                $summary['upcoming'][] = ['obligation' => $obligation, 'remaining' => $remaining];
            }
        }

        return $summary;
    }
}
