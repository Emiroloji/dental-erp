# ÜTS (Ürün Takip Sistemi) Entegrasyonu

ÜTS, T.C. Sağlık Bakanlığı'na bağlı TİTCK'nın işlettiği tıbbi cihaz takip sistemidir. Tedarikçi bir ürünü kliniğe ÜTS'de **Verme bildirimiyle** gönderir; klinik de **Alma bildirimiyle** kabul eder. Bu uygulama kabul bekleyenleri okur ve saatlik tarar, lot/seri doğrular; **Alma** (Aşama 42), hastaya kullanımda **Kullanım** (Aşama 43), **Almak istemiyorum** (Aşama 47) ve tedarikçiye iadede **Verme** (Aşama 48) bildirimlerini gönderir, iptali olanları iptal eder.

Kaynak: "Takip ve İzleme Web Servis Tanımları Dokümanı" rev. 1.99 (ÜTS 9.8.x), uts.saglik.gov.tr.

## Ne yapar

| Ekran | Yol | İşlev |
|---|---|---|
| Kabul Bekleyenler | `/uts/kabul-bekleyenler` | Tedarikçilerin kurumunuza bildirdiği, henüz kabul edilmemiş ürünler. Her satır ürün kartı (GTIN), lot/seri ve satın alma teslim kaydı (belge no) ile eşleştirilip rozetlenir. Gönderen kurum koduna göre filtrelenir, 10'luk sayfalanır. Stok yazma yetkisi olan, satırları tek tek ya da seçerek **ÜTS'de kabul eder** (aşağıya bakın). |
| Gönderim Geçmişi | `/uts/gecmis` | Bu uygulamadan gönderilen her Alma, Kullanım ve Kullanım iptali: kim, ne zaman, hangi ürün/lot/seri, kaç adet, ÜTS bildirim kodu ya da hata mesajı. Gönderilemeyen kullanım buradan **tekrar gönderilir**, bildirilmiş kullanım **ÜTS'de iptal edilir**. |
| Lot/Seri Doğrula | `/uts/dogrula` | Ürün no + lot/seri yazılır ya da kutudaki GS1 DataMatrix okutulur; ÜTS'deki kayıt, SKT dahil kendi kaydınızla karşılaştırılır. |
| Ayarlar → ÜTS Bağlantısı | `/ayarlar` | Sistem token'ını girme, bağlantıyı deneme, kaldırma (yalnızca Admin). |

Ekranları görme yetkisi Stok modülünü izler ve şube kapsamı geçerlidir.

## Alma bildirimi (kabul)

- Kabul, ÜTS'nin satıra verdiği bildirim koduyla (`VBI`) yapılır; ürün/lot/seri yeniden yazılmaz. Servis: `POST /UTS/uh/rest/bildirim/alma/ekle`.
- **Geri alınamaz.** ÜTS'de Alma bildiriminin iptali yoktur (belge 3.2). Yanlış kabul edilen ürün ancak gönderene iade (Verme) bildirimiyle geri verilebilir. Bu yüzden her gönderim onay panelinden geçer.
- Lot takipli ve adedi 1'den fazla satırda eksik adet kabul edilebilir; seri takipli üründe adet gönderilmez.
- Kabul **stoğa dokunmaz.** Stok girişi satın alma teslim alma ekranından yapılır; "Teslim kaydı var" rozeti eşleşmeyi gösterir.
- Yetki: Stok yazma (`stock_movement.create`). Salt-okunur organizasyonda kapalıdır.
- Aynı bildirim ikinci kez gönderilmez. Başarılı ve başarısız her deneme `uts_notifications` tablosuna yazılır (token yazılmaz).

## Arka plan taraması ve bildirim (Aşama 47)

- `uts:scan-pending` her saat, ÜTS bağlantısı olan **aktif** organizasyonların kabul bekleyenlerini tarar (`ScanUtsPendingJob`, bağlantı başına tek iş; en fazla 20 sayfa).
- İlk kez görülen bildirim kodları `uts_pending_seen`'e yazılır ve Admin ile stok yazma yetkili personele "ÜTS'de kabul bekleyen yeni ürün" bildirimi gider (kaç yeni, hangi gönderenler). Okunmamış ÜTS bildirimi olan kişiye yenisi yığılmaz.
- ÜTS hatası taramayı düşürmez; hiçbir şey "görüldü" sayılmaz, sonraki saat yeniden denenir.

## Almak istemiyorum (Aşama 47)

Kabul Bekleyenler'de satırdaki "Almak istemiyorum" (stok yazma yetkisi, onay paneliyle) bildirimi ÜTS'de `almakIstemiyorum/almakIstemiyorumOlarakIsaretle` (`BID`) ile işaretler; satır listeden kalkar ve Gönderim Geçmişi'ne yazılır. Gerçek ortam kilidi bu işlem için de geçerlidir.

