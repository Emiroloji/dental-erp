@props(['animate' => false])

{{-- Stok: bir ürünün depodaki partileri, FEFO sırasıyla. animate: ilk partiden çıkış yapılır. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-[15px] font-medium text-ink">Kompozit A</p>
            <p class="text-[12px] text-ink-muted">Merkez Şube, Varsayılan Depo</p>
        </div>
        <span class="text-[12px] px-2 py-0.5 rounded bg-status-warn-bg text-status-warn font-medium">Sarı</span>
    </div>

    <table class="mt-4 w-full text-[13px]">
        <caption class="sr-only">Kompozit A ürününün partileri</caption>
        <thead>
            <tr class="text-ink-muted text-[12px]">
                <th scope="col" class="text-left font-normal pb-1.5">Lot</th>
                <th scope="col" class="text-left font-normal pb-1.5">Son kullanma</th>
                <th scope="col" class="text-right font-normal pb-1.5">Miktar</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([['LOT-2291', 12, 18], ['LOT-2310', 164, 40], ['LOT-2344', 426, 60]] as $i => [$lot, $days, $qty])
                <tr @class(['border-t border-line', 'bg-brand-100/50' => $i === 0])>
                    <td class="py-2.5 text-ink">{{ $lot }}</td>
                    <td class="py-2.5">
                        <span class="text-ink">{{ now()->addDays($days)->format('d.m.Y') }}</span>
                        <span @class(['block text-[12px]', $days < 30 ? 'text-status-critical' : 'text-ink-muted'])>{{ $days }} gün kaldı</span>
                    </td>
                    <td class="py-2.5 text-right tabular-nums text-ink whitespace-nowrap">
                        @if ($i === 0 && $animate)
                            <span class="inline-grid">
                                <span class="col-start-1 row-start-1 mk-out" style="--d: 1.6s">{{ $qty }} adet</span>
                                <span class="col-start-1 row-start-1 mk-in font-medium" style="--d: 1.9s">{{ $qty - 2 }} adet</span>
                            </span>
                        @else
                            {{ $qty }} adet
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p @class(['mt-4 rounded-md bg-canvas border border-line px-3 py-2.5 text-[12px] text-ink-muted leading-relaxed', 'mk-in' => $animate]) @if ($animate) style="--d: 1.2s" @endif>
        <span class="text-ink font-medium">Çıkış: 2 adet, klinik içi kullanım.</span>
        Lot seçilmedi; son kullanma tarihi önce dolan LOT-2291 düştü.
    </p>
</div>
