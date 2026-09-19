<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rapor Asistanı — Doğal Dille Rapor Sorgulama (Faz 4 — Aşama 25)
    |--------------------------------------------------------------------------
    |
    | Yapay zekâ yalnızca soruyu yapılandırılmış bir rapor sorgusuna (rapor
    | türü + filtreler) çevirir; rakamları sistem kendi raporlarından üretir.
    | Servise yalnızca soru metni ve filtre seçenekleri gider.
    |
    | driver: "gemini" (varsayılan). Başka sağlayıcı eklemek için
    | App\Domain\Assistant\Contracts\QueryInterpreter uygulanır ve
    | AppServiceProvider'daki eşlemeye eklenir.
    |
    */

    'driver' => env('ASSISTANT_DRIVER', 'gemini'),

    // Organizasyon başına sorgu limitleri (Platform Sahibi'nin paketinden bağımsız).
    'daily_limit' => (int) env('ASSISTANT_DAILY_LIMIT', 50),
    'monthly_limit' => (int) env('ASSISTANT_MONTHLY_LIMIT', 1000),

    'max_question_length' => 500,

    // Cevap tablosunda gösterilen en fazla satır.
    'default_rows' => 10,
    'max_rows' => 50,

];
