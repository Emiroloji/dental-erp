@props(['title', 'path' => null, 'dark' => false])

{{--
    Tanıtım sitesindeki örnek ekranların çerçevesi: programın kendi düzeninin
    küçültülmüş hâli (koyu kenar menüsü + açık çalışma alanı). İçindeki veriler
    örnektir; ekran görüntüsü değil, sitenin kendi token'larıyla çizilmiş HTML'dir.
--}}
<figure {{ $attributes->class(['rounded-xl overflow-hidden border text-left', $dark ? 'border-panel-line bg-panel-800 shadow-[0_24px_60px_-20px_rgb(0_0_0/0.6)]' : 'border-line bg-surface shadow-[0_18px_40px_-24px_rgb(14_26_28/0.35)]']) }}>
    <div @class(['flex items-center gap-3 px-4 h-10 border-b text-[12px]', $dark ? 'border-panel-line text-white/45' : 'border-line text-ink-muted bg-canvas'])>
        <span class="flex gap-1.5" aria-hidden="true">
            <span @class(['w-2.5 h-2.5 rounded-full', $dark ? 'bg-white/15' : 'bg-line'])></span>
            <span @class(['w-2.5 h-2.5 rounded-full', $dark ? 'bg-white/15' : 'bg-line'])></span>
            <span @class(['w-2.5 h-2.5 rounded-full', $dark ? 'bg-white/15' : 'bg-line'])></span>
        </span>
        <span @class(['font-medium', $dark ? 'text-white/80' : 'text-ink'])>{{ $title }}</span>
        @if ($path)
            <span class="ml-auto truncate">{{ $path }}</span>
        @endif
    </div>
    <div class="flex">
        <div class="hidden sm:flex w-11 shrink-0 flex-col items-center gap-3 py-4 bg-panel-900" aria-hidden="true">
            @foreach ([1, 0, 0, 0, 0] as $active)
                <span @class(['w-4 h-1.5 rounded-full', $active ? 'bg-brand-400' : 'bg-white/15'])></span>
            @endforeach
        </div>
        <div class="min-w-0 flex-1">
            {{ $slot }}
        </div>
    </div>
</figure>
