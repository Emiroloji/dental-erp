<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stok Uyarı Seviyesi Eşikleri
    |--------------------------------------------------------------------------
    |
    | proje.md Bölüm 7'deki Yeşil/Sarı/Kırmızı uyarı tablosunun "yapılandırılabilir"
    | olarak işaretlenmiş sabit değerleri burada tutulur. Aşama 29.1'den beri
    | her ürün kendi sarı/kırmızı eşiğini taşıyabilir (alert_mode + miktar ve
    | SKT eksenlerinin eşikleri); aşağıdaki değerler
    | yalnızca ürün kartında eşik girilmemişse devreye giren varsayılandır.
    | Bir ürünün kendi min_stock alanı tanımlıysa o da "düşük stok" eşiği
    | olarak kullanılmaya devam eder.
    |
    */

    'levels' => [
        // Bu adet veya altına düşen ürünler "Düşük / Sarı" seviyeye geçer.
        'low_quantity_threshold' => (float) env('STOCK_LOW_QUANTITY_THRESHOLD', 10),

        // Bu adet veya altına düşen (ya da stok tükenen) ürünler "Kritik / Kırmızı" seviyeye geçer.
        'critical_quantity_threshold' => (float) env('STOCK_CRITICAL_QUANTITY_THRESHOLD', 5),

        // Son kullanma tarihine bu kadar gün veya daha az kalan lotu olan ürünler "Düşük / Sarı" sayılır.
        'expiry_warning_days' => (int) env('STOCK_EXPIRY_WARNING_DAYS', 30),
    ],

];
