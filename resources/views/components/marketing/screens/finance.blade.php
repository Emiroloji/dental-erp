@props(['animate' => false])

{{-- Finans Özeti: toplam nakit, hesaplar ve 90 günlük nakit projeksiyonu. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <p class="text-[15px] font-medium text-ink">Finans Özeti</p>
        <p class="text-[12px] text-ink-muted">{{ now()->format('d.m.Y') }}</p>
    </div>

    <p class="mt-4 text-[12px] text-ink-muted">Toplam nakit</p>
    <p class="text-[28px] leading-none tracking-[-0.02em] font-semibold text-ink tabular-nums">187.450,00 ₺</p>

    <dl class="mt-4 divide-y divide-line border-y border-line text-[13px]">
        @foreach ([['Kasa', '26.200,00'], ['Banka', '157.750,00'], ['POS', '3.500,00']] as [$account, $balance])
            <div class="flex justify-between gap-3 py-1.5">
                <dt class="text-ink-muted">{{ $account }}</dt>
                <dd class="text-ink tabular-nums whitespace-nowrap">{{ $balance }} ₺</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-4">
        <div class="flex items-baseline justify-between gap-3 text-[12px]">
            <span class="text-ink-muted">90 günlük projeksiyon</span>
            <span class="text-finance-in tabular-nums whitespace-nowrap">206.450,00 ₺</span>
        </div>
        <svg viewBox="0 0 300 70" class="mt-2 w-full h-[70px]" role="img" aria-label="Nakit 187.450 TL; 6.000 TL ödeme ve 25.000 TL tahsilatla 206.450 TL'ye çıkıyor">
            <line x1="0" y1="69.5" x2="300" y2="69.5" class="stroke-line" />
            <path d="M0 42 L95 42 L95 48 L205 48 L205 18 L300 18"
                pathLength="1" fill="none" stroke-width="2" stroke-linejoin="round"
                @class(['stroke-finance-in', 'mk-draw' => $animate]) @if ($animate) style="--d: 0.5s" @endif />
        </svg>
        <ul class="mt-1 flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-[11px] text-ink-muted">
            <li>Depo ödemesi <span class="text-finance-out tabular-nums whitespace-nowrap">−6.000 ₺</span></li>
            <li>Sigorta tahsilatı <span class="text-finance-in tabular-nums whitespace-nowrap">+25.000 ₺</span></li>
        </ul>
    </div>
</div>
