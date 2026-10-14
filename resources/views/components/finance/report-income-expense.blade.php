@props(['report'])
@php
    use App\Domain\Finance\Support\Money;
@endphp
<div>
    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        <div class="bg-surface px-5 py-4">
            <dt class="text-[12px] text-ink-muted">Gelir</dt>
            <dd class="text-[20px] font-medium tabular-nums text-finance-in">{{ Money::format($report['income_total']) }}</dd>
        </div>
        <div class="bg-surface px-5 py-4">
            <dt class="text-[12px] text-ink-muted">Gider</dt>
            <dd class="text-[20px] font-medium tabular-nums text-finance-out">{{ Money::format($report['expense_total']) }}</dd>
        </div>
        <div class="bg-surface px-5 py-4">
            <dt class="text-[12px] text-ink-muted">Net</dt>
            <dd @class(['text-[20px] font-medium tabular-nums', 'text-ink' => $report['net'] >= 0, 'text-finance-out' => $report['net'] < 0])>{{ Money::format($report['net']) }}</dd>
        </div>
    </dl>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        @foreach ([['Gelir kategorileri', $report['income'], $report['income_total'], 'text-finance-in'], ['Gider kategorileri', $report['expense'], $report['expense_total'], 'text-finance-out']] as [$title, $lines, $total, $tone])
            <section>
                <h2 class="mb-2 text-[15px] font-medium text-ink">{{ $title }}</h2>
                <div class="border border-line rounded-lg bg-surface overflow-hidden">
                    <table class="w-full text-[14px]">
                        <tbody class="divide-y divide-line">
                            @forelse ($lines as $line)
                                <tr>
                                    <td class="px-4 sm:px-5 py-2.5">{{ $line['category'] }}</td>
                                    <td class="px-4 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums {{ $tone }}">{{ Money::format($line['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-5 py-6 text-center text-ink-muted text-[13px]">Bu dönemde kayıt yok.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line text-[13px]">
                                <td class="px-4 sm:px-5 py-2.5 font-medium">Toplam</td>
                                <td class="px-4 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums font-medium">{{ Money::format($total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    <h2 class="mb-2 text-[15px] font-medium text-ink">Aylık gelir ve gider</h2>
    <section class="border border-line rounded-lg bg-surface px-4 sm:px-5 py-4 mb-6">
        <x-finance.bar-chart
            :groups="array_map(fn ($m) => ['in' => $m['income'], 'out' => $m['expense']], $report['months'])"
            :labels="array_column($report['months'], 'month')"
            title="Aylık gelir ve gider" />
    </section>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Ay</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Gelir</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Gider</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($report['months'] as $month)
                    <tr wire:key="m-{{ $month['month'] }}">
                        <td class="px-3 sm:px-5 py-2.5 whitespace-nowrap">{{ $month['month'] }}</td>
                        <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums text-finance-in">{{ Money::format($month['income']) }}</td>
                        <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums text-finance-out">{{ Money::format($month['expense']) }}</td>
                        <td @class(['px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-out' => $month['net'] < 0])>{{ Money::format($month['net']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
