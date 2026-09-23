# Sıfırdan Sunucu Kurulumu (VPS)

Aşama 30. Hedef: **alan adı satın almadan**, yalnızca bir VPS ile uygulamayı
HTTPS üzerinden ayağa kaldırmak. Alan adı sonradan alındığında değişecek tek
şey sunucu adıdır.

Varsayılan ortam: **Ubuntu 24.04 LTS**, root erişimli KVM VPS, 4 GB RAM.

> Sunucuyu almadan önce sağlayıcıya iki şey sorun:
> **(1)** Giden 587 numaralı port açık mı? (kapalıysa hiçbir e-posta gitmez)
> **(2)** Tam root erişimli KVM mi? (OpenVZ ise bazı adımlar çalışmaz)

## 0. Adres: alan adı yokken HTTPS

Let's Encrypt çıplak bir IP adresine sertifika vermez. Alan adı almadan geçici
bir ad için **sslip.io** kullanılır: hiçbir kayıt gerektirmez, `1-2-3-4.sslip.io`
doğrudan `1.2.3.4` adresine çözümlenir ve bu ad Public Suffix List'te olduğu
için Let's Encrypt sertifika verir.

Sunucu IP'niz `1.2.3.4` ise adınız: `1-2-3-4.sslip.io`

Bu belgede geçen `<SUNUCU_ADI>` yerine bunu yazın.

> **Kendi imzaladığınız sertifika işe yaramaz.** Tarayıcı uyarısını geçseniz
> bile PWA kurulumuna ve kameraya izin vermez — yani barkod okutma çalışmaz.
> sslip.io takılırsa alternatif Tailscale Funnel'dır (ücretsiz, kalıcı ad).

## 1. Temel paketler

```sh
apt update && apt upgrade -y
apt install -y nginx postgresql redis-server supervisor git unzip \
    php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
```

Composer:

```sh
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Node (arayüz derlemesi için):

```sh
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
```

### Swap

4 GB RAM bu uygulama için yeterli, ama XLSX üretimi gibi işlerde ani sıçramalar
olur. 2 GB swap güvenlik payıdır:

```sh
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

## 2. Veritabanı

```sh
sudo -u postgres psql
```

```sql
CREATE DATABASE dental_erp;
CREATE USER dental_erp WITH ENCRYPTED PASSWORD 'güçlü-bir-şifre';
GRANT ALL PRIVILEGES ON DATABASE dental_erp TO dental_erp;
\c dental_erp
GRANT ALL ON SCHEMA public TO dental_erp;
\q
```

## 3. Uygulama

```sh
mkdir -p /var/www && cd /var/www
git clone <depo-adresi> dental-erp && cd dental-erp

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

`.env` içinde en az şunlar:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<SUNUCU_ADI>

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=dental_erp
DB_USERNAME=dental_erp
DB_PASSWORD=güçlü-bir-şifre

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

> `APP_DEBUG=false` **şart**. `true` kalırsa bir hata sayfası veritabanı
> şifresini ve ortam değişkenlerini ziyaretçiye gösterir.

Şema ve izinler:

```sh
php artisan migrate --force
php artisan storage:link
chown -R www-data:www-data /var/www/dental-erp/storage /var/www/dental-erp/bootstrap/cache
php artisan optimize
```

## 4. nginx

```sh
cp deploy/nginx/dental-erp.conf /etc/nginx/sites-available/dental-erp
sed -i 's/<SUNUCU_ADI>/1-2-3-4.sslip.io/' /etc/nginx/sites-available/dental-erp
ln -s /etc/nginx/sites-available/dental-erp /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

Bu yapılandırma iki bilinen eksiği kapatır: `.webmanifest` dosyasının
`application/manifest+json` olarak sunulması (aksi hâlde Chrome manifest'i yok
sayar, "Ana ekrana ekle" hiç çıkmaz) ve `sw.js`'in önbelleğe alınmaması.

## 5. HTTPS

```sh
apt install -y certbot python3-certbot-nginx
certbot --nginx -d <SUNUCU_ADI>
```

certbot 443 bloğunu, sertifika yollarını ve 80 → 443 yönlendirmesini kendisi
ekler. Yenileme `certbot.timer` ile otomatiktir:

```sh
systemctl status certbot.timer
certbot renew --dry-run
```

