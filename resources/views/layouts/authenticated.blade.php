<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

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
                </div>

                <nav class="flex-1 px-3 py-2 space-y-0.5">
                    <x-nav-link :href="route('notifications.index')" :active="request()->routeIs('notifications.index')">
                        <x-slot:icon><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.73 21a2 2 0 0 1-3.46 0" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                        Bildirimler
                    </x-nav-link>
                    @can('product_management.viewAny')
                        <x-nav-link :href="route('products.index')" :active="request()->routeIs('products.index')">
                            <x-slot:icon><path d="M4 7l8-4 8 4-8 4-8-4Zm0 0v10l8 4m0-14v14m8-14v10l-8 4" stroke-linejoin="round"/></x-slot:icon>
                            Ürünler
                        </x-nav-link>
                    @endcan
                    @can('category_management.viewAny')
                        <x-nav-link :href="route('categories.index')" :active="request()->routeIs('categories.index')">
                            <x-slot:icon><path d="M4 5h7v7H4V5Zm9 0h7v4h-7V5ZM4 15h7v4H4v-4Zm9-3h7v7h-7v-7Z" stroke-linejoin="round"/></x-slot:icon>
                            Kategoriler
                        </x-nav-link>
                    @endcan
                    @can('supplier_management.viewAny')
                        <x-nav-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.index')">
                            <x-slot:icon><path d="M3 10.5 12 4l9 6.5M5 9.5V20h5v-6h4v6h5V9.5" stroke-linejoin="round"/></x-slot:icon>
                            Tedarikçiler
                        </x-nav-link>
                    @endcan
                    @can('staff_management.viewAny')
                        <x-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.index')">
                            <x-slot:icon><path d="M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-6 9c0-3.3 2.7-6 6-6s6 2.7 6 6M17 11a3 3 0 1 0 0-6M23 20c0-2.8-2-5-4.5-5.8" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Personel
                        </x-nav-link>
                    @endcan
                    @can('stock_movement.viewAny')
                        <div class="px-3 pt-4 pb-1 text-[11px] font-medium uppercase tracking-wide text-white/40">Stok</div>
                        <x-nav-link :href="route('stock.status')" :active="request()->routeIs('stock.status')">
                            <x-slot:icon><path d="M3 7l9-4 9 4-9 4-9-4Zm0 0v10l9 4m0-14v14m9-14v10l-9 4" stroke-linejoin="round"/></x-slot:icon>
                            Stok Durumu
                        </x-nav-link>
                        <x-nav-link :href="route('stock.in')" :active="request()->routeIs('stock.in')">
                            <x-slot:icon><path d="M12 5v14M5 12h14" stroke-linecap="round"/></x-slot:icon>
                            Stok Girişi
                        </x-nav-link>
                        <x-nav-link :href="route('stock.out')" :active="request()->routeIs('stock.out')">
                            <x-slot:icon><path d="M12 19V5M5 12h14" stroke-linecap="round"/></x-slot:icon>
                            Stok Çıkışı
                        </x-nav-link>
                        <x-nav-link :href="route('stock.movements')" :active="request()->routeIs('stock.movements')">
                            <x-slot:icon><path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/></x-slot:icon>
                            Stok Hareketleri
                        </x-nav-link>
                        <x-nav-link :href="route('inventory.index')" :active="request()->routeIs('inventory.*')">
                            <x-slot:icon><path d="M9 4h6M9 4a1 1 0 0 0-1 1v1h8V5a1 1 0 0 0-1-1M8 6H6a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1h-2M9 13l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Stok Sayımı
                        </x-nav-link>
                    @endcan
                    @can('transfer.viewAny')
                        <x-nav-link :href="route('transfers.index')" :active="request()->routeIs('transfers.index')">
                            <x-slot:icon><path d="M4 8h13l-3-3M20 16H7l3 3" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Transferler
                        </x-nav-link>
                    @endcan
                    @can('purchasing.viewAny')
                        <x-nav-link :href="route('purchasing.index')" :active="request()->routeIs('purchasing.index')">
                            <x-slot:icon><path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L20 8H6M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm9 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Satın Alma
                        </x-nav-link>
                    @endcan
                    @can('reports.viewAny')
                        <x-nav-link :href="route('reports.stock')" :active="request()->routeIs('reports.stock')">
                            <x-slot:icon><path d="M4 19V9m6 10V5m6 14v-7" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Raporlar
                        </x-nav-link>
                    @endcan
                    @can('warehouse_stock.viewAny')
                        <x-nav-link :href="route('reports.warehouse-stock')" :active="request()->routeIs('reports.warehouse-stock')">
                            <x-slot:icon><path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 3h7M16.5 13v7" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Depo Stokları
                        </x-nav-link>
                    @endcan
                    @can('branches.manage')
                        <div class="px-3 pt-4 pb-1 text-[11px] font-medium uppercase tracking-wide text-white/40">Kuruluş</div>
                        <x-nav-link :href="route('branches.index')" :active="request()->routeIs('branches.index')">
                            <x-slot:icon><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Şubeler
                        </x-nav-link>
                    @endcan
                    @can('warehouses.manage')
                        <x-nav-link :href="route('warehouses.index')" :active="request()->routeIs('warehouses.index')">
                            <x-slot:icon><path d="M3 21V9l9-6 9 6v12M7 21v-8h10v8M7 17h10" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Depolar
                        </x-nav-link>
                    @endcan
                    @can('audit_logs.viewAny')
                        <x-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.index')">
                            <x-slot:icon><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3Z" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Denetim Kayıtları
                        </x-nav-link>
                    @endcan
                </nav>

                <div class="px-3 pb-4 pt-2 border-t border-panel-line/60 mx-3">
                    <div class="flex items-center justify-between px-3 py-3">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium truncate">{{ auth()->user()->name }}</p>
                            <p class="text-[12px] text-white/50">{{ match(auth()->user()->role) {
                                'admin' => 'Yönetici',
                                'platform_owner' => 'Platform Sahibi',
                                default => 'Personel',
                            } }}@if (auth()->user()->branch) · {{ auth()->user()->branch->name }}@endif</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <livewire:notification-bell />
                            <button
                                onclick="event.preventDefault(); document.getElementById('sidebar-logout-form').requestSubmit()"
                                class="text-white/40 hover:text-white/90 transition-colors shrink-0"
                                title="Çıkış Yap"
                            >
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5M20 12H9M12 19H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
                            </button>
                        </div>
                    </div>
                    <form id="sidebar-logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
                        @csrf
                    </form>
                </div>
            </aside>

            <div class="flex-1 min-w-0">
                <header class="lg:hidden flex items-center justify-between px-5 py-4 bg-panel-900 text-white">
                    <span class="font-medium tracking-tight">Dental ERP</span>
                    <livewire:notification-bell />
                </header>

                <main class="px-5 py-8 lg:px-10 lg:py-10 max-w-5xl">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
