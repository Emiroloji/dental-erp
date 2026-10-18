{{-- Gelen e-fatura: kalemler, tıbbi cihaz kimlikleri ve eşleşmeler. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[15px] font-medium text-ink font-mono">DMD2026000451</p>
            <p class="text-[12px] text-ink-muted">Dental Medikal Depo, 17.10.2026</p>
        </div>
        <p class="text-[15px] font-medium text-ink tabular-nums whitespace-nowrap">23.705,00 ₺</p>
    </div>

    <ul class="mt-4 divide-y divide-line border-y border-line text-[13px]">
        @foreach ([
            ['İmplant 4.3x10', '2 adet', 'NA-1002, NA-1003'],
            ['Kollajen membran 25x25', '4 adet', 'Lot BG-55'],
        ] as [$product, $quantity, $tracking])
            <li class="py-2.5">
                <div class="flex justify-between gap-3">
                    <span class="text-ink truncate">{{ $product }}</span>
                    <span class="text-ink-muted whitespace-nowrap">{{ $quantity }}</span>
                </div>
                <span class="block font-mono text-[12px] text-ink-muted">{{ $tracking }}</span>
            </li>
        @endforeach
    </ul>

    <ul class="mt-4 flex flex-wrap gap-2 text-[12px]">
        <li class="rounded px-2 py-1 bg-status-good-bg text-status-good">Tedarikçi eşleşti</li>
        <li class="rounded px-2 py-1 bg-status-good-bg text-status-good">Teslim kaydı var</li>
        <li class="rounded px-2 py-1 border border-line text-ink">ÜTS'de 2 kabul bekleyen</li>
        <li class="rounded px-2 py-1 border border-line text-ink">Finans'a aktar</li>
    </ul>
</div>