Sertifika yerine oturduktan **sonra** nginx yapılandırmasındaki HSTS satırının
yorumunu kaldırabilirsiniz.

## 6. Zamanlayıcı ve kuyruk worker'ı

```sh
crontab -e -u www-data
```

```cron
* * * * * cd /var/www/dental-erp && php artisan schedule:run >> /dev/null 2>&1
```

```sh
cp deploy/supervisor/dental-erp-worker.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update
supervisorctl status dental-erp-worker:*
```

Ayrıntı: `docs/production.md`.

## 7. E-posta (alan adı yokken)

Alan adınız yokken kendi adınıza gönderim yapamazsınız. Pilot için Gmail SMTP
yeterlidir: Google hesabında **iki adımlı doğrulamayı açın**, ardından
*Uygulama şifreleri* bölümünden 16 haneli bir şifre üretin (normal hesap
şifreniz çalışmaz).

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=hesabiniz@gmail.com
MAIL_PASSWORD=uygulama-şifresi
MAIL_FROM_ADDRESS=hesabiniz@gmail.com
MAIL_FROM_NAME="Dental ERP"
```

Günde ~500 e-posta sınırı vardır; pilot için fazlasıyla yeter. Uçtan uca
deneme:

```sh
php artisan tinker
>>> Illuminate\Support\Facades\Mail::raw('deneme', fn ($m) => $m->to('hesabiniz@gmail.com')->subject('Dental ERP SMTP denemesi'));
```

Gelmezse önce giden 587 portunun açık olduğunu doğrulayın:

```sh
nc -vz smtp.gmail.com 587
```

Gerçek müşteriye açarken alan adı alınıp Resend/Postmark'a geçilir; değişen tek
şey bu beş satırdır.

## 8. Yedekleme

`docs/yedekleme.md` → "Sunucu dışı kopya (rclone)". Özet:

```sh
curl https://rclone.org/install.sh | sudo bash
rclone config          # Google Drive hedefi tanımlanır
rclone mkdir drive:dental-erp-yedek
```

```
BACKUP_OFFSITE_DRIVER=rclone
BACKUP_RCLONE_REMOTE=drive:dental-erp-yedek
BACKUP_RCLONE_CONFIG=/root/.config/rclone/rclone.conf
```

```sh
php artisan backup:run
php artisan backup:check-offsite
```

## 9. Hata izleme

`sentry.io` üzerinde ücretsiz bir hesap açıp DSN'i `.env`'e yazmak yeterlidir;
ek paket gerekmez.

```
SENTRY_DSN=https://...@....ingest.sentry.io/...
SENTRY_ENVIRONMENT=production
```

Tanımlı değilse hatalar log'a yazılır, uygulama yine çalışır. Sentry'nin ayrıca
bir faydası var: `backup:check-offsite` yedeğin dışarı çıkmadığını fark
ettiğinde bildirimi Sentry e-posta olarak gönderir.

Duman testi (geçici bir route ile `throw new RuntimeException('sentry denemesi')`
ve Sentry'de göründüğünün doğrulanması) kurulumdan sonra bir kez yapılmalıdır.

## 10. Güvenlik duvarı

```sh
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable
```

PostgreSQL ve Redis yalnızca `127.0.0.1` dinlemeli; dışarıya açılmamalıdır.

## 11. Kurulum sonrası kontrol listesi

- [ ] `https://<SUNUCU_ADI>` açılıyor, sertifika geçerli
- [ ] `https://<SUNUCU_ADI>/health` → `200`
- [ ] Platform Sahibi hesabıyla giriş yapılıyor
- [ ] Şifremi unuttum e-postası gerçekten geliyor
- [ ] `supervisorctl status` → worker'lar `RUNNING`
- [ ] Bir rapor dışa aktarımı kuyrukta üretilip indirilebiliyor
- [ ] `php artisan backup:run` çalışıyor ve dosya Drive'da görünüyor
- [ ] `php artisan backup:check-offsite` başarılı
- [ ] **Telefonda:** site açılıyor, "Ana ekrana ekle" çıkıyor, uygulama olarak
      açılıyor, Hızlı İşlem'de kamera açılıyor ve gerçek bir barkod okunuyor,
      uçak moduyla çevrimdışı sayfası geliyor

Son madde hiçbir aşamada gerçek cihazda denenmedi; kurulumdan sonra gözle
doğrulanmalıdır.
