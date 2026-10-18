<?php

use App\Http\Controllers\EInvoice\EInvoicePdfController;
use App\Http\Controllers\Marketing\GuideController;
use App\Http\Controllers\Marketing\SeoController;
use App\Http\Controllers\Purchasing\PurchaseReceiptDocumentController;
use App\Http\Controllers\Reporting\ReportExportDownloadController;
use App\Http\Controllers\Stock\LabelController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Tanıtım sitesi (fazlar-adimlar.md Aşama 29.2): girişsiz, tenant/auth
 * middleware'lerinin dışında yaşayan birkaç sayfa. Oturumu açık kullanıcı
 * ana sayfadan kendi paneline yönlendirilir (bileşenlerin mount'unda).
 */
Route::livewire('/', 'pages::marketing.home')->name('marketing.home');
Route::livewire('/neler-yapiyor', 'pages::marketing.features')->name('marketing.features');
Route::livewire('/talep', 'pages::marketing.contact')->name('marketing.contact');

// Aşama 52: ÜTS ve e-fatura rehberleri, site haritası ve robots.txt (girişsiz).
Route::get('/rehber', [GuideController::class, 'index'])->name('guides.index');
Route::get('/rehber/{slug}', [GuideController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('guides.show');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');

Route::post('/logout', function () {
    Auth::logout();

    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::livewire('/login', 'pages::access.login')
    ->middleware('guest')
    ->name('login');

// Aşama 28: şifremi unuttum akışı — e-posta ile tek seferlik, süreli bağlantı.
Route::livewire('/sifremi-unuttum', 'pages::access.forgot-password')
    ->middleware('guest')
    ->name('password.request');

Route::livewire('/sifre-sifirla/{token}', 'pages::access.reset-password')
    ->middleware('guest')
    ->name('password.reset');

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
    Route::livewire('/paket-talepleri', 'pages::platform.plan-requests')->name('plan-requests.index');
    Route::livewire('/gelen-talepler', 'pages::platform.leads')->name('leads.index');
    Route::livewire('/hesaplar', 'pages::platform.accounts')->name('accounts.index');
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

    Route::livewire('/hizli-islem', 'pages::stock.quick')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.quick');

    Route::get('/etiket/urun/{product}', [LabelController::class, 'product'])
        ->middleware(['auth', 'can:product_management.viewAny'])
        ->name('labels.product');

    Route::get('/etiket/lot/{lot}', [LabelController::class, 'lot'])
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('labels.lot');

    Route::livewire('/stok-hareketleri', 'pages::stock.movements')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.movements');

    Route::livewire('/seri-takibi', 'pages::stock.serials')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('stock.serials');

    // Aşama 43: hastalar (ÜTS Kullanım bildirimi için en az veri).
    Route::livewire('/hastalar', 'pages::patients.index')
        ->middleware(['auth', 'can:patients.viewAny'])
        ->name('patients.index');

    Route::livewire('/hastalar/{patient}', 'pages::patients.show')
        ->whereNumber('patient')
        ->middleware(['auth', 'can:patients.viewAny'])
        ->name('patients.show');

    // Aşama 33: ÜTS sorgulama (salt okunur).
    Route::livewire('/uts/kabul-bekleyenler', 'pages::uts.pending')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('uts.pending');

    Route::livewire('/uts/dogrula', 'pages::uts.verify')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('uts.verify');

    // Aşama 42: ÜTS'ye gönderilen bildirimlerin (Alma) kaydı.
    Route::livewire('/uts/gecmis', 'pages::uts.history')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('uts.history');

    // Faz 5: Finans (ön muhasebe) — organizasyon geneli, şube kapsamı yok (karar 2.9).
    Route::livewire('/finans', 'pages::finance.overview')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.overview');

    Route::livewire('/finans/hareketler', 'pages::finance.entries')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.entries');

    Route::livewire('/finans/odemeler', 'pages::finance.obligations')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.obligations');

    Route::livewire('/finans/cariler', 'pages::finance.parties')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.parties');

    Route::livewire('/finans/cariler/{party}', 'pages::finance.party')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.parties.show');

    Route::livewire('/finans/tekrarlayanlar', 'pages::finance.recurrings')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.recurrings');

    Route::livewire('/finans/hesaplar', 'pages::finance.accounts')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.accounts');

    Route::livewire('/finans/hesaplar/{account}', 'pages::finance.account')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.accounts.show');

    Route::livewire('/finans/raporlar', 'pages::finance.reports')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.reports');

    Route::livewire('/finans/kategoriler', 'pages::finance.categories')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.categories');

    Route::livewire('/stok-sayimi', 'pages::inventory.index')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('inventory.index');

    Route::livewire('/stok-sayimi/{count}', 'pages::inventory.show')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('inventory.show');

    Route::livewire('/transferler', 'pages::transfer.index')
        ->middleware(['auth', 'can:transfer.viewAny'])
        ->name('transfers.index');

    Route::livewire('/transferler/{transfer}', 'pages::transfer.show')
        ->middleware(['auth', 'can:transfer.viewAny'])
        ->name('transfers.show');

    Route::livewire('/satin-alma', 'pages::purchasing.index')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('purchasing.index');

    Route::livewire('/satin-alma/{order}', 'pages::purchasing.show')
        ->whereNumber('order')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('purchasing.show');

    // Faz 6: gelen e-faturalar (görmek satın alma okuma yetkisiyle).
    Route::livewire('/e-fatura', 'pages::einvoice.index')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('einvoice.index');

    Route::livewire('/e-fatura/{invoice}', 'pages::einvoice.show')
        ->whereNumber('invoice')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('einvoice.show');

    Route::get('/e-fatura/{invoice}/pdf', EInvoicePdfController::class)
        ->whereNumber('invoice')
        ->middleware(['auth', 'can:purchasing.viewAny'])
        ->name('einvoice.pdf');

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

    Route::livewire('/raporlar/tahmin', 'pages::reports.forecast')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.forecast');

    Route::livewire('/raporlar/kontrollu', 'pages::reports.controlled')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.controlled');

    Route::livewire('/raporlar/asistan', 'pages::reports.assistant')
        ->middleware(['auth', 'can:reports.viewAny'])
        ->name('reports.assistant');

    Route::get('/raporlar/indir/{export}', ReportExportDownloadController::class)
        ->whereNumber('export')
        ->middleware(['auth', 'can:report_exports.download'])
        ->name('reports.exports.download');

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

    Route::livewire('/veri-aktarimi', 'pages::import.index')
        ->middleware(['auth', 'can:data_import.create'])
        ->name('import.index');

    Route::livewire('/ayarlar', 'pages::settings.index')
        ->middleware(['auth', 'can:system_settings.viewAny'])
        ->name('settings.index');

    Route::livewire('/denetim-kayitlari', 'pages::audit.index')
        ->middleware(['auth', 'can:audit_logs.viewAny'])
        ->name('audit.index');
});
