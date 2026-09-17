<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aşama 6: kritik stok / SKT taraması. Saatlik yeterlidir — uyarı sistemi
// gerçek zamanlı değil, "son 1 saat içinde fark edilir" hedefiyle tasarlandı.
Schedule::command('stock:scan-levels')->hourly();
