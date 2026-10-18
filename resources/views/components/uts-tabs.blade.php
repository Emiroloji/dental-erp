@props(['active'])

{{-- ÜTS ekranlarının sekmeleri. Etkin sekme açıkça verilir; Livewire güncellemesinde istek adresi sayfanınki değildir. --}}
<nav class="flex flex-wrap gap-x-4 gap-y-2 mt-4 text-[14px]">
    @foreach ([
        'uts.pending' => 'Kabul Bekleyenler',
        'uts.declined' => 'Almak İstemediklerim',
        'uts.verify' => 'Lot/Seri Doğrula',
        'uts.history' => 'Gönderim Geçmişi',
    ] as $route => $label)
        <a href="{{ route($route) }}" @class(['pb-1', 'font-medium text-ink border-b-2 border-brand-500' => $active === $route, 'text-ink-muted hover:text-ink' => $active !== $route])>{{ $label }}</a>
    @endforeach
</nav>
