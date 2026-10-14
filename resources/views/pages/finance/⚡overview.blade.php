<?php

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Reports\CashFlowProjectionService;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Reports\IncomeExpenseReportService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\ObligationSummaryService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public function with(
        FinanceBalanceService $balances,
        IncomeExpenseReportService $incomeExpense,
        CashFlowProjectionService $projection,
        ObligationSummaryService $obligations,
    ): array {
        $today = FinanceCalendar::today();
        $accounts = FinanceAccount::where('status', 'active')->orderBy('name')->get();

        if ($accounts->isEmpty()) {
            return ['accounts' => $accounts];
        }

        $accountBalances = $balances->balances($accounts);

        return [
            'accounts' => $accounts,
            'accountBalances' => $accountBalances,
            // Toplam nakit, aktif hesapların bakiye toplamıdır (Nakit Akışı raporunun dönem sonu nakdi pasif hesapları da içerir).
            'total' => array_sum($accountBalances),
            'month' => $incomeExpense->report(FinanceReportFilters::fromArray([], $today->startOfMonth(), $today)),
            'summary' => $obligations->summarize(5, $today),
            'projection' => $projection->project(90, $today, array_sum($accountBalances)),
            'today' => $today,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
        <p class="text-[14px] text-ink-muted mt-1">Nakit durumun, bu ayın seyri ve önümüzdeki günlerin ödeme ve tahsilatları.</p>
    </div>

    <x-finance-tabs />

    @if ($accounts->isEmpty())
        <div class="border border-dashed border-line rounded-lg bg-surface px-6 py-10 text-center">
            <p class="text-[14px] text-ink">Önce bir kasa veya banka hesabı tanımla.</p>
            <a href="{{ route('finance.accounts') }}" wire:navigate class="inline-block mt-3 text-[14px] text-brand-600 hover:underline">Hesaplar'a git →</a>
        </div>
    @else
        @php
            $fmt = fn (string $date) => CarbonImmutable::parse($date)->format('d.m.Y');
            $points = $projection['points'];
            $lastPoint = $points[array_key_last($points)];
            $negativeAt = $projection['first_negative'];
            $negativeBalance = $negativeAt === null ? 0 : collect($points)->firstWhere('date', $negativeAt)['balance'];
        @endphp

        <div class="mb-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="border border-line rounded-lg bg-surface px-4 py-3">
                <div class="text-[12px] text-ink-muted">Toplam nakit <span class="text-[11px]">(aktif hesaplar)</span></div>
                <div @class(['mt-1 text-[18px] sm:text-[22px] font-medium tabular-nums', 'text-ink' => $total >= 0, 'text-finance-out' => $total < 0])>{{ Money::format($total) }}</div>
            </div>

            <div class="border border-line rounded-lg bg-surface px-4 py-3">
                <div class="text-[12px] text-ink-muted">Bu ay (net)</div>
                <div @class(['mt-1 text-[18px] sm:text-[22px] font-medium tabular-nums', 'text-ink' => $month['net'] >= 0, 'text-finance-out' => $month['net'] < 0])>{{ Money::format($month['net']) }}</div>
                <dl class="mt-1 text-[12px] tabular-nums">
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Gelir</dt><dd class="text-finance-in">{{ Money::format($month['income_total']) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Gider</dt><dd class="text-finance-out">{{ Money::format($month['expense_total']) }}</dd></div>
                </dl>
            </div>

            <div class="border border-line rounded-lg bg-surface px-4 py-3">
                <div class="text-[12px] text-ink-muted">7 gün içinde <span class="text-[11px]">(gecikenler dahil)</span></div>
                <dl class="mt-1 text-[12px] tabular-nums">
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Ödenecek</dt><dd class="text-[15px] font-medium {{ $summary['weekPayable'] > 0 ? 'text-finance-out' : 'text-ink-muted' }}">{{ $summary['weekPayable'] > 0 ? Money::format($summary['weekPayable']) : '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Tahsil edilecek</dt><dd class="text-[15px] font-medium {{ $summary['weekReceivable'] > 0 ? 'text-finance-in' : 'text-ink-muted' }}">{{ $summary['weekReceivable'] > 0 ? Money::format($summary['weekReceivable']) : '—' }}</dd></div>
                </dl>
            </div>

            <a href="{{ route('finance.obligations', ['tab' => 'overdue']) }}" wire:navigate class="border border-line rounded-lg bg-surface px-4 py-3 hover:border-ink-muted focus-visible:outline-2 focus-visible:outline-brand-500">
                <div class="text-[12px] text-ink-muted">Geciken @if ($summary['overdueCount'] > 0)<span class="text-[11px]">({{ $summary['overdueCount'] }} kayıt)</span>@endif</div>
                <dl class="mt-1 text-[12px] tabular-nums">
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Ödeme</dt><dd class="text-[15px] font-medium {{ $summary['overduePayable'] > 0 ? 'text-finance-out' : 'text-ink-muted' }}">{{ $summary['overduePayable'] > 0 ? Money::format($summary['overduePayable']) : '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Tahsilat</dt><dd class="text-[15px] font-medium {{ $summary['overdueReceivable'] > 0 ? 'text-finance-in' : 'text-ink-muted' }}">{{ $summary['overdueReceivable'] > 0 ? Money::format($summary['overdueReceivable']) : '—' }}</dd></div>
                </dl>
            </a>
        </div>

        <section class="mb-6 border border-line rounded-lg bg-surface px-4 sm:px-5 py-4">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 class="text-[15px] font-medium text-ink">90 günlük nakit projeksiyonu</h2>
                <a href="{{ route('finance.reports', ['report' => 'cash_flow']) }}" wire:navigate class="text-[13px] text-brand-600 hover:underline">Raporlar → Nakit Akışı</a>
            </div>
            @if ($negativeAt !== null)
                <div role="alert" class="mb-3 rounded-md border border-finance-out/30 bg-finance-out/10 px-4 py-2.5 text-[13px] text-finance-out">
                    Nakit {{ $fmt($negativeAt) }} tarihinde eksiye düşüyor ({{ Money::format($negativeBalance) }}). Vadesi yaklaşan ödemeler için tahsilat veya hesaplar arası aktarım planla.
                </div>
            @else
                <p class="mb-3 text-[13px] text-ink-muted">Nakit eksiye düşmüyor: {{ $fmt($lastPoint['date']) }} tarihinde tahmini {{ Money::format($lastPoint['balance']) }}.</p>
            @endif
            <x-finance.line-chart
                :values="array_column($points, 'balance')"
                :labels="[$fmt($points[0]['date']), $fmt($lastPoint['date'])]"
                title="90 günlük tahmini nakit bakiyesi" />
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="border border-line rounded-lg bg-surface">
                <h2 class="px-4 sm:px-5 py-3 text-[15px] font-medium text-ink border-b border-line">Hesaplar</h2>
                <ul class="divide-y divide-line">
                    @foreach ($accounts as $account)
                        <li wire:key="account-{{ $account->id }}">
                            <a href="{{ route('finance.accounts.show', $account) }}" wire:navigate class="flex items-baseline justify-between gap-3 px-4 sm:px-5 py-3 text-[14px] hover:bg-line/40">
                                <span class="text-ink min-w-0 truncate">{{ $account->name }}</span>
                                <span @class(['tabular-nums whitespace-nowrap font-medium', 'text-ink' => $accountBalances[$account->id] >= 0, 'text-finance-out' => $accountBalances[$account->id] < 0])>{{ Money::format($accountBalances[$account->id]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="border border-line rounded-lg bg-surface">
                <div class="flex items-baseline justify-between gap-3 px-4 sm:px-5 py-3 border-b border-line">
                    <h2 class="text-[15px] font-medium text-ink">Yaklaşan</h2>
                    <a href="{{ route('finance.obligations') }}" wire:navigate class="text-[13px] text-brand-600 hover:underline">Tümü</a>
                </div>
                @if ($summary['upcoming'] === [])
                    <p class="px-5 py-8 text-center text-[13px] text-ink-muted">Vadesi bekleyen kayıt yok.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($summary['upcoming'] as $row)
                            @php
                                $obligation = $row['obligation'];
                                $isPayable = $obligation->direction === ObligationDirection::Payable;
                                $late = $obligation->due_on->toDateString() < $today->toDateString();
                            @endphp
                            <li wire:key="upcoming-{{ $obligation->id }}" class="flex items-start justify-between gap-3 px-4 sm:px-5 py-3 text-[14px]">
                                <div class="flex min-w-0 gap-3">
                                    <span @class(['w-[4.5rem] shrink-0 tabular-nums', 'text-finance-out font-medium' => $late, 'text-ink-muted' => ! $late])>{{ $obligation->due_on->format('d.m.Y') }}</span>
                                    <div class="min-w-0">
                                        <div class="text-ink truncate">{{ $obligation->description }}</div>
                                        <div class="text-[12px] text-ink-muted truncate">{{ $obligation->party ? $obligation->party->name.' · ' : '' }}{{ $obligation->direction->label() }}</div>
                                    </div>
                                </div>
                                <span class="tabular-nums whitespace-nowrap font-medium {{ $isPayable ? 'text-finance-out' : 'text-finance-in' }}">{{ Money::format($row['remaining']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif
</div>
