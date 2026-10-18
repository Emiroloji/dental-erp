<?php

use App\Domain\Reporting\Models\ReportExport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aşama 6: kritik stok / SKT taraması. Saatlik yeterlidir — uyarı sistemi
// gerçek zamanlı değil, "son 1 saat içinde fark edilir" hedefiyle tasarlandı.
Schedule::command('stock:scan-levels')->hourly();

// Aşama 23: kuyrukta üretilen rapor dosyaları 7 gün saklanır (ReportExport::RETENTION_DAYS).
Schedule::command('model:prune', ['--model' => [ReportExport::class]])->daily();

// Aşama 28: günlük veritabanı yedeği. Gece trafiğin en düşük olduğu saatte
// alınır; komut ayrıca saklama süresi dolmuş yedekleri siler.
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping();

// Aşama 30: yedeğin sunucu dışına gerçekten kopyalandığını doğrular. Yedekten
// birkaç saat sonra çalışır ki gece alınan yedek hedefte görünsün.
Schedule::command('backup:check-offsite')->dailyAt('06:00');

// Aşama 28: kuyruk worker'ı düşerse işler sessizce birikir; saatlik kontrol
// bekleyen ve başarısız işleri log'a uyarı olarak yazar.
Schedule::command('queue:health-check')->hourly();

// Faz 5 (Aşama 35): tekrarlayan finans kalemlerinden 35 gün ilerisinin
// yükümlülükleri. Tekil (recurring_id, due_on) sayesinde tekrar çalışması zararsız.
Schedule::command('finance:generate-recurring')->dailyAt('03:15');

// Faz 5 (Aşama 35): vadesi yaklaşan/geçmiş ödeme ve tahsilat bildirimi. Tekrarlayan
// kalem üretiminden sonra çalışır ki yeni üretilen yükümlülükler de görülsün.
Schedule::command('finance:scan-due')->dailyAt('05:00');

// Günlük özet e-postası (Aşama 39): stok taraması saatlik, finans vade taraması
// 05:00'te çalıştığı için özet o sabahın uyarılarını da içerir.
// Faz 7 (Aşama 47): ÜTS'de kabul bekleyen yeni Verme bildirimleri için saatlik
// tarama ve bildirim (bağlantısı olan aktif organizasyonlar).
Schedule::command('uts:scan-pending')->hourly()->withoutOverlapping();

// Faz 6: gelen e-faturalar her 30 dakikada çekilir (bağlantısı olan aktif
// organizasyonlar; kuyrukta, aynı bağlantı için tek iş).
Schedule::command('einvoice:sync')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('notifications:send-digest')->dailyAt('07:30')->timezone('Europe/Istanbul')->withoutOverlapping();
