@props(['actual', 'projection'])
@php
    use App\Domain\Finance\Support\Money;
    use Carbon\CarbonImmutable;

    $points = $projection['points'];
    $lastPoint = $points[count($points) - 1];
    $fmt = fn (string $date) => CarbonImmutable::parse($date)->format('d.m.Y');
@endphp
<div>
    <h2 class="mb-2 text-[15px] font-medium text-ink">Gerçekleşen nakit akışı</h2>
    <section class="border border-line rounded-lg bg-surface px-4 sm:px-5 py-4 mb-3">
        <x-finance.bar-chart
            :groups="array_map(fn ($m) => ['in' => $m['in'], 'out' => $m['out']], $actual)"
            :labels="array_column($actual, 'month')"
            title="Aylık nakit girişi ve çıkışı" />
    </section>

    <section class="mb-8 border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Ay</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Giriş</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Çıkış</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Net</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Dönem sonu nakit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($actual as $month)
                    <tr wire:key="cf-{{ $month['month'] }}">
                        <td class="px-3 sm:px-5 py-2.5 whitespace-nowrap">{{ $month['month'] }}</td>
                        <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums text-finance-in">{{ Money::format($month['in']) }}</td>
                        <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums text-finance-out">{{ Money::format($month['out']) }}</td>
                        <td @class(['hidden sm:table-cell px-5 py-2.5 text-right whitespace-nowrap tabular-nums', 'text-finance-out' => $month['net'] < 0])>{{ Money::format($month['net']) }}</td>
                        <td @class(['px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-out' => $month['closing'] < 0])>{{ Money::format($month['closing']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <h2 class="mb-2 text-[15px] font-medium text-ink">Vade bazlı projeksiyon ({{ count($points) - 1 }} gün, tüm aktif hesaplar)</h2>
    @if ($projection['first_negative'])
        <div class="mb-3 rounded-md bg-status-critical/10 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">Nakit {{ $fmt($projection['first_negative']) }}'de eksiye düşüyor.</div>
    @endif

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-3">
        <div class="bg-surface px-4 sm:px-5 py-3">
            <dt class="text-[12px] text-ink-muted">Bugünkü nakit</dt>
            <dd @class(['text-[16px] font-medium tabular-nums', 'text-finance-out' => $projection['start'] < 0])>{{ Money::format($projection['start']) }}</dd>
        </div>
        <div class="bg-surface px-4 sm:px-5 py-3">
            <dt class="text-[12px] text-ink-muted">Beklenen tahsilat</dt>
            <dd class="text-[16px] font-medium tabular-nums text-finance-in">{{ Money::format($projection['receivable']) }}</dd>
        </div>
        <div class="bg-surface px-4 sm:px-5 py-3">
            <dt class="text-[12px] text-ink-muted">Beklenen ödeme</dt>
            <dd class="text-[16px] font-medium tabular-nums text-finance-out">{{ Money::format($projection['payable']) }}</dd>
        </div>
        <div class="bg-surface px-4 sm:px-5 py-3">
            <dt class="text-[12px] text-ink-muted">{{ $fmt($lastPoint['date']) }} tahmini nakit</dt>
            <dd @class(['text-[16px] font-medium tabular-nums', 'text-finance-out' => $lastPoint['balance'] < 0])>{{ Money::format($lastPoint['balance']) }}</dd>
        </div>
    </dl>

    <section class="border border-line rounded-lg bg-surface px-4 sm:px-5 py-4">
        <x-finance.line-chart
            :values="array_column($points, 'balance')"
            :labels="[$fmt($points[0]['date']), $fmt($lastPoint['date'])]"
            title="Tahmini nakit bakiyesi" />
    </section>
</div>
