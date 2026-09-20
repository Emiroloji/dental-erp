<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kuyruk Sağlık Eşikleri
    |--------------------------------------------------------------------------
    |
    | Aşama 28: kuyruk worker'ı Supervisor ile sürekli ayakta tutulur. Worker
    | düşerse işler birikir; bekleyen iş sayısı bu eşiği aşarsa uyarı verilir.
    | "failed_jobs" tablosuna son 24 saatte düşen kayıt sayısı da izlenir —
    | rapor dışa aktarımı ve stok taraması bu kuyruğa bağlı.
    |
    */

    'queue' => [
        'pending_threshold' => (int) env('HEALTH_QUEUE_PENDING_THRESHOLD', 100),
        'failed_window_hours' => (int) env('HEALTH_QUEUE_FAILED_WINDOW_HOURS', 24),
        'failed_threshold' => (int) env('HEALTH_QUEUE_FAILED_THRESHOLD', 1),
    ],

];
