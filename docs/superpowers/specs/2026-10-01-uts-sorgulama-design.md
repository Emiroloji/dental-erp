# ÜTS Entegrasyonu — Aşama A (salt okunur sorgulama) Tasarımı

Tarih: 01.10.2026 · Durum: taslak, kullanıcı onayı bekliyor

## Amaç

Kliniğin ÜTS'deki (Ürün Takip Sistemi) durumunu uygulamadan görebilmek: tedarikçinin kliniğe yaptığı **Verme bildirimlerinden henüz kabul edilmemiş olanları** listelemek ve bir lot/seri numarasını ÜTS'de **doğrulamak**. Bu aşamada ÜTS'ye **hiçbir şey yazılmaz**. Yazma (Alma, Kullanım, Tüketiciye Verme bildirimleri) Aşama B'dir ve ayrı tasarlanır.

Kaynak: "ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı", rev. 1.47 (ÜTS 7.6.x). Bölüm 2.1 (token), 3.4.1 (Tekil Ürün Sorgula), 3.4.7 (Kabul Edilecek Tekil Ürün Sorgula).

## Kapsam dışı (bilinçli)

- Alma/Kullanım/Tüketiciye Verme bildirimi (Aşama B). Tüketiciye Verme hasta kimlik numarası isteyebilir; bu projenin "hasta kaydı tutmama" kararıyla çeliştiği için ayrı karar gerekir.
- Yerel eşitleme tablosu, zamanlanmış çekme işi ve bildirim (gerekirse sonraki adım; istemci aynı kalır).
- Barkodla ürün kartı doldurma: belgede böyle bir katalog servisi yok.
- Ödeme/e-fatura gibi daha önce reddedilmiş konular.

## Bileşenler

### 1. `UtsClient` arayüzü (`app/Domain/Uts`)

Mevcut `QueryInterpreter` deseniyle aynı: arayüz + gerçek uygulama + yapılandırılmamış/sahte uygulama.

