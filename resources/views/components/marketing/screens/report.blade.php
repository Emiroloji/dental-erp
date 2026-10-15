{{-- Raporlar ve asistan: düz Türkçe soru, cevabı rapor olarak. --}}
<div class="p-4 sm:p-5">
    <p class="text-[15px] font-medium text-ink">Asistan</p>

    <p class="mt-3 ml-auto max-w-[85%] rounded-lg rounded-br-sm bg-panel-900 text-white px-3 py-2 text-[13px]">
        Geçen ay Kadıköy'de en çok hangi ürünler kullanıldı?
    </p>

    <div class="mt-3 rounded-lg border border-line px-3 py-3">
        <p class="text-[12px] text-ink-muted">Kullanım raporu, Kadıköy Şubesi, {{ now()->subMonthNoOverflow()->translatedFormat('F Y') }}</p>
        <ul class="mt-2.5 space-y-2 text-[12px]">
            @foreach ([['Muayene eldiveni (M)', 1450, 100], ['Pamuk rulo', 980, 68], ['Lokal anestezik', 312, 22], ['Dental enjektör 2ml', 290, 20]] as [$product, $qty, $pct])
                <li>
                    <div class="flex justify-between gap-3"><span class="text-ink truncate">{{ $product }}</span><span class="text-ink-muted tabular-nums">{{ number_format($qty, 0, ',', '.') }}</span></div>
                    <div class="mt-1 h-1.5 rounded-full bg-brand-500/80" style="width: {{ $pct }}%"></div>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-[12px] text-ink-muted">Excel veya PDF olarak indirilebilir.</p>
    </div>
</div>
