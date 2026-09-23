# İzleme: Hata Takibi ve Sağlık Kontrolü

Aşama 28 kapsamında eklendi.

## Hata takibi

500 hataları `App\Domain\Platform\Contracts\ErrorReporter` arayüzü üzerinden
raporlanır. Uygulama kodunda hiçbir yerde sağlayıcı adı geçmez; sürücü
`config/errors.php` ile seçilir (Rapor Asistanı'ndaki `QueryInterpreter` ile
aynı yaklaşım).

| Sürücü | Ne zaman | Ne yapar |
| --- | --- | --- |
| `sentry` | `SENTRY_DSN` tanımlıysa (varsayılan) | Olayı Sentry'nin store uç noktasına HTTP ile gönderir. Resmî SDK'ya bağımlılık yoktur. |
| `log` | DSN tanımlı değilse | Hatayı istek/kullanıcı bağlamıyla `[hata-izleme]` etiketiyle log'a yazar. |
| `none` | Testlerde (`phpunit.xml`) | Hiçbir şey yapmaz, dış servise bağlanılmaz. |

Kurulum:

```dotenv
SENTRY_DSN=https://<public-key>@<org>.ingest.sentry.io/<project-id>
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=2026.09.20      # isteğe bağlı, dağıtım sürümü
```

Ayrıntılar:

- **Neler gönderilir:** yalnızca sunucu hataları. Doğrulama hatası, yetkisiz
  erişim, 404/403/419 gibi kullanıcı kaynaklı durumlar
  (`ExceptionReporting::shouldReport`) gürültü olduğu için gönderilmez.
- **Hangi bağlam:** ortam, URL, HTTP metodu, route adı, kullanıcı id/e-posta ve
  organizasyon id. **Form girdileri bilinçli olarak gönderilmez** — şifre ve
  klinik verisi dışarı çıkmaz.
- **Raporlama hata verirse** (Sentry erişilemez, DSN bozuk) istek etkilenmez;
  sorun log'a uyarı olarak düşer.
- Laravel'in kendi log kaydı her hâlükârda yazılmaya devam eder.

## Sağlık kontrolü

`GET /health` kimlik doğrulaması istemez ve hiçbir klinik verisi döndürmez.

```json
{
  "status": "ok",
  "checked_at": "2026-09-20T21:30:00+00:00",
  "checks": {
    "database": { "status": "ok" },
    "cache": { "status": "ok" },
    "queue": { "status": "ok", "pending": 0, "failed_recently": 0, "messages": [] }
  }
}
```

| Durum | HTTP | Anlamı |
| --- | --- | --- |
| `ok` | 200 | Veritabanı, cache ve kuyruk sağlıklı |
| `degraded` | 200 | Kuyrukta iş birikmiş veya son 24 saatte başarısız iş var — alarm değil, incelenmeli |
| `down` | 503 | Veritabanı veya cache erişilemiyor |

Uç nokta bilerek `web` middleware grubunun dışındadır: oturum sürücüsü Redis
olduğu için Redis çöktüğünde session middleware'i isteği kontrolcüye ulaşmadan
patlatır, sağlık kontrolü de tam ihtiyaç duyulan anda cevap veremezdi. Yerelde
doğrulandı — Redis durdurulduğunda uç nokta `503` ve "Cache'e bağlanılamadı"
mesajını döndürüyor, Redis geri açılınca `200 ok`'a dönüyor.

Uptime izleme servisi (UptimeRobot, Better Stack vb.) bu adrese 1-5 dakikada bir
bağlanacak şekilde ayarlanır; 503 alınca alarm üretir. Laravel'in hazır `/up`
adresi de durur, o yalnızca "PHP ayakta mı" sorusunu yanıtlar.

Eşikler `config/health.php` içindedir:

| Ayar | .env anahtarı | Varsayılan |
| --- | --- | --- |
| Bekleyen iş eşiği | `HEALTH_QUEUE_PENDING_THRESHOLD` | 100 |
| Başarısız iş penceresi (saat) | `HEALTH_QUEUE_FAILED_WINDOW_HOURS` | 24 |
| Başarısız iş eşiği | `HEALTH_QUEUE_FAILED_THRESHOLD` | 1 |

## Log dosyaları

Canlıda `LOG_STACK=daily` kullanılır.

Varsayılan `single`, her şeyi tek bir `storage/logs/laravel.log` dosyasına yazar
ve bu dosya **yalnızca büyür, hiç küçülmez**. Aylar içinde gigabaytlara çıkabilir;
disk dolduğunda PostgreSQL de yazamaz hâle gelir ve sistem durur — sebebi
anlaşılması zor, hoş olmayan bir çöküş biçimi.

`daily` her gün yeni bir dosya açar (`laravel-2026-09-23.log`) ve
`LOG_DAILY_DAYS` (varsayılan 14) gününden eskileri kendisi siler.

```dotenv
LOG_STACK=daily
# LOG_DAILY_DAYS=14
```

Sunucuda ayrıca `logrotate` yapılandırmasına **gerek yoktur**: bu iş uygulama
seviyesinde çözülür, ayar repoda durur ve yeni bir sunucu kurulduğunda
kendiliğinden aynı şekilde çalışır.

Tek sınırı: bölme güne göredir, boyuta göre değil. Tek gün içinde bir hata
döngüsü diski hâlâ doldurabilir; bunun gerçek çözümü log döndürme değil disk
izlemesidir.

## Kurulu izleme

| Ne | Nasıl | Uyarı nereye |
| --- | --- | --- |
| Sunucu/uygulama ayakta mı | Dış uptime servisi → `GET /health` | E-posta |
| Yedek dışarı çıkıyor mu | `backup:check-offsite`, her gün 06:00 | E-posta (`BACKUP_ALERT_EMAIL`) |
| Kuyruk birikiyor mu | `queue:health-check`, saatlik | Log (`[kuyruk]`) + hata kodu |
| Sertifika yenileniyor mu | `certbot.timer` | Let's Encrypt e-postası |
| 500 hataları | `ErrorReporter` | Log (Sentry kurulmadı) |
