@php
    $tabs = [
        'reports.stock' => 'Stok Durumu',
        'reports.movements' => 'Stok Hareketleri',
        'reports.usage' => 'Kullanım ve Maliyet',
        'reports.purchasing' => 'Satın Alma ve İade',
        'reports.forecast' => 'Tüketim Tahmini',
        'reports.controlled' => 'Kontrollü Ürün Defteri',
        'reports.assistant' => 'Rapor Asistanı',
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

@if (session('export-status'))
    <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('export-status') }}</div>
@endif

<livewire:report-exports />
