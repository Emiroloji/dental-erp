@php
    $tabs = [
        'reports.stock' => 'Stok Durumu',
        'reports.movements' => 'Stok Hareketleri',
        'reports.usage' => 'Kullanım ve Maliyet',
        'reports.purchasing' => 'Satın Alma ve İade',
    ];
@endphp

<nav class="mb-6 flex flex-wrap gap-1 border-b border-line">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" wire:navigate
           class="px-3 py-2 text-[14px] -mb-px border-b-2 {{ request()->routeIs($route) ? 'border-brand-500 text-ink font-medium' : 'border-transparent text-ink-muted hover:text-ink' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
