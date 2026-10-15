@props(['animate' => false])

{{-- Uyarılar: stok durumu listesi; animate: bir ürün sarıdan kırmızıya düşer ve bildirim gelir. --}}
<div class="p-4 sm:p-5">
    <p class="text-[15px] font-medium text-ink">Stok durumu</p>
    <p class="text-[12px] text-ink-muted">Kadıköy Şubesi</p>

    <ul class="mt-4 divide-y divide-line border-y border-line text-[13px]">
        @foreach ([
            ['Muayene eldiveni (M)', '420 adet', 'good'],
            ['Lokal anestezik', '36 ampul', 'warn'],
            ['İmplant 4.3x10', '3 adet', 'flip'],
            ['Dental enjektör 2ml', '180 adet', 'good'],
        ] as [$name, $qty, $level])
            <li class="flex items-center gap-3 py-2.5">
                <span class="flex-1 text-ink truncate">{{ $name }}</span>
                <span class="text-ink-muted tabular-nums whitespace-nowrap">{{ $qty }}</span>
                @if ($level === 'flip' && $animate)
                    <span class="inline-grid w-[62px] justify-items-end">
                        <span class="col-start-1 row-start-1 text-[12px] px-2 py-0.5 rounded bg-status-warn-bg text-status-warn mk-out" style="--d: 1.4s">Sarı</span>
                        <span class="col-start-1 row-start-1 text-[12px] px-2 py-0.5 rounded bg-status-critical-bg text-status-critical font-medium mk-in mk-pulse" style="--d: 1.7s">Kırmızı</span>
                    </span>
                @else
                    @php($chip = ['good' => ['Yeşil', 'bg-status-good-bg text-status-good'], 'warn' => ['Sarı', 'bg-status-warn-bg text-status-warn'], 'flip' => ['Kırmızı', 'bg-status-critical-bg text-status-critical font-medium']][$level])
                    <span class="w-[62px] text-right"><span class="text-[12px] px-2 py-0.5 rounded {{ $chip[1] }}">{{ $chip[0] }}</span></span>
                @endif
            </li>
        @endforeach
    </ul>

    <div @class(['mt-4 flex gap-3 rounded-md border border-status-critical/25 bg-status-critical-bg/60 px-3 py-2.5', 'mk-in' => $animate]) @if ($animate) style="--d: 2.3s" @endif>
        <span class="mt-1 w-2 h-2 rounded-full bg-status-critical shrink-0" aria-hidden="true"></span>
        <p class="text-[12px] leading-relaxed text-ink">
            <span class="font-medium">İmplant 4.3x10 kritik seviyede.</span>
            <span class="text-ink-muted">Kadıköy deposunun sorumlusuna ve klinik sahibine bildirim gitti.</span>
        </p>
    </div>
</div>
