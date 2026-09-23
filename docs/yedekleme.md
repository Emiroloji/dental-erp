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
| Disk | `BACKUP_DISK` | `local` | `config/filesystems.php`'deki disk adı. Yerel kalır; sunucu dışına çıkarma işini rclone yapar (aşağıya bakın). |
| Klasör | `BACKUP_PATH` | `backups` | Disk içindeki klasör. |
| Saklama | `BACKUP_RETENTION_DAYS` | `14` | Bu günden eski yedekler her çalışmada silinir (kabul aralığı 7–30 gün). |
| pg_dump | `BACKUP_PG_DUMP` | `pg_dump` | PATH üzerinde değilse tam yol veya sarmalayıcı komut. |
| psql | `BACKUP_PSQL` | `psql` | Aynı şekilde. |
| Zaman aşımı | `BACKUP_TIMEOUT` | `900` | Saniye. |

Döküm `--clean --if-exists --no-owner --no-privileges` ile alınır: geri yükleme
var olan şemanın üzerine güvenle yazar ve yedek, farklı bir veritabanı
kullanıcısıyla da açılabilir.

## Sunucu dışı kopya (rclone)

Yedek sunucunun kendi diskinde durduğu sürece **gerçek bir yedek değildir**:
disk giderse yedek de gider. Aşama 30'da `backup:run` komutuna, yedek
alındıktan sonra yerel klasörü uzak bir hedefe kopyalayan bir adım eklendi.

### Neden Flysystem diski değil de rclone

Yedekleme cron'la, kimse bakmadan çalışır. Google Drive gibi OAuth kullanan
hedeflerde token yenileme PHP tarafında kırılgandır ve bozulduğunda **sessizce**
bozulur. rclone bu işi güvenilir yapıyor, hedefi değiştirmek uygulama kodunu
hiç ilgilendirmiyor. Uygulama tarafında yalnızca `OffsiteBackupSync` arayüzü
var (`app/Domain/Platform/Contracts`); bugünkü tek uygulaması rclone, hedef
S3'e taşınırsa yeni bir sınıf yazılır.

`copy` kullanılır, `sync` değil: `sync` yereli birebir yansıtır ve saklama
süresi dolan bir yedek yerelden silindiğinde hedeften de silerdi. `copy`
yalnızca ekler — hedefte yerelden **daha uzun** bir geçmiş birikir. Yedekler
sıkıştırılmış SQL dökümü olduğu için (onlarca KB) bu birikim yıllarca sorun
çıkarmaz; yine de hedef yılda bir gözden geçirilip çok eskiler elle silinebilir.

### Kurulum (Google Drive örneği)

```sh
sudo -v ; curl https://rclone.org/install.sh | sudo bash
```

Hedefi tanımlayın. Sunucuda tarayıcı olmadığı için yetkilendirme
**kendi bilgisayarınızda** yapılır — `rclone config` sırasında
"Use web browser to automatically authenticate?" sorusuna **n** deyin, komut
size kendi bilgisayarınızda çalıştırmanız için bir `rclone authorize` satırı
verir, oradaki çıktıyı sunucuya yapıştırırsınız.

```sh
rclone config
#   n) New remote
#   name> drive
#   Storage> drive
#   client_id / client_secret> (boş bırakılabilir)
#   scope> 1 (full access)  veya  3 (yalnızca rclone'un oluşturduğu dosyalar)
#   Use web browser...> n
```

Klasörü oluşturup bağlantıyı doğrulayın:

```sh
rclone mkdir drive:dental-erp-yedek
rclone lsd drive:
```

`.env`:

```
BACKUP_OFFSITE_DRIVER=rclone
BACKUP_RCLONE_REMOTE=drive:dental-erp-yedek
BACKUP_RCLONE_CONFIG=/root/.config/rclone/rclone.conf
BACKUP_OFFSITE_MAX_AGE_HOURS=48
```

> `BACKUP_RCLONE_CONFIG` **yazılmalıdır**. Cron ve queue worker farklı bir
> kullanıcı ve farklı bir `HOME` ile çalışır; yol verilmezse rclone
> yapılandırmayı bulamaz ve yedekleme yalnızca cron'da, sessizce başarısız olur.
> Dosyanın komutu çalıştıran kullanıcı (`www-data`) tarafından okunabildiğinden
> emin olun.

Elle deneyin:

```sh
php artisan backup:run
php artisan backup:check-offsite
rclone ls drive:dental-erp-yedek
```

### "Yedek gerçekten gitti mi" kontrolü

`backup:check-offsite` hedefteki **en yeni** yedeğin yaşını ölçer.
`BACKUP_OFFSITE_MAX_AGE_HOURS` (varsayılan 48) değerinden eskiyse veya hedefte
hiç yedek yoksa:

- log'a `[yedek]` etiketli bir hata yazar,
- `ErrorReporter` üzerinden hata izlemeye bildirir — `SENTRY_DSN` tanımlıysa
  Sentry bunu e-posta olarak gönderir, yani haberdar olma yolu budur,
- hata koduyla çıkar (cron çıktısı izleniyorsa oradan da görünür).

Zamanlaması `routes/console.php` içinde her gün 06:00 — gece 02:30'da alınan
yedeğin hedefte görünmesi için birkaç saat bırakılmıştır.

### Uyarı maili

`BACKUP_ALERT_EMAIL` doldurulursa sorun doğrudan sorumlunun gelen kutusuna
gider. **Canlıda bu alan doldurulmalıdır**: log'a yazmak tek başına işe
yaramaz, çünkü log dosyasına kimse bakmaz ve yedeğin gitmediği, yedeğe ihtiyaç
duyulan gün fark edilir.

```
BACKUP_ALERT_EMAIL=sorumlu@ornek.com
# BACKUP_ALERT_THROTTLE_HOURS=24
```

Davranış:

- Sorun sürdüğü sürece **günde bir kez** mail atılır (`alert_throttle_hours`).
  Aksi hâlde uyarı gürültüye dönüşür ve okunmaz olur.
- Sorun düzeldiğinde **tek bir "düzeldi" maili** gider. Bu bilinçlidir:
  yalnızca "bozuldu" maili atılsaydı, sessizliğin "düzeldi" mi yoksa "mail de
  mi gitmiyor" mu olduğu anlaşılmazdı.
- Hiç sorun yaşanmadıysa hiç mail gitmez; "her şey yolunda" maili yoktur.

Uyarının gönderilip gönderilmediği önbellekte tutulur (`backup:offsite-alert-sent`).
`php artisan cache:clear` bu hafızayı da siler; sorun sürüyorsa bir sonraki
kontrolde yeniden mail gider.

`backup:run` de sunucu dışı kopya başarısız olursa **hata koduyla çıkar**,
yedek yerelde alınmış olsa bile. Bu bilinçlidir: bir günün kaçtığını o gün
bilmek gerekir.

Sunucu dışı kopyayı geçici olarak atlamak için: `php artisan backup:run --sunucu-disi-atla`.

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
