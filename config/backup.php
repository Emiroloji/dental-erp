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

];
