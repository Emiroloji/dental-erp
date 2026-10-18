<?php

return [

    /*
    | ÜTS (Ürün Takip Sistemi) web servisleri — "Takip ve İzleme Web Servis
    | Tanımları Dokümanı" rev. 1.99. Token organizasyon başına veritabanında
    | (şifreli) tutulur; burada yalnızca adresler ve yazma kilidi vardır.
    */
    'urls' => [
        'test' => env('UTS_TEST_URL', 'https://utstest.saglik.gov.tr'),
        'production' => env('UTS_PRODUCTION_URL', 'https://utsuygulama.saglik.gov.tr'),
    ],

    'timeout' => (int) env('UTS_TIMEOUT', 15),

    /*
    | Gerçek ortama bildirim (Alma...) göndermek. Alma bildiriminin ÜTS'de
    | iptali yoktur; yazma bir klinikle test ortamında doğrulanana kadar
    | kapalıdır (Aşama 42). Okuma ekranları bu ayardan etkilenmez.
    */
    'production_writes' => (bool) env('UTS_PRODUCTION_WRITES', false),

];
