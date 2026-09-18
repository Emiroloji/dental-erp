<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? 'Platform — '.config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="antialiased bg-canvas text-ink">
        <div class="min-h-screen lg:flex">
            <aside class="bg-panel-900 text-white lg:w-64 lg:shrink-0 lg:flex lg:flex-col">
                <div class="flex items-center gap-2.5 px-6 py-6">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="text-brand-400 shrink-0">
                        <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                    </svg>
                    <span class="font-medium tracking-tight text-[15px]">Dental ERP</span>
                    <span class="ml-auto text-[10px] uppercase tracking-wide text-brand-400 border border-brand-400/40 rounded px-1.5 py-0.5">Platform</span>
                </div>

                <nav class="flex-1 px-3 py-2 space-y-0.5">
                    <x-nav-link :href="route('platform.dashboard')" :active="request()->routeIs('platform.dashboard')">
                        <x-slot:icon><path d="M4 19V9m6 10V5m6 14v-7" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                        Genel Bakış
                    </x-nav-link>
                    <x-nav-link :href="route('platform.organizations.index')" :active="request()->routeIs('platform.organizations.*')">
                        <x-slot:icon><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                        Organizasyonlar
                    </x-nav-link>
                </nav>

                <div class="px-3 pb-4 pt-2 border-t border-panel-line/60 mx-3">
                    <div class="flex items-center justify-between px-3 py-3">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium truncate">{{ auth()->user()->name }}</p>
                            <p class="text-[12px] text-white/50">Platform Sahibi</p>
                        </div>
                        <button onclick="event.preventDefault(); document.getElementById('platform-logout-form').requestSubmit()" class="text-white/40 hover:text-white/90 transition-colors shrink-0" title="Çıkış Yap">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5M20 12H9M12 19H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
                        </button>
                    </div>
                    <form id="platform-logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
                </div>
            </aside>

            <div class="flex-1 min-w-0">
                <header class="lg:hidden flex items-center justify-between px-5 py-4 bg-panel-900 text-white">
                    <span class="font-medium tracking-tight">Dental ERP · Platform</span>
                </header>

                <main class="px-5 py-8 lg:px-10 lg:py-10 max-w-5xl">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
