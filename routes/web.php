<?php

use App\Http\Controllers\Purchasing\PurchaseReceiptDocumentController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(auth()->check() ? '/dashboard' : '/login');
});

Route::post('/logout', function () {
    Auth::logout();

    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::livewire('/login', 'pages::access.login')
    ->middleware('guest')
    ->name('login');

// Geçici şifreyle açılan hesaplar ilk girişte şifresini değiştirir (proje.md Bölüm 2).
Route::livewire('/sifre-degistir', 'pages::access.change-password')
    ->middleware('auth')
    ->name('password.change');

/*
 * Platform Yönetici Paneli (mimari.md Bölüm 9): yalnızca Platform Sahibi.
 * Organizasyon, paket ve platform istatistikleri — hiçbir klinik/stok verisi yok.
 */
Route::middleware(['auth', 'platform'])->prefix('platform')->name('platform.')->group(function () {
    Route::livewire('/', 'pages::platform.dashboard')->name('dashboard');
    Route::livewire('/organizasyonlar', 'pages::platform.organizations')->name('organizations.index');
    Route::livewire('/organizasyonlar/{organization}', 'pages::platform.organization')->name('organizations.show');
});

/*
 * Klinik (tenant) ekranları: Platform Sahibi giremez; pasif organizasyonun
 * oturumu kapatılır; geçici şifre önce değiştirilir (EnsureTenantAccess).
 */

Route::middleware('tenant')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')
        ->middleware('auth')
        ->name('dashboard');

    Route::livewire('/personel', 'pages::access.staff')
        ->middleware(['auth', 'can:staff_management.viewAny'])
        ->name('staff.index');

    Route::livewire('/kategoriler', 'pages::catalog.categories')
        ->middleware(['auth', 'can:category_management.viewAny'])
        ->name('categories.index');

    Route::livewire('/tedarikciler', 'pages::catalog.suppliers')
        ->middleware(['auth', 'can:supplier_management.viewAny'])
        ->name('suppliers.index');

    Route::livewire('/urunler', 'pages::catalog.products')
        ->middleware(['auth', 'can:product_management.viewAny'])
        ->name('products.index');

    Route::livewire('/stok-durumu', 'pages::stock.status')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.status');

    Route::livewire('/stok-girisleri', 'pages::stock.in')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.in');

    Route::livewire('/stok-cikislari', 'pages::stock.out')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.out');

    Route::livewire('/stok-hareketleri', 'pages::stock.movements')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.movements');

    Route::livewire('/stok-sayimi', 'pages::inventory.index')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('inventory.index');

    Route::livewire('/stok-sayimi/{count}', 'pages::inventory.show')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('inventory.show');

    Route::livewire('/transferler', 'pages::transfer.index')
        ->middleware(['auth', 'can:transfer.viewAny'])
        ->name('transfers.index');

    Route::livewire('/satin-alma', 'pages::purchasing.index')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('purchasing.index');

    Route::get('/satin-alma/teslim/{receipt}/belge', PurchaseReceiptDocumentController::class)
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('purchasing.receipts.document');

    Route::livewire('/iadeler', 'pages::returns.index')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('returns.index');

    Route::livewire('/iadeler/{return}', 'pages::returns.show')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('returns.show');

    Route::livewire('/bildirimler', 'pages::notifications.index')
        ->middleware('auth')
        ->name('notifications.index');

    Route::livewire('/raporlar', 'pages::reports.stock')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.stock');

    Route::livewire('/raporlar/hareketler', 'pages::reports.movements')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.movements');

    Route::livewire('/raporlar/kullanim', 'pages::reports.usage')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.usage');

    Route::livewire('/raporlar/satin-alma', 'pages::reports.purchasing')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.purchasing');

    Route::livewire('/depo-stoklari', 'pages::reports.warehouse-stock')
        ->middleware(['auth', 'can:warehouse_stock.viewAny'])
        ->name('reports.warehouse-stock');

    Route::livewire('/subeler', 'pages::organization.branches')
        ->middleware(['auth', 'can:branches.manage'])
        ->name('branches.index');

    Route::livewire('/depolar', 'pages::organization.warehouses')
        ->middleware(['auth', 'can:warehouses.manage'])
        ->name('warehouses.index');

    Route::livewire('/paket', 'pages::organization.subscription')
        ->middleware(['auth', 'can:subscription.view'])
        ->name('subscription.show');

    Route::livewire('/denetim-kayitlari', 'pages::audit.index')
        ->middleware(['auth', 'can:audit_logs.viewAny'])
        ->name('audit.index');
});
