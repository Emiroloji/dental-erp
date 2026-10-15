{{-- Satın alma: kısmi teslim edilmiş bir sipariş. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-[15px] font-medium text-ink">SA-00014</p>
            <p class="text-[12px] text-ink-muted">Dental Medikal Depo, onaylayan klinik sahibi</p>
        </div>
        <span class="text-[12px] px-2 py-0.5 rounded bg-brand-100 text-brand-600 font-medium">Kısmi teslim</span>
    </div>

    <ul class="mt-4 space-y-3 text-[13px]">
        @foreach ([['Muayene eldiveni (M)', 'kutu', 10, 8], ['Dental enjektör 2ml', 'adet', 200, 200], ['Kompozit A', 'adet', 40, 0]] as [$product, $unit, $ordered, $received])
            <li>
                <div class="flex justify-between gap-3">
                    <span class="text-ink truncate">{{ $product }}</span>
                    <span class="text-ink-muted tabular-nums whitespace-nowrap">{{ $received }} / {{ $ordered }} {{ $unit }}</span>
                </div>
                <div class="mt-1.5 h-1.5 rounded-full bg-canvas border border-line overflow-hidden">
                    <div class="h-full bg-brand-500 rounded-full" style="width: {{ round($received / $ordered * 100) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>

    <p class="mt-4 text-[12px] text-ink-muted leading-relaxed">
        Gelen 8 kutu lot, son kullanma tarihi ve fatura numarasıyla stoğa girdi; kalan 2 kutu açık siparişte bekliyor.
    </p>
</div>
