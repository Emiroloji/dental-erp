<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? 'Dental ERP — Diş kliniği stok yönetimi' }}</title>
        <meta name="description" content="Çok şubeli diş klinikleri ve hastaneler için lot ve son kullanma tarihi takipli stok yönetimi: depolar arası transfer, satın alma, sayım, iade, uyarılar ve raporlar tek sistemde.">

        @include('partials.pwa-head')

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="antialiased bg-surface text-ink">
        {{-- Tanıtım sitesi (Aşama 29.2): girişsiz, tenant/auth middleware'lerinin dışında. --}}
        <header class="sticky top-0 z-30 bg-surface/95 backdrop-blur border-b border-line">
            <div class="mx-auto max-w-[1120px] px-4 sm:px-6 h-[60px] flex items-center gap-3">
                <a href="{{ route('marketing.home') }}" class="flex items-center gap-2.5 shrink-0" aria-label="Dental ERP ana sayfa">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" class="text-brand-600 shrink-0" aria-hidden="true">
                        <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                    </svg>
                    <span class="font-semibold tracking-[-0.02em] text-[15px]">Dental ERP</span>
                </a>

                <nav class="ml-auto flex items-center gap-1 sm:gap-2 text-[14px] whitespace-nowrap">
                    <a href="{{ route('marketing.features') }}"
                        class="hidden sm:inline-block px-3 py-2 rounded-md transition-colors {{ request()->routeIs('marketing.features') ? 'text-ink' : 'text-ink-muted hover:text-ink' }}">
                        Neler yapıyor
                    </a>
                    <a href="{{ route('login') }}" class="px-2 sm:px-3 py-2 rounded-md text-ink-muted hover:text-ink transition-colors">Giriş yap</a>
                    <a href="{{ route('marketing.contact') }}" class="sm:ml-1 bg-panel-900 text-white rounded-md px-3 sm:px-4 py-2 font-medium hover:bg-panel-800 transition-colors">Talep gönder</a>
                </nav>
            </div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="bg-panel-900 text-white/60">
            <div class="mx-auto max-w-[1120px] px-4 sm:px-6 py-14">
                <div class="sm:flex sm:items-start sm:justify-between gap-10">
                    <div class="max-w-[38ch]">
                        <div class="flex items-center gap-2.5 text-white">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" class="text-brand-400 shrink-0" aria-hidden="true">
                                <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                            </svg>
                            <span class="font-semibold tracking-[-0.02em] text-[15px]">Dental ERP</span>
                        </div>
                        <p class="mt-3 text-[13px] leading-relaxed">
                            Diş klinikleri ve hastaneleri için lot ve son kullanma tarihi takipli stok yönetim sistemi.
                        </p>
                    </div>

                    <nav class="mt-8 sm:mt-0 flex flex-col gap-2.5 text-[14px]">
                        <a href="{{ route('marketing.home') }}" class="hover:text-white transition-colors">Ana sayfa</a>
                        <a href="{{ route('marketing.features') }}" class="hover:text-white transition-colors">Neler yapıyor</a>
                        <a href="{{ route('marketing.contact') }}" class="hover:text-white transition-colors">Talep gönder</a>
                        <a href="{{ route('login') }}" class="hover:text-white transition-colors">Giriş yap</a>
                    </nav>
                </div>

                <p class="mt-12 pt-6 border-t border-panel-line text-[13px]">© {{ now()->year }} Dental ERP</p>
            </div>
        </footer>

        @livewireScripts
    </body>
</html>
