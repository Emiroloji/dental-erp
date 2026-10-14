@props(['values' => [], 'labels' => [], 'title' => ''])
@php
    use App\Domain\Finance\Support\FinanceChart;
    use App\Domain\Finance\Support\Money;

    $values = array_values($values);
    $w = 720;
    $h = 200;
    $chart = FinanceChart::line($values, $w, $h, 8);
    $clip = 'fc-'.\Illuminate\Support\Str::random(8);
    $summary = $values === [] ? '' : 'İlk: '.Money::format($values[0])
        .', son: '.Money::format($values[count($values) - 1])
        .', en düşük: '.Money::format($chart['min'])
        .', en yüksek: '.Money::format($chart['max']).'.';
@endphp
@if ($values === [])
    <p class="text-sm text-ink-muted">Veri yok</p>
@else
    <figure {{ $attributes->class(['w-full']) }}>
        <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $title }}" class="w-full h-auto">
            <title>{{ $title }}</title>
            <desc>{{ $summary }}</desc>
            <defs>
                <clipPath id="{{ $clip }}"><rect x="0" y="{{ $chart['zero_y'] }}" width="{{ $w }}" height="{{ $h }}" /></clipPath>
            </defs>
            <path d="{{ $chart['area'] }}" clip-path="url(#{{ $clip }})" class="fill-finance-out/15" stroke="none" />
            <line x1="0" x2="{{ $w }}" y1="{{ $chart['zero_y'] }}" y2="{{ $chart['zero_y'] }}" stroke-dasharray="4 4" stroke-width="1" class="stroke-line" />
            <path d="{{ $chart['path'] }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" class="stroke-brand-500" />
        </svg>
        @if (count($labels) >= 2)
            <figcaption class="mt-1 flex justify-between text-xs text-ink-muted">
                <span>{{ $labels[0] }}</span>
                <span>{{ $labels[1] }}</span>
            </figcaption>
        @endif
    </figure>
@endif
