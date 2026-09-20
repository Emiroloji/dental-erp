<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hata İzleme Sürücüsü
    |--------------------------------------------------------------------------
    |
    | Aşama 28: 500 hataları bir arayüz (ErrorReporter) arkasından raporlanır.
    | SENTRY_DSN tanımlıysa "sentry" sürücüsü olaylar Sentry'ye gönderir;
    | tanımlı değilse "log" sürücüsü çalışır ve hatayı istek/kullanıcı
    | bağlamıyla birlikte log'a yazar. Testlerde "none" kullanılır; hiçbir
    | dış servise bağlanılmaz.
    |
    | Desteklenen: "sentry", "log", "none"
    |
    */

    'driver' => env('ERROR_REPORTER_DRIVER', filled(env('SENTRY_DSN')) ? 'sentry' : 'log'),

    'log_channel' => env('ERROR_REPORTER_LOG_CHANNEL'),

    'sentry' => [
        'dsn' => env('SENTRY_DSN'),
        'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
        'release' => env('SENTRY_RELEASE'),
        'timeout' => (int) env('SENTRY_TIMEOUT', 5),
    ],

];
