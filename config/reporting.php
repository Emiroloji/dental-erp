<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Cache Süresi
    |--------------------------------------------------------------------------
    |
    | mimari.md Bölüm 6: dashboard özet rakamları kısa süreli (1-5 dakika)
    | cache'lenir. Varsayılan 120 saniye bu aralığın ortasında.
    |
    */

    'dashboard_cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 120),

];