**Geri alma (Aşama 57):** ÜTS → **Almak İstemediklerim** (`/uts/almak-istemediklerim`, stok okuma yetkisi) `almakIstemiyorum/sorgula/almakIstemedigimVermeBildirimlerim` (3.4.25; `ADT` 20, sonraki sayfa için ÜTS'nin döndürdüğü `OFF`) ile işaretlenenleri listeler. Stok yazma yetkisi olan, onay panelinden geçerek **"Almak istiyorum"** der: `almakIstemiyorum/almakIstiyorumOlarakIsaretle` (3.4.23, `BID`); bildirim Kabul Bekleyenler'e döner, kabul yine oradan yapılır. Kayıt `almak_istiyorum` tipiyle Gönderim Geçmişi'ne yazılır. Durumu (`AID`) `ALINMAK_ISTENMIYOR` olan satırlar kurumun kendi işareti değildir; geri alma düğmesi çıkmaz. Dolu olmayan sayfa son sayfa sayılır (son sayfada `OFF`'un dönüp dönmediği belgede yazmıyor).

## Tedarikçiye iade — Verme bildirimi (Aşama 48)

- ÜTS takipli ürünün iadesi **kargoya verildikten sonra** iade detayındaki ÜTS bölümünden bildirilir (iade akışından ayrı adım; ÜTS kesintisi iadeyi durdurmaz).
- İstek `bildirim/verme/ekle`: `UNO`, seri takipli üründe her seri için `SNO`, lot takipli üründe `LNO` + `ADT`, `KUN` (tedarikçinin ÜTS kurum no), `BNO` (iade faturası/irsaliye no), `GIT` (tarih).
- Tedarikçinin ÜTS kurum no'su tedarikçi kartında tutulur; ilk iade bildiriminde ya da ÜTS'den ürün kabul edildiğinde (aynı belge numaralı e-faturanın tedarikçisine, gönderen kurum kodu) kendiliğinden dolar.
- Yarım kalan gönderim tekrar başlatılınca yalnızca eksik seriler gider.
- **İptal vardır** (`bildirim/verme/iptal`). Tedarikçi iadeyi reddederse, ÜTS'de geçerli Verme bildirimi iptal edilmeden "Tedarikçi Reddetti" yapılamaz (ürün stoğa döner).

## İmha ve HEK/Zayiat (Aşama 49)

- ÜTS takipli ürün Stok Çıkışı'nda **"SKT geçmiş"** ya da **"Hasarlı ürün"** nedeniyle çıkarken "ÜTS bildirimi" bölümü açılır (varsayılan işaretli; ÜTS portalından zaten bildirildiyse kaldırılır).
- **İmha / bertaraf** (`bildirim/imhaBertaraf/ekle`): gerekçe (`GRK`; SKT geçmişte varsayılan `SON_KULLANMA_TARIHI_GECMIS`, hasarda `TASIMA_VE_SAKLAMA_KOSULLARI_BOZULMUS`) ve imha tutanağı no (`BNO`, zorunlu).
- **HEK / zayiat** (`bildirim/hekZayiat/ekle`): tür (`TUR`: HEK, çalınma, yangın, doğal afet, stok düzeltme, diğer).
- "Diğer" seçilince açıklama zorunlu (`DGA` / `DTA`, en fazla 50 karakter). Seri takipli üründe her seri ayrı, lot takipli üründe `LNO` + `ADT`.
- Gönderim çıkıştan sonra yapılır; başarısız olursa çıkış kalır, Gönderim Geçmişi'nde "Tekrar gönder" aynı gerekçeyle yeniden dener.
- **İptal vardır** (`imhaBertaraf/iptal`, `hekZayiat/iptal`); ÜTS'de geçerli bildirimi olan çıkış hareketi önce ÜTS'de iptal edilmeden geri alınamaz.

## Kullanım bildirimi (hastaya kullanım)

