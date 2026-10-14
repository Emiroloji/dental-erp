@props(['groups' => [], 'labels' => [], 'title' => ''])
@php
    use App\Domain\Finance\Support\FinanceChart;
    use App\Domain\Finance\Support\Money;

    $groups = array_values($groups);
    $w = 720;
    $h = 200;
    $chart = FinanceChart::bars($groups, $w, $h, 8);
    $totalIn = array_sum(array_column($groups, 'in'));
    $totalOut = array_sum(array_column($groups, 'out'));
    $summary = 'Toplam gelir: '.Money::format($totalIn).', toplam gider: '.Money::format($totalOut)
        .', en yüksek aylık tutar: '.Money::format($chart['max']).'.';
    $last = count($labels) - 1;
@endphp
@if ($groups === [])
    <p class="text-sm text-ink-muted">Veri yok</p>
@else
    <figure {{ $attributes->class(['w-full']) }}>
        <div class="mb-1 flex gap-4 text-xs text-ink-muted">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-finance-in"></span>Gelir</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-finance-out"></span>Gider</span>
        </div>
        <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $title }}" class="w-full h-auto">
            <title>{{ $title }}</title>
            <desc>{{ $summary }}</desc>
            <line x1="0" x2="{{ $w }}" y1="{{ $chart['zero_y'] }}" y2="{{ $chart['zero_y'] }}" stroke-width="1" class="stroke-line" />
            @foreach ($chart['bars'] as $bar)
                @if ($bar['kind'] === 'in')
                    <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="2" class="fill-finance-in" />
                @else
                    <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="2" class="fill-finance-out" />
                @endif
            @endforeach
        </svg>
        @if ($labels !== [])
            <figcaption class="mt-1 flex justify-between text-xs text-ink-muted">
                @foreach ($labels as $i => $label)
                    <span @class(['hidden sm:inline' => $i !== 0 && $i !== $last])>{{ $label }}</span>
                @endforeach
            </figcaption>
        @endif
    </figure>
@endif