- `pendingReceipts(?int $senderCode, int $page): PendingReceiptPage` — `POST .../UTS/uh/rest/bildirim/alma/bekleyenler/sorgula`, gövde `GKK` (gönderen kurum kodu), `SAN` (sayfa, 10'luk).
- `lookup(string $uno, ?string $lot, ?string $serial): UtsItem` — `POST .../UTS/uh/rest/tekilUrun/sorgula`, gövde `UNO`, `LNO`, `SNO`, `SAN`.
- Uygulamalar: `HttpUtsClient` (gerçek), `FakeUtsClient` (testler ve geliştirme), `UnconfiguredUtsClient` (token yokken anlaşılır hata).
- Her istekte `utsToken` başlığı. Adres ortama göre: test `https://utstest.saglik.gov.tr`, gerçek `https://utsuygulama.saglik.gov.tr`.
- Yanıttaki `MSJ` listesi (TIP: BILGI/UYARI/HATA, MET, KOD) `UtsException`'a çevrilir; yetki hatası ayrı bir istisna ile ayırt edilir. Zaman aşımı kısa tutulur.

Değer nesneleri: `PendingReceipt` (GKK, UNO, LNO, SNO, ADT, BID, BNO, BZA, BTI, UIK, GKU, MME), `UtsItem` (UTP, UNO, LNO, SNO, ADT, URT, SKT, ITT, UIK, UAK, SKG, KKG, UDI, MME).

### 2. Token saklama

Yeni tablo `uts_connections`: `organization_id` (benzersiz, `BelongsToOrganization` kapsamı), `environment` (test|production), `token` (`encrypted` cast), `last_verified_at`, zaman damgaları.

- Token arayüzde geri gösterilmez; yalnızca "kayıtlı · son doğrulama: …" yazar.
- Girme/değiştirme/silme yalnızca Ana Klinik Sahibi ve Admin.
- Her klinik kendi token'ını üretir (kurum yetkilisi, e-imza ile, ÜTS portalı). Ortak/platform token'ı yoktur.
- Salt-okunur ve pasif organizasyon kuralları mevcut `ReadOnlyGuard` ile korunur.

### 3. Ekranlar

- **Ayarlar → ÜTS Bağlantısı:** ortam seçimi, token girişi, "Bağlantıyı dene" (başarıda `last_verified_at` güncellenir), silme.
- **ÜTS → Kabul Bekleyenler** (`/uts/kabul-bekleyenler`): bekleyen Verme bildirimleri, 10'luk sayfalama, gönderen kuruma göre filtre. Her satır: ürün (marka/model), UNO, lot/seri, adet, belge no, gönderen, bildirim zamanı. Eşleştirme rozetleri: *Ürün bizde var* (ürün kartı `gtin` = UNO), *Lot/seri bizde var* (`StockLot`/`StockSerial`), *Bekleyen siparişte* (belge no ile satın alma teslimi). Eşleşmeyenler uyarı rozeti alır; bu aşamada eylem butonu yoktur.
- **ÜTS → Doğrula** (`/uts/dogrula`): UNO + lot/seri elle girilir ya da mevcut `ScanResolver` ile GS1 DataMatrix okutulur. Sonuç: ürün tipi, SKT, UDI, takip tipi, marka; bizdeki kayıtla farklar (ör. SKT uyuşmazlığı) vurgulanır.
- Menüde Stok grubunun altında. Yetki: `stock_movement.viewAny` (şube kapsamı korunur). Tüm paketlerde açık (paketler yalnızca sayısal limitle ayrılır).

### 4. Hata ve boş durumlar

Token yok → ekranda "ÜTS bağlantısı kurulmamış" ve Ayarlar'a bağlantı. Yetki hatası → "token geçersiz veya süresi dolmuş". ÜTS erişilemez → "ÜTS şu an yanıt vermiyor, tekrar dene". Boş liste → "Kabul bekleyen bildirim yok". ÜTS'nin ham hata metni (MSJ.MET) kullanıcıya gösterilir ve günlüğe yazılır; token asla günlüğe yazılmaz.

## Test stratejisi

- Birim: `HttpUtsClient` yanıt ayrıştırma ve `MSJ` hata eşleme (`Http::fake`, belgedeki örnek istek/yanıtlarla).
- Özellik: token şifreli saklanır (veritabanında düz metin yok), başka organizasyonun token'ı kullanılamaz, yetkisiz kullanıcı ayara giremez, ekranlar token yok/hata/boş/dolu durumlarını gösterir, eşleştirme rozetleri doğru.
- Kabul: `FakeUtsClient` ile uçtan uca (token gir → bekleyenleri gör → doğrula).
- Gerçek doğrulama: kullanıcının ÜTS **test ortamı** token'ıyla elle deneme; sonuçlar aşama raporuna yazılır.

## Açık noktalar (test hesabında doğrulanacak)

1. `bekleyenler/sorgula` isteğinde `GKK` zorunlu mu, yoksa tüm gönderenler için boş bırakılabilir mi? (Belgede örnek `GKK: 7`.)
2. Bağlantı testi için en güvenli çağrı hangisi (örn. `SAN: 0` ile bekleyenler).
3. `UNO` biçimi: belge "23 karakter ürün numarası/barkod" diyor; ürün kartındaki 14 haneli `gtin` ile birebir mi, başında sıfır dolgusu var mı?
4. ÜTS'nin istek hız sınırı ve hata kodlarının tam listesi (belgenin ilgili bölümü okunacak).
5. Hangi ürünlerin "tekil takipli" olduğu ve diş kliniklerinin hangi bildirimlerle yükümlü olduğu mevzuat teyidi gerektirir; uygulama bu konuda yasal tavsiye vermez.

## Aşama B'ye geçiş

Aynı `UtsClient` arayüzüne `receive()` (Alma bildirimi) eklenir; satın alma teslim alma ekranı ve seri takibi kullanım çıkışı bu çağrılara bağlanır. Tüketiciye Verme için hasta kimliği kararı ayrıca alınır.
