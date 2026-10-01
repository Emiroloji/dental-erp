# ÜTS (Ürün Takip Sistemi) Sorgulama

ÜTS, T.C. Sağlık Bakanlığı'na bağlı TİTCK'nın işlettiği tıbbi cihaz takip sistemidir. Tedarikçi bir ürünü kliniğe ÜTS'de **Verme bildirimiyle** gönderir; klinik de **Alma bildirimiyle** kabul eder. Bu uygulama şu an yalnızca **okur**: ÜTS'ye hiçbir bildirim göndermez (yazma Aşama B'dir).

## Ne yapar

| Ekran | Yol | İşlev |
|---|---|---|
| Kabul Bekleyenler | `/uts/kabul-bekleyenler` | Tedarikçilerin kurumunuza bildirdiği, henüz kabul edilmemiş ürünler. Her satır ürün kartı (GTIN), lot/seri ve satın alma teslim kaydı (belge no) ile eşleştirilip rozetlenir. Gönderen kurum koduna göre filtrelenir, 10'luk sayfalanır. |
| Lot/Seri Doğrula | `/uts/dogrula` | Ürün no + lot/seri yazılır ya da kutudaki GS1 DataMatrix okutulur; ÜTS'deki kayıt, SKT dahil kendi kaydınızla karşılaştırılır. |
| Ayarlar → ÜTS Bağlantısı | `/ayarlar` | Sistem token'ını girme, bağlantıyı deneme, kaldırma (yalnızca Admin). |

Ekranları görme yetkisi Stok modülünü izler ve şube kapsamı geçerlidir.

## Token nasıl alınır

Her klinik kendi token'ını üretir; ortak bir anahtar yoktur.

1. Kurum yetkilisi ÜTS portalına girer: gerçek ortam `https://utsuygulama.saglik.gov.tr`, deneme için `https://utstest.saglik.gov.tr`.
2. **Kullanıcı → Sistem Kullanıcısı Tanımlama İşlemleri** menüsünü açar.
3. Çıkan metni **e-imza** ile imzalar; ÜTS bir sistem token'ı üretir.
4. Token, Ayarlar → ÜTS Bağlantısı'na yapıştırılır ve "Bağlantıyı dene" ile doğrulanır.

Token'ı yalnızca kurumun imza yetkilisi (hastanelerde başhekim/yardımcıları) üretebilir. Deneme (test) ortamındaki veriler gerçek ortama aktarılmaz; önce test ortamıyla denenmesi önerilir.

## Güvenlik

- Token veritabanında `APP_KEY` ile şifreli saklanır (`uts_connections.token`). Arayüzde geri gösterilmez, günlüğe ve denetim kaydına yazılmaz.
- Her istekte `utsToken` başlığı olarak gider.
- `APP_KEY` değişirse kayıtlı token'lar çözülemez; yeniden girilmesi gerekir.
- Salt-okunur organizasyonda token değiştirilemez.

## Ortam değişkenleri (isteğe bağlı)

| Değişken | Varsayılan |
|---|---|
| `UTS_TEST_URL` | `https://utstest.saglik.gov.tr` |
| `UTS_PRODUCTION_URL` | `https://utsuygulama.saglik.gov.tr` |
| `UTS_TIMEOUT` | `15` (saniye) |

## Sorun giderme

- **"ÜTS bağlantısı kurulmamış"**: Ayarlar'dan token girilmemiş.
- **"ÜTS token'ı geçersiz veya süresi dolmuş"** (HTTP 401/403): token yanlış, süresi dolmuş ya da yanlış ortama (test/gerçek) girilmiş. Yeni token üretin.
- **"ÜTS şu an yanıt vermiyor"**: ÜTS'ye ulaşılamadı (zaman aşımı). Biraz sonra tekrar deneyin.
- ÜTS'in kendi hata mesajı (`MSJ`, tipi HATA) olduğu gibi ekranda gösterilir ve sunucu günlüğüne yazılır (token yazılmaz).

## Bilinen sınırlar

- Canlı sorgudur; ÜTS yavaşsa ya da kapalıysa ekran bekler. Yerel eşitleme ve "yeni bekleyen var" bildirimi yoktur.
- ÜTS yanıt biçimi servis tanım dokümanına (rev. 1.47, ÜTS 7.6.x) göre yazıldı; gerçek test ortamıyla doğrulama sonuçları aşama raporundadır.
- Hangi ürünlerin tekil takipli olduğu ve kliniğinizin hangi bildirimlerle yükümlü olduğu mevzuata bağlıdır; uygulama yasal tavsiye vermez.
- Aşama B (kapsam): Alma bildirimi (kabul), Kullanım ve Tüketiciye Verme bildirimleri. Tüketiciye Verme hasta kimliği isteyebilir; bu, projenin "hasta kaydı tutmama" kararıyla çeliştiğinden ayrıca karara bağlanacaktır.
