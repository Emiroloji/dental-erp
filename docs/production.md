# Production Kurulumu

> Sıfırdan bir VPS'e kurulum (paketler, veritabanı, nginx, HTTPS, e-posta,
> güvenlik duvarı) için önce **`docs/sunucu-kurulumu.md`** okunmalıdır. Bu belge
> uygulamanın kurulduktan sonra çalışır kalmasını anlatır.

Aşama 28 kapsamında yazıldı. Sunucuda uygulamanın çalışır durumda kalması için
gereken üç şey: **zamanlayıcı (cron)**, **kuyruk worker'ı (Supervisor)** ve
**izleme (health check + hata takibi)**.

## 1. Zamanlayıcı

Laravel zamanlayıcısı tek bir cron kaydıyla çalışır:

```cron
* * * * * cd /var/www/dental-erp && php artisan schedule:run >> /dev/null 2>&1
```

Buna bağlı işler (`routes/console.php`):

| Komut | Sıklık | Ne yapar |
| --- | --- | --- |
| `stock:scan-levels` | saatlik | Kritik stok / SKT taraması, bildirim üretir |
| `model:prune` | günlük | 7 günü geçen rapor dosyalarını siler |
| `backup:run` | her gün 02:30 | Veritabanı yedeği + eski yedek temizliği |
| `queue:health-check` | saatlik | Biriken ve başarısız kuyruk işlerini log'a uyarı olarak yazar |
| `backup:check-offsite` | her gün 06:00 | Yedeğin sunucu dışına gerçekten kopyalandığını doğrular |

## 2. Kuyruk worker'ı (Supervisor)

Rapor dışa aktarımı (`GenerateReportExportJob`) ve stok taraması
(`ScanStockLevelsJob`) kuyrukta çalışır. Worker düşerse uygulama hata vermez,
işler sessizce birikir — bu yüzden worker bir process manager ile ayakta
tutulmalıdır.

```sh
sudo apt-get install supervisor
sudo cp deploy/supervisor/dental-erp-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status dental-erp-worker:*
```

- `autorestart=true` — worker çökerse Supervisor yeniden başlatır.
- `numprocs=2` — iki worker süreci. Yük arttıkça bu sayı yükseltilir.
- `--max-time=3600` / `--max-jobs=500` — worker düzenli aralıklarla kendini
  yeniler, uzun süren süreçlerde bellek sızıntısı birikmez.
- Her dağıtımdan sonra `php artisan queue:restart` çalıştırılır; worker'lar
  mevcut işi bitirip kapanır ve Supervisor onları yeni kodla başlatır.

**Başarısız işler.** `failed_jobs` tablosu haftada en az bir kez gözden
geçirilir:

```sh
php artisan queue:failed          # listeler
php artisan queue:retry all       # düzeltildiyse yeniden dener
php artisan queue:flush           # artık gereksizse temizler
```

`queue:health-check` komutu bu tabloyu saatlik kontrol eder; son 24 saatte
başarısız iş varsa veya kuyrukta eşiği aşan sayıda iş bekliyorsa log'a
`[kuyruk]` etiketli bir uyarı yazar ve hata koduyla çıkar (cron çıktısı
izleniyorsa doğrudan haber verir). Eşikler `config/health.php` içindedir.

## 3. İzleme

- `GET /health` — veritabanı, cache ve kuyruk bağlantısını kontrol eder.
  Sağlıklıysa `200`, değilse `503` döner. Uptime izleme servisi (UptimeRobot,
  Better Stack vb.) bu adrese bağlanır. Ayrıntı: `docs/izleme.md`.
- 500 hataları `SENTRY_DSN` tanımlıysa Sentry'ye gönderilir, tanımlı değilse
  log'a yazılır. Ayrıntı: `docs/izleme.md`.

## 4. Dağıtım adımları

**GitHub'a push etmek canlıyı güncellemez.** Sunucu kodu `git pull` ile alır ve
bunu tetikleyen bir şey yoktur; her dağıtım bilinçli olarak başlatılır.

Yerel bilgisayardan tek komut:

```sh
ssh root@<sunucu> /var/www/dental-erp/deploy/deploy.sh
```

Betik (`deploy/deploy.sh`) sırayla şunları yapar: bakım moduna alır, kodu
`origin/main`'e sabitler, `composer install --no-dev`, `npm ci && npm run build`,
`migrate --force`, `optimize`, dosya izinleri, `queue:restart`, bakım modundan
çıkar ve son olarak `/health` adresini kontrol eder. Herhangi bir adım
başarısız olursa orada durur ve uygulamayı bakım modunda bırakmaz.

Elle yapmak gerekirse aynı adımlar:

```sh
php artisan down
git fetch origin && git reset --hard origin/main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize          # config/route/view cache
chown root:www-data .env && chmod 640 .env
php artisan queue:restart
php artisan up
```

> `.env` dosyasının `www-data` tarafından okunabilir olması şarttır. Okunamazsa
> Laravel ayarları alamaz ve **sessizce** varsayılanlara (SQLite) düşer: site
> açılır ama yanlış veritabanıyla çalışır. `/health` bunu yakalar.

### Otomatik dağıtım (isteğe bağlı)

Her push'ta canlının kendiliğinden güncellenmesi istenirse GitHub Actions'tan
SSH ile bu betik çağrılabilir. Pilot sürecinde **bilinçli olarak
kurulmamıştır**: tek klinikle çalışırken dağıtımın ne zaman olacağını kontrol
etmek, yanlışlıkla yarım bir değişikliği canlıya göndermekten daha değerlidir.

## 5. Yedekleme

Günlük otomatik yedek, sunucu dışı kopya (rclone) ve geri yükleme prosedürü
için `docs/yedekleme.md`.
