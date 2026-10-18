{{-- Hasta kartı: hastaya kullanılan implantlar ve ÜTS durumları. --}}
<div class="p-4 sm:p-5">
    <p class="text-[15px] font-medium text-ink">Ayşe Yılmaz</p>
    <p class="text-[12px] text-ink-muted">T.C. kimlik no 100******46, doğum yılı 1985</p>

    <p class="mt-4 text-[12px] text-ink-muted">Kullanılan ürünler</p>
    <ul class="mt-1.5 divide-y divide-line border-y border-line text-[13px]">
        @foreach ([
            ['12.09.2026', 'İmplant 4.3x10', 'Seri NA-1002', 'Kadıköy'],
            ['12.09.2026', 'Kemik grefti 0,5 cc', 'Lot BG-21', 'Kadıköy'],
            ['03.10.2026', 'İyileşme başlığı 4.5', 'Seri HB-3310', 'Beşiktaş'],
        ] as [$date, $product, $tracking, $branch])
            <li class="py-2.5 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="text-ink">{{ $product }}</span>
                    <span class="block text-ink-muted font-mono text-[12px]">{{ $tracking }}, {{ $branch }}</span>
                </div>
                <div class="text-right shrink-0">
                    <span class="block text-ink-muted tabular-nums">{{ $date }}</span>
                    <span class="text-[12px] text-status-good">ÜTS'ye bildirildi</span>
                </div>
            </li>
        @endforeach
    </ul>

    <p class="mt-4 text-[12px] text-ink-muted leading-relaxed">
        Kimlik numarası şifreli saklanır, ekranlarda maskeli görünür.
    </p>
</div>
