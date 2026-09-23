<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Yedek Dosyalarının Saklandığı Disk ve Klasör
    |--------------------------------------------------------------------------
    |
    | Aşama 28: veritabanı yedekleri config/filesystems.php'deki bir diske
    | yazılır. Yerelde "local" (storage/app/private) yeterlidir; canlıda
    | yedeğin sunucudan farklı bir yerde durması için s3 benzeri bir disk
    | tanımlanıp BACKUP_DISK ile seçilmelidir.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Saklama Süresi
    |--------------------------------------------------------------------------
    |
    | Bu günden eski yedekler her yedekleme sonunda otomatik silinir.
    | Kabul edilen aralık 7-30 gündür.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Araçları
    |--------------------------------------------------------------------------
    |
    | Sunucuda pg_dump/psql PATH üzerinde değilse (ör. veritabanı bir Docker
    | konteynerindeyse) tam yol veya sarmalayıcı bir komut verilebilir.
    |
    */

    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),

    'psql' => env('BACKUP_PSQL', 'psql'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Sunucu Dışı Kopya (Aşama 30)
    |--------------------------------------------------------------------------
    |
    | Yedek sunucunun kendi diskinde durduğu sürece gerçek bir yedek değildir:
    | disk giderse yedek de gider. "rclone" sürücüsü yerel yedek klasörünü
    | uzak bir hedefe (Google Drive, S3, Backblaze...) kopyalar.
    |
    | Neden Flysystem diski değil de rclone: yedekleme cron'la, gözetimsiz
    | çalışır. Google Drive gibi OAuth kullanan hedeflerde token yenileme
    | PHP tarafında kırılgandır; rclone bu işi yıllardır güvenilir yapıyor ve
    | hedef değiştirmek uygulama kodunu hiç ilgilendirmez.
    |
    | "remote" rclone'daki hedef adı ve klasörüdür: "drive:dental-erp-yedek".
    | "config" cron'un farklı bir HOME ile çalıştığı durumlar için rclone.conf
    | dosyasının tam yoludur — boş bırakılırsa rclone kendi varsayılanına bakar.
    |
    | max_age_hours: uzaktaki en yeni yedek bu kadar saatten eskiyse
    | "backup:check-offsite" uyarı verir. Günlük yedekte 48 saat, bir günün
    | kaçmasını tolere eder ama iki günü etmez.
    |
    | Desteklenen sürücüler: "rclone", "none"
    |
    */

    'offsite' => [

        'driver' => env('BACKUP_OFFSITE_DRIVER', 'none'),

        'rclone' => [
            'binary' => env('BACKUP_RCLONE_BINARY', 'rclone'),
            'remote' => env('BACKUP_RCLONE_REMOTE'),
            'config' => env('BACKUP_RCLONE_CONFIG'),
            'timeout' => (int) env('BACKUP_RCLONE_TIMEOUT', 900),
        ],

        'max_age_hours' => (int) env('BACKUP_OFFSITE_MAX_AGE_HOURS', 48),

        /*
        | Yedek dışarı çıkmadığında haber verilecek adres (Aşama 30, ek).
        |
        | Yalnızca log'a yazmak yetmiyor: log dosyasına kimse bakmaz ve
        | yedeğin gitmediği, yedeğe ihtiyaç duyulan gün fark edilir. Adres
        | boş bırakılırsa eski davranış sürer (yalnızca log + hata kodu).
        |
        | throttle_hours: aynı sorun sürerken her gün tekrar tekrar mail
        | atılmaz. Sorun düzeldiğinde tek bir "düzeldi" maili gider, böylece
        | sessizlik "düzeldi" mi "mail de mi gitmiyor" belirsizliği kalmaz.
        */
        'alert_email' => env('BACKUP_ALERT_EMAIL'),

        'alert_throttle_hours' => (int) env('BACKUP_ALERT_THROTTLE_HOURS', 24),

    ],

];
