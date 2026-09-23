# Dental ERP

Çok kiracılı (multi-tenant) diş kliniği **stok ve klinik yönetim sistemi**. Bir
klinik zinciri; şubelerini, depolarını, personel yetkilerini, ürün katalogunu ve
lot/SKT bazlı stoğunu tek yerden yönetir. Her organizasyonun verisi diğerinden
tamamen yalıtılmıştır.

Sistemin çekirdeği `StockMovementService`'tir: stok yalnızca buradan değişir
(giriş, çıkış, transfer, sayım düzeltmesi, iade). Hiçbir ekran veya servis
`stock_lots.quantity` alanını doğrudan güncellemez — bütün modüller bu tek
kapıdan geçer.

## Neler var

- **Organizasyon / şube / depo** hiyerarşisi, modül × okuma-yazma-silme kutucuk
  bazlı personel yetkileri ve şube kapsamı.
- **Katalog:** kategori, tedarikçi, ürün, birim çevrimi ("1 Kutu = 50 Adet").
- **Stok:** lot ve son kullanma tarihi (FEFO), giriş/çıkış/iptal, çoklu depo,
  şubeler arası transfer, satın alma/sipariş ve kısmi teslimat, stok sayımı,
  iade yönetimi.
- **Uyarılar ve raporlar:** ürün bazlı sarı/kırmızı eşik (miktar veya SKT'ye
  kalan gün), kritik stok / SKT taraması, bildirimler, filtreli raporlar,
  Excel/PDF dışa aktarım, denetim kaydı (audit log).
- **Platform paneli:** organizasyon yönetimi, paket limitleri ve manuel
  talep → onay akışı (ödeme entegrasyonu bilinçli olarak yoktur).
- **Barkod/QR ve PWA:** kamera veya el terminaliyle hızlı stok giriş/çıkışı,
  telefona kurulabilen arayüz.
- **Faz 4:** istatistiksel stok tüketim tahmini, doğal dille rapor sorgulama,
  ilaç/medikal genişletmesi (ÜTS, seri no takibi, soğuk zincir, kontrollü ürün).
- **Tanıtım sitesi:** aynı repo içinde girişsiz sayfalar (`/`, `/neler-yapiyor`,
  `/talep`). Talep formu yalnızca bir `Lead` kaydı oluşturur — otomatik hesap
  veya organizasyon açılmaz; Platform Sahibi "Gelen Talepler" ekranından elle
  yürütür.

## Teknoloji

Laravel 13 + Livewire 4 (tek repo, monolit), PostgreSQL, Redis (cache, session,
queue), Tailwind CSS + Vite. Alan (domain) bazlı klasörleme: `app/Domain/Stock`,
`Catalog`, `Transfer`, `Purchasing`, `Inventory`, `Returns`, `Access`,
`Organization`, `Platform`, `Reporting`, `Forecasting`, `Assistant`, `Audit`.

## Kurulum

```sh
git clone <repo> && cd dental-erp
docker compose up -d                 # PostgreSQL + Redis
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed           # demo organizasyon, şube, depo, kullanıcılar
npm install && npm run build
php artisan serve
```

Seeder'dan gelen demo hesaplar (şifre: `password`):

| Hesap | E-posta |
| --- | --- |
| Platform Sahibi | `platform@dental-erp.test` |
| Klinik Admini | `admin@dental-erp.test` |

Kuyruk işleri (rapor dışa aktarımı, stok taraması) için yerelde
`php artisan queue:work`, zamanlanmış görevler için `php artisan schedule:work`.

## Geliştirme

```sh
php artisan test          # tüm test paketi
./vendor/bin/pint         # kod biçimi
npm run dev               # Vite geliştirme sunucusu
```

Her push ve PR'da GitHub Actions aynı üç adımı çalıştırır
(`.github/workflows/ci.yml`); testler kırmızıyken PR birleştirilmez.

## Canlıya çıkış

| Konu | Belge |
| --- | --- |
| Sıfırdan VPS kurulumu: paketler, nginx, HTTPS, e-posta, güvenlik duvarı | [`docs/sunucu-kurulumu.md`](docs/sunucu-kurulumu.md) |
| Zamanlayıcı, Supervisor ile kuyruk worker'ı, dağıtım adımları | [`docs/production.md`](docs/production.md) |
| Günlük yedek, sunucu dışı kopya (rclone), geri yükleme prosedürü ve provası | [`docs/yedekleme.md`](docs/yedekleme.md) |
| Hata takibi (Sentry/log) ve `/health` uç noktası | [`docs/izleme.md`](docs/izleme.md) |

## Şartname belgeleri

Projenin "ne, nasıl ve hangi kurallarla" sorularını yanıtlayan belgeler kod
deposunda değil, projenin NotebookLM defterinde tutulur:

- **proje.md** — fonksiyonel kapsam, modüller, kapsam dışı bırakılanlar.
- **mimari.md** — mimari kararlar: Laravel + Livewire monolit, `app/Domain`
  klasörlemesi, `BelongsToOrganization` kiracı kapsamı, cache ve ölçekleme.
- **kurallar.md** — bağlayıcı iş kuralları (stok hareketleri, transfer durum
  geçişleri, bildirimler).
- **fazlar-adimlar.md** — aşama aşama yol haritası; her aşamanın sonunda geçmesi
  gereken bir "Doğrulama" maddesi vardır.
- Ayrıca her aşamanın sonunda yazılan `asama-N-rapor.md` raporları.

Bir değişiklik bu belgelerle çelişiyorsa belgeler kazanır; önce belge
güncellenir, sonra kod.
