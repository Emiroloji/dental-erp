<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stok Uyarı Seviyesi Eşikleri
    |--------------------------------------------------------------------------
    |
    | proje.md Bölüm 7'deki Yeşil/Sarı/Kırmızı uyarı tablosunun "yapılandırılabilir"
    | olarak işaretlenmiş sabit değerleri burada tutulur. Bir ürünün kendi
    | min_stock alanı tanımlıysa (ürün kartında girildiyse) o öncelikli "düşük
    | stok" eşiği olarak kullanılır; aşağıdaki sabitler min_stock tanımlı
    | olmayan/geniş olan ürünler için de bir taban güvenlik ağı sağlar.
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
