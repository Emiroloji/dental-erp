<?php

return [

    /*
    | Gelen e-fatura (Faz 6). API anahtarı organizasyon başına veritabanında
    | (şifreli) tutulur; burada yalnızca entegratör adresleri vardır.
    */
    'urls' => [
        'nilvera' => [
            'test' => env('EINVOICE_NILVERA_TEST_URL', 'https://apitest.nilvera.com'),
            'production' => env('EINVOICE_NILVERA_PRODUCTION_URL', 'https://api.nilvera.com'),
        ],
    ],

    'timeout' => (int) env('EINVOICE_TIMEOUT', 20),

    /* İlk eşitlemede geriye bakılan gün sayısı. */
    'initial_days' => 30,

];
