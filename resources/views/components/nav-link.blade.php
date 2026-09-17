@props(['href', 'active' => false])

<a
    href="{{ $href }}"
    @class([
        'flex items-center gap-3 px-3 py-2.5 rounded-md text-[14px] transition-colors',
        'bg-panel-700 text-white' => $active,
        'text-white/60 hover:text-white/95 hover:bg-panel-800' => ! $active,
    ])
>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="shrink-0 {{ $active ? 'text-brand-400' : 'text-white/40' }}">
        {{ $icon }}
    </svg>
    <span>{{ $slot }}</span>
</a>
