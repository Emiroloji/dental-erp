<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? 'Dental ERP — Diş kliniği stok yönetimi' }}</title>
        <meta name="description" content="Çok şubeli diş klinikleri ve hastaneler için lot/SKT takipli stok yönetimi: depolar arası transfer, satın alma, sayım, iade ve raporlar tek sistemde.">

        @include('partials.pwa-head')

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="antialiased bg-canvas text-ink">
        {{-- Tanıtım sitesi (Aşama 29.2): girişsiz, tenant/auth middleware'lerinin dışında. --}}
        <header class="border-b border-line bg-surface">
            <div class="max-w-5xl mx-auto px-6 h-16 flex items-center gap-8">
                <a href="{{ route('marketing.home') }}" class="flex items-center gap-2.5 shrink-0">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="text-brand-500 shrink-0">
                        <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                    </svg>
                    <span class="font-medium tracking-tight text-[15px]">Dental ERP</span>
                </a>

                <nav class="flex items-center gap-6 text-[14px] ml-auto">
                    <a href="{{ route('marketing.features') }}" class="hidden sm:inline text-ink-muted hover:text-ink transition-colors {{ request()->routeIs('marketing.features') ? 'text-ink' : '' }}">Neler Yapıyor</a>
                    <a href="{{ route('login') }}" class="text-ink-muted hover:text-ink transition-colors">Giriş yap</a>
                    <a href="{{ route('marketing.contact') }}" class="bg-panel-900 text-white rounded-md px-4 py-2 font-medium hover:bg-panel-800 transition-colors">Talep Gönder</a>
                </nav>
            </div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="border-t border-line mt-24">
            <div class="max-w-5xl mx-auto px-6 py-10 flex flex-col sm:flex-row sm:items-center gap-3 text-[13px] text-ink-muted">
                <p>© {{ now()->year }} Dental ERP</p>
                <div class="sm:ml-auto flex items-center gap-6">
                    <a href="{{ route('marketing.features') }}" class="hover:text-ink transition-colors">Neler Yapıyor</a>
                    <a href="{{ route('marketing.contact') }}" class="hover:text-ink transition-colors">Talep Gönder</a>
                    <a href="{{ route('login') }}" class="hover:text-ink transition-colors">Giriş yap</a>
                </div>
            </div>
        </footer>

        @livewireScripts
    </body>
</html>
