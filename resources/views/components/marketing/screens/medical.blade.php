{{-- ÜTS: Gönderim Geçmişi; aynı implant partisinin kabulden imhaya ÜTS bildirimleri. --}}
<div class="p-4 sm:p-5">
    <div class="flex items-baseline justify-between gap-3">
        <p class="text-[15px] font-medium text-ink">ÜTS, Gönderim Geçmişi</p>
        <p class="text-[12px] text-ink-muted">Bu hafta</p>
    </div>

    <ul class="mt-4 divide-y divide-line border-y border-line text-[13px]">
        @foreach ([
            ['Alma', 'İmplant 4.3x10, 10 adet', 'Kabul edildi', true],
            ['Kullanım', 'NA-1002, A*** Y***', 'Bildirildi', true],
            ['Verme', 'NA-1007, tedarikçiye iade', 'Bildirildi', true],
            ['İmha', 'Membran BG-55, SKT geçmiş', 'Gönderilemedi', false],
        ] as [$type, $what, $result, $ok])
            <li class="py-2.5 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="text-ink font-medium">{{ $type }}</span>
                    <span class="block text-ink-muted truncate">{{ $what }}</span>
                </div>
                <span @class(['shrink-0 text-[12px] px-2 py-0.5 rounded', 'bg-status-good-bg text-status-good' => $ok, 'bg-status-warn-bg text-status-warn' => ! $ok])>{{ $result }}</span>
            </li>
        @endforeach
    </ul>

    <p class="mt-4 text-[12px] text-ink-muted leading-relaxed">
        Kabul, kullanım, iade ve imha programdan ÜTS'ye gider; her birinin cevabı burada kalır.
    </p>
</div>
