# Dokploy ile Canlı Kurulum

Aşama 32. Uygulama, aynı sunucudaki diğer projelerle birlikte **Dokploy**
üzerinden Docker ile çalışır. Eski elle kurulum (nginx + PHP-FPM + Supervisor
+ cron, `docs/sunucu-kurulumu.md`) bununla **yer değiştirir**; `deploy/`
altındaki dosyalar o kurulumun kaydı olarak duruyor.

## Yapı

`docker-compose.prod.yml` beş servis çalıştırır; üç uygulama servisi aynı
imajı (`Dockerfile`) kullanır, rol `CONTAINER_ROLE` ile seçilir:

| Servis | Ne yapar | Eski kurulumdaki karşılığı |
| --- | --- | --- |
| `dental-app` | Web (FrankenPHP, port 8080). Açılışta `migrate --force` + `optimize` | nginx + PHP-FPM, `deploy.sh` |
| `dental-worker` | `queue:work` (rapor dışa aktarımı, stok taraması) | Supervisor |
| `dental-scheduler` | `schedule:work` (`routes/console.php`) | cron satırı |
| `dental-db` | PostgreSQL 16 | sunucudaki PostgreSQL |
| `dental-redis` | Redis 7 (oturum, önbellek) | sunucudaki Redis |

- Dışarıya hiçbir port açılmaz; HTTPS ve alan adı Dokploy'un Traefik'i üzerinden
  verilir. Bu yüzden `bootstrap/app.php` proxy başlıklarına güvenir
  (`trustProxies`); aksi halde adresler `http://` üretilir.
- `storage/` kalıcı bir volume'dur ve üç uygulama servisinde ortaktır: worker'ın
  ürettiği rapor dosyasını web servisi indirtebilir, `backup:run` yedekleri
  burada tutar.
- Her servisin bellek sınırı vardır (`mem_limit`): bir servis coşarsa aynı
  sunucudaki diğer projeleri boğmaz.
- Loglar dosyaya değil standart çıktıya yazılır (`LOG_CHANNEL=stderr`) ve
  Dokploy panelinde servis bazında görünür.

## 1. Dokploy'da proje

1. **Create Project** → ad: `dental-erp`.
2. **Create Service → Compose** → kaynak: GitHub, depo `Emiroloji/dental-erp`,
   dal `main`, compose yolu `./docker-compose.prod.yml`.
3. **Environment** sekmesine aşağıdaki değişkenleri girin (Dokploy bunları
   compose klasöründeki `.env` dosyasına yazar; servisler `env_file: .env`
   ile okur).
4. **Domains** → alan adı ekle: servis `dental-app`, port `8080`, HTTPS açık,
   sertifika Let's Encrypt. Alan adı yokken: `dental.<IP-tireli>.sslip.io`
   (ör. `dental.91-151-88-152.sslip.io`).
5. **Deploy**.

## 2. Ortam değişkenleri

```env
APP_NAME="Dental ERP"
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show ile üretin
APP_DEBUG=false
APP_URL=https://dental.91-151-88-152.sslip.io
APP_LOCALE=tr
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=dental-db
DB_PORT=5432
DB_DATABASE=dental_erp
DB_USERNAME=dental_erp
DB_PASSWORD=                  # openssl rand -hex 24

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database
REDIS_CLIENT=phpredis
REDIS_HOST=dental-redis
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=                # Google uygulama şifresi
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Dental ERP"

GEMINI_API_KEY=
BACKUP_OFFSITE_DRIVER=none
BACKUP_ALERT_EMAIL=
SENTRY_DSN=
```

`DB_DATABASE`, `DB_USERNAME` ve `DB_PASSWORD` hem uygulamaya hem de
`dental-db` servisinin ilk kurulumuna gider. **Veritabanı volume'u oluştuktan
sonra `DB_PASSWORD`'ü değiştirmek PostgreSQL'deki şifreyi değiştirmez**;
değiştirilecekse önce veritabanında `ALTER USER` yapılır.

## 3. İlk açılış

Migration'lar `dental-app` açılırken kendiliğinden çalışır. Kontrol:

```sh
curl https://dental.91-151-88-152.sslip.io/health   # 200 ve {"status":"ok",...}
```

Boş veritabanıyla başlanıyorsa ilk Platform Sahibi hesabı Dokploy'da
`dental-app` servisinin **Terminal** sekmesinden açılır (demo seeder canlı
imajda çalışmaz, faker geliştirme bağımlılığıdır):

```sh
php artisan tinker
>>> App\Models\User::forceCreate(['organization_id' => null, 'name' => 'Platform Sahibi', 'email' => 'siz@ornek.com', 'password' => bcrypt('geçici-şifre'), 'role' => App\Models\User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
```

Giriş yaptıktan sonra şifre "Şifremi unuttum" akışıyla değiştirilir.

### Eski sunucudan veri taşıma

Eski kurulumdaki veriler `backup:run` yedeğiyle taşınır:

```sh
# eski sunucuda
php artisan backup:run --sunucu-disi-atla
# oluşan storage/app/private/backups/<dosya>.sql.gz bilgisayara indirilir
```

Yeni kurulumda dosya `dental-app` konteynerine kopyalanıp geri yüklenir:

```sh
docker cp <dosya>.sql.gz $(docker ps -qf name=dental-app):/app/storage/app/private/backups/
docker exec -it $(docker ps -qf name=dental-app) php artisan backup:restore <dosya>.sql.gz
```

## 4. Güncelleme

GitHub'a push etmek canlıyı kendiliğinden güncellemez (pilot kararı,
`docs/production.md`). Dokploy panelinden **Deploy** düğmesine basılır; imaj
yeniden derlenir, `dental-app` açılırken migration'ları uygular, worker ve
zamanlayıcı yeni imajla başlar. Otomatik dağıtım istenirse Dokploy'da
"Autodeploy" açılır.

## 5. Yedekleme

- `backup:run` her gece 02:30'da (UTC) `dental-scheduler` içinden çalışır;
  yedekler `storage` volume'unda, `storage/app/private/backups` altındadır.
- İmajda `pg_dump`/`psql` (16) ve `rclone` vardır; sunucu dışı kopya için
  `docs/yedekleme.md`'deki rclone yapılandırması `BACKUP_RCLONE_CONFIG` ile
  gösterilen bir dosyaya konur ve `BACKUP_OFFSITE_DRIVER=rclone` yapılır.

## Yerelde deneme

Compose `env_file: .env` okur; geliştirme `.env`'i ise SQLite kullanır. Bu
yüzden deneme kodun ayrı bir kopyasında yapılır: o kopyada `.env` yukarıdaki
canlı benzeri değerlerle (`APP_URL=http://localhost:8088`) doldurulur ve porta
erişmek için bir `docker-compose.override.yml` ile `dental-app`'e
`ports: ["8088:8080"]` eklenir:

```sh
docker compose -f docker-compose.prod.yml -f docker-compose.override.yml up -d --build
```
