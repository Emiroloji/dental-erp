<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stok Tüketim Tahmini (Faz 4 — Aşama 24)
    |--------------------------------------------------------------------------
    |
    | İstatistiksel yöntem: son "history_weeks" haftanın kullanım çıkışları
    | (klinik içi kullanım + sarf, iptaller hariç) haftalık toplanır ve Holt
    | doğrusal üstel düzeltme (seviye + trend) ile gelecek haftanın tüketimi
    | tahmin edilir. Dış servis yok; aynı veride hep aynı sonuç.
    |
    */

    // Geriye bakılan hafta sayısı.
    'history_weeks' => (int) env('FORECAST_HISTORY_WEEKS', 12),

    // İlk kullanımdan bu yana bu kadar haftadan az geçmiş varsa tahmin "düşük güven" sayılır.
    'min_history_weeks' => (int) env('FORECAST_MIN_HISTORY_WEEKS', 3),

    // Holt parametreleri: alpha seviyenin, beta trendin son veriye duyarlılığı (0-1).
    'alpha' => (float) env('FORECAST_ALPHA', 0.5),
    'beta' => (float) env('FORECAST_BETA', 0.3),

    // Stoğun yetme süresi bu gün sayısına eşit/altındaysa "Kritik", "warning_days" ve altındaysa "Yakında".
    'critical_days' => (int) env('FORECAST_CRITICAL_DAYS', 7),
    'warning_days' => (int) env('FORECAST_WARNING_DAYS', 30),

    // "Beklenen kullanım" ufku (gün).
    'horizon_days' => (int) env('FORECAST_HORIZON_DAYS', 30),

    // Trend/seviye oranı bu eşiği aşarsa "artıyor"/"azalıyor", aksi halde "sabit".
    'trend_threshold' => (float) env('FORECAST_TREND_THRESHOLD', 0.10),

];
