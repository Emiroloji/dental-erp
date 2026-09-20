# Yedekleme ve Geri Yükleme

Aşama 28 kapsamında eklendi. Yedekleme uygulamanın kendi artisan komutlarıyla
yapılır; ek bir pakete bağımlılık yoktur.

## Nasıl çalışır

- `php artisan backup:run` — aktif PostgreSQL veritabanının `pg_dump` çıktısını
  gzip ile sıkıştırıp yapılandırılan diske yazar, ardından saklama süresi dolmuş
  yedekleri siler.
- `php artisan backup:restore {dosya?} [--force]` — bir yedeği geri yükler.
  Dosya adı verilmezse diskteki yedekler listelenir. `--force` verilmedikçe
  her ortamda onay sorar.
- Zamanlama: `routes/console.php` içinde her gün 02:30'da `backup:run`
  (`withoutOverlapping`). Sunucuda `php artisan schedule:run` cron'a bağlı
  olmalıdır — bkz. `docs/production.md`.

Dosya adı `{veritabani}-YYYY-AA-GG-SSDDss.sql.gz` biçimindedir.

## Ayarlar (`config/backup.php`)

| Ayar | .env anahtarı | Varsayılan | Açıklama |
| --- | --- | --- | --- |
| Disk | `BACKUP_DISK` | `local` | `config/filesystems.php`'deki disk adı. Canlıda yedeğin sunucudan **farklı** bir yerde durması için s3 benzeri bir disk tanımlayın. |
| Klasör | `BACKUP_PATH` | `backups` | Disk içindeki klasör. |
| Saklama | `BACKUP_RETENTION_DAYS` | `14` | Bu günden eski yedekler her çalışmada silinir (kabul aralığı 7–30 gün). |
| pg_dump | `BACKUP_PG_DUMP` | `pg_dump` | PATH üzerinde değilse tam yol veya sarmalayıcı komut. |
| psql | `BACKUP_PSQL` | `psql` | Aynı şekilde. |
| Zaman aşımı | `BACKUP_TIMEOUT` | `900` | Saniye. |

Döküm `--clean --if-exists --no-owner --no-privileges` ile alınır: geri yükleme
var olan şemanın üzerine güvenle yazar ve yedek, farklı bir veritabanı
kullanıcısıyla da açılabilir.

## Geri yükleme prosedürü

1. Uygulamayı bakım moduna alın: `php artisan down`.
2. Queue worker'ları durdurun (`sudo supervisorctl stop dental-erp-worker:*`),
   böylece geri yükleme sırasında iş kuyruğu veriye dokunmaz.
3. **Önce mevcut hâlin yedeğini alın** (`php artisan backup:run`) — yanlış
   yedeği geri yüklerseniz dönebileceğiniz bir nokta kalsın.
4. `php artisan backup:restore` çalıştırın, listeden yedeği seçin ve onaylayın.
5. `php artisan migrate --force` ile şemanın güncel olduğunu doğrulayın
   (yedek eski bir sürümdense eksik migration'lar burada tamamlanır).
6. `php artisan optimize:clear`, ardından worker'ları başlatın ve
   `php artisan up` ile bakım modundan çıkın.
7. `/health` adresini ve birkaç ekranı (dashboard, stok durumu) gözle kontrol edin.

Geri yüklemeyi **önce boş/geçici bir veritabanında denemek** en güvenli yoldur:
`DB_DATABASE=dental_erp_restore_test php artisan backup:restore <dosya> --force`
komutu yedeği yalnızca o veritabanına yazar, canlı veriye dokunmaz.

## Yapılan geri yükleme provası

| | |
| --- | --- |
| Tarih | 20.09.2026 |
| Ortam | Yerel geliştirme (docker-compose, PostgreSQL 16.12) |
| Yedek | `dental_erp-2026-09-20-210424.sql.gz` (13 KB) |

Adımlar ve sonuç:

1. `php artisan backup:run` ile canlı şemanın yedeği alındı.
2. Boş bir `dental_erp_restore_test` veritabanı oluşturuldu (0 tablo).
3. `DB_DATABASE=dental_erp_restore_test php artisan backup:restore dental_erp-2026-09-20-210424.sql.gz --force` çalıştırıldı.
4. Sonuç: 39 tablo geri geldi; satır sayıları kaynak veritabanıyla birebir aynı
   (organizations 2, users 4, products 1, suppliers 1, stock_lots 2,
   stock_movements 3, audit_logs 29).
5. `migrate:status` tüm migration'ları "Ran" gösterdi; `users_id_seq` son değeri
   4 — sequence'ler de doğru geldi, geri yüklenen veritabanına yeni kayıt
   eklenebilir durumda.
6. Prova veritabanı silindi.

Bu prova, sunucu kurulumu değiştiğinde (disk, PostgreSQL sürümü, yedek diski)
tekrarlanmalı ve bu tablo güncellenmelidir.
