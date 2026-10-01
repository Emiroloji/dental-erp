<?php

return [

    /*
    | ÜTS (Ürün Takip Sistemi) web servisleri — "Takip ve İzleme Web Servis
    | Tanımları Dokümanı" rev. 1.47. Token organizasyon başına veritabanında
    | (şifreli) tutulur; burada yalnızca adresler vardır.
    */
    'urls' => [
        'test' => env('UTS_TEST_URL', 'https://utstest.saglik.gov.tr'),
        'production' => env('UTS_PRODUCTION_URL', 'https://utsuygulama.saglik.gov.tr'),
    ],

    'timeout' => (int) env('UTS_TIMEOUT', 15),

];
