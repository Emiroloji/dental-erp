{{-- Transfer: şubeler arası talebin adımları; stok "Gönderildi" ve "Teslim alındı"da değişir. --}}
<div class="p-4 sm:p-5">
    <p class="text-[15px] font-medium text-ink">Transfer #27</p>
    <p class="text-[12px] text-ink-muted">Merkez Şube → Kadıköy Şubesi, 50 adet muayene eldiveni</p>

    <ol class="mt-5 space-y-0 text-[13px]">
        @foreach ([
            ['Bekliyor', 'Kadıköy talep açtı', 'done'],
            ['Onaylandı', 'Merkez depo sorumlusu', 'done'],
            ['Hazırlanıyor', 'Koliler hazırlandı', 'done'],
            ['Gönderildi', 'Merkez stoğundan 50 adet düştü', 'current'],
            ['Teslim alındı', 'Kadıköy stoğuna eklenecek', 'next'],
        ] as $i => [$state, $note, $phase])
            <li class="flex gap-3">
                <span class="flex flex-col items-center" aria-hidden="true">
                    <span @class([
                        'w-3 h-3 rounded-full border-2 mt-1',
                        'bg-brand-500 border-brand-500' => $phase === 'done',
                        'bg-surface border-brand-500 ring-4 ring-brand-100' => $phase === 'current',
                        'bg-surface border-line' => $phase === 'next',
                    ])></span>
                    @unless ($loop->last)
                        <span @class(['w-px flex-1 my-1', $phase === 'done' ? 'bg-brand-500' : 'bg-line'])></span>
                    @endunless
                </span>
                <span class="pb-3">
                    <span @class(['block', $phase === 'next' ? 'text-ink-muted' : 'text-ink font-medium'])>{{ $state }}</span>
                    <span class="block text-[12px] text-ink-muted">{{ $note }}</span>
                </span>
            </li>
        @endforeach
    </ol>
</div>
