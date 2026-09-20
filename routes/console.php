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

// Aşama 28: kuyruk worker'ı düşerse işler sessizce birikir; saatlik kontrol
// bekleyen ve başarısız işleri log'a uyarı olarak yazar.
Schedule::command('queue:health-check')->hourly();