- Ürün kartında **"Hastaya kullanımda ÜTS'ye bildirilir"** işaretli olmalı (GTIN zorunlu; ör. implant, greft, membran).
- Stok Çıkışı'nda bu ürün **"Klinik içi kullanım"** nedeniyle çıkarken **hasta** (Hastalar kaydından, ad/soyad ya da tam kimlik numarasıyla arama; formdan yeni hasta eklenebilir) ve **kullanım tarihi** istenir.
- Kayıttan sonra her seri (lot takipli üründe her adet ayrı) için `POST /UTS/uh/rest/bildirim/kullanim/ekle` gider: `UNO` (GTIN), `SNO` ya da `LNO` (`ADT` gönderilmez), `GIT` (tarih), `HAA`, `HAS`, `TUR` ve kimlik türüne göre `TKN`/`YKN`/`PAN` (kimliği belirsizde `KTN` = hasta kayıt no).
- ÜTS hatası stok çıkışını geri almaz; ekranda uyarı çıkar, Gönderim Geçmişi'nden tekrar gönderilir.
- **İptal vardır** (`kullanim/iptal`, `BID`). ÜTS'de bildirilmiş bir kullanımın stok çıkışı, önce ÜTS'de iptal edilmeden Stok Hareketleri'nden iptal edilemez.
- Hızlı İşlem'de işaretli ürün "Klinik içi kullanım" ile çıkarılamaz (hasta seçimi Stok Çıkışı'ndadır).
- Hasta kimliği `uts_notifications` tablosuna yazılmaz; kayıt `patient_usages` üzerinden hastaya bağlanır. Hasta verisi ve güvenlik önlemleri için tasarım belgesi: `docs/superpowers/specs/2026-10-18-hasta-ve-uts-kullanim-design.md`.

## Hastanın vücudundan çıkarma (Aşama 55)

- Hasta kartındaki üründe "Vücuttan çıkar" (hasta yazma yetkisi): gerekçe (`GRK`: doku uyumsuzluğu, revizyon, kullanım ömrünü doldurma, geri çağırma, diğer + `DGA`), çıkarma tarihi (`GIT`; kullanım tarihinden önce ve ileri olamaz).
- Çıkarma önce kullanım kaydına yazılır (`patient_usages.removed_on`, `removal_reason`, `removal_note`); kullanım ÜTS'ye bildirilmişse `bildirim/hastaninVucudundanCikarma/ekle` aynı ürün ve hasta alanlarıyla gider. ÜTS hatası yerel kaydı geri almaz; Gönderim Geçmişi'nden tekrar gönderilir.
- **İptal vardır** (`hastaninVucudundanCikarma/iptal`); iptal edilince yerel çıkarma da kaldırılır. Çıkarma bildirimi geçerliyken kullanım bildirimi iptal edilemez (önce çıkarma iptal edilir).

### Gerçek ortam kilidi

Yazma (Alma ve Kullanım) henüz gerçek ÜTS'de denenmedi. Bağlantı **gerçek ortamda** ise gönderim, `UTS_PRODUCTION_WRITES=true` yapılana kadar engellenir ve ekranda "gerçek ortamda bildirim gönderimi henüz açılmadı" yazar. Test ortamı ve okuma ekranları etkilenmez.

Açma sırası: bir klinik test ortamı token'ıyla birkaç satırı kabul eder, sonuç ve gerçek yanıtlar bu belgeye yazılır, ardından sunucuda `UTS_PRODUCTION_WRITES=true`.

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
| `UTS_PRODUCTION_WRITES` | `false` — gerçek ortama bildirim göndermeyi açar |

## Sorun giderme

- **"ÜTS bağlantısı kurulmamış"**: Ayarlar'dan token girilmemiş.
- **"ÜTS token'ı geçersiz veya süresi dolmuş"** (HTTP 401/403): token yanlış, süresi dolmuş ya da yanlış ortama (test/gerçek) girilmiş. Yeni token üretin.
- **"ÜTS şu an yanıt vermiyor"**: ÜTS'ye ulaşılamadı (zaman aşımı). Biraz sonra tekrar deneyin.
- ÜTS'in kendi hata mesajı (`MSJ`, tipi HATA) olduğu gibi ekranda gösterilir ve sunucu günlüğüne yazılır (token yazılmaz).

## Bilinen sınırlar

- Ekranlar canlı sorgudur; ÜTS yavaşsa ya da kapalıysa ekran bekler. Kabul bekleyenler saatte bir taranıp yeni gelen için bildirim düşer (Aşama 47); ekranların kendisi yerel kopyadan çalışmaz.
- ÜTS yanıt biçimi servis tanım dokümanına (rev. 1.99, ÜTS 9.8.x) göre yazıldı ve yerel sahte ÜTS sunucusuyla uçtan uca denendi; **gerçek ÜTS test ortamında henüz denenmedi.**
- Hangi ürünlerin tekil takipli olduğu ve kliniğinizin hangi bildirimlerle yükümlü olduğu mevzuata bağlıdır; uygulama yasal tavsiye vermez.
- Kullanım bildiriminde adet 1'dir (belge 3.1.9 ve `UTS-H222`): lot takipli üründen birden çok adet kullanılınca her adet ayrı kullanım kaydı ve ayrı bildirimdir. Bu yüzden hastaya kullanımda miktar tam sayı olmalıdır.
- Programda olmayan bildirimler: Tüketiciye Verme ve envanter (demirbaş); bunlar ÜTS portalından yapılır.
