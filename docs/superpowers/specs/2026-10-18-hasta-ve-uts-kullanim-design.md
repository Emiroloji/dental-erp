# Hasta Kaydı ve ÜTS Kullanım Bildirimi — Tasarım

Tarih: 18.10.2026 · Aşama 43 · Durum: onaylandı (kullanıcı "en iyisini yapalım, KVKK'yı avukat netleştirecek" dedi; ayrıntı kararları Claude'a bırakıldı)

## Amaç

Hastaya kullanılan ÜTS takipli ürünü (implant, kemik grefti, membran…) hastaya bağlamak ve ÜTS'ye **Kullanım bildirimi** göndermek. Böylece klinik "bu implant kime takıldı / bu hastaya ne takıldı" sorusunu uygulamadan cevaplar; rakipte olan hasta dosyası açığı kapanır.

## Karar değişikliği

Proje başından beri "hasta kaydı tutulmaz" kararı vardı (kvkk-ve-veri-guvenligi.md Bölüm 1). Kullanıcı 18.10.2026'da bunu değiştirdi: **en az veriyle hasta kaydı tutulur.** Kayıt bir hasta dosyası/tedavi kaydı değildir; yalnızca ÜTS'nin istediği kimlik bilgisi ve hangi ürünün kullanıldığıdır. Aydınlatma metni, açık rıza, VERBİS gibi hukuki konular kullanıcının avukatıyla netleşecek; bu belge teknik önlemleri anlatır. `karar-kaydi.md` ve `kvkk-ve-veri-guvenligi.md` güncellenecek.

Kaynak: ÜTS Web Servis Tanımları rev. 1.99 — 3.1.9 (Kullanım Bildirimi), 3.2 (Kullanım İptal: `bildirim/kullanim/iptal`, `BID`), 3.4.1 (`KKG`: kullanırken kimlik gerekli mi).

## Kapsam

1. Hasta kaydı (yeni `app/Domain/Patients`).
2. Ürün kartında "Hastaya kullanımda ÜTS'ye bildirilir" işareti.
3. Stok Çıkışı'nda, işaretli ürün "Klinik içi kullanım" nedeniyle çıkarken hasta seçimi; kullanım kaydı ve ÜTS Kullanım bildirimi.
4. Gönderim Geçmişi'nde başarısız kullanım bildirimini tekrar gönderme ve gönderilmiş olanı ÜTS'de iptal etme.
5. Hasta detayında kullanılan ürünler ("implant kartı").

Kapsam dışı: tedavi/randevu/hasta dosyası, telefon/adres, Tüketiciye Verme, toplu hasta aktarımı, hasta silme talebi akışı (KVKK unutulma hakkı — avukat görüşünden sonra).

## Veri

### `patients`
| Alan | Not |
|---|---|
| `organization_id` | `BelongsToOrganization`; hastalar organizasyon genelidir (şubeler arası ortak) |
| `first_name`, `last_name` | ÜTS `HAA`, `HAS` (en fazla 50) |
| `identity_type` | `tc` · `foreign` (yabancı kimlik) · `passport` · `unknown` (kimliği belirsiz) → ÜTS `TUR` |
| `identity_no` | **şifreli** (`encrypted` cast, APP_KEY), `$hidden` — denetim kaydına, günlüğe, listelere düz yazılmaz |
| `identity_hash` | HMAC-SHA256(APP_KEY, normalize(no)); organizasyon içinde tekil; tam eşleşmeyle arama ve mükerrer engeli. `$hidden` |
| `birth_year` | isteğe bağlı; aynı adlı hastaları ayırt etmek için |
| timestamps | |

- T.C. kimlik no 11 hane ve resmi kontrol algoritmasıyla doğrulanır.
- Ekranlarda kimlik no maskeli gösterilir (`123******01`); tam numara yalnızca düzenleme formunda, hasta yazma yetkisiyle görünür.
- `Auditable`: oluşturma/değişiklik denetime yazılır; gizli alanlar (`identity_no`, `identity_hash`) otomatik dışarıda kalır.
- Kullanım kaydı olan hasta silinemez.

### `patient_usages`
`organization_id`, `patient_id`, `stock_movement_id`, `product_id`, `stock_lot_id`, `stock_serial_id` (seri takipliyse), `quantity`, `used_on` (tarih), `user_id`, `cancelled_at`. Seri takipli üründe her seri ayrı satırdır.

### `products.uts_usage_report` (boolean, varsayılan false)
"Hastaya kullanımda ÜTS'ye bildirilir". GTIN zorunludur (yoksa işaret kaydedilmez). Ürün düzenleme formunda, ilaç/medikal alanlarının yanında.

### `uts_notifications` eklemeleri
`patient_usage_id` (boş olabilir), `source_notification_id` boş olabilir hale gelir. Yeni `type` değerleri: `kullanim`, `kullanim_iptal`. Kimlik bilgisi bu tabloya **yazılmaz**; hasta, kullanım kaydı üzerinden bulunur.

## Yetki

Yeni modül `Module::Patients` ("Hastalar"): okuma (liste, detay, çıkışta hasta seçme), yazma (ekle/düzenle), silme. Yetki matrisinde diğer modüller gibi görünür; Admin hepsine sahiptir.

- İşaretli ürünü "Klinik içi kullanım" ile çıkarmak: stok yazma + hasta okuma. Hasta okuma yoksa form hasta seçtiremez ve açık mesaj verir.
- ÜTS'de tekrar gönder / iptal et: stok yazma.

## Akış

### Stok Çıkışı
1. Ürün `uts_usage_report` ve neden "Klinik içi kullanım" ise formda **Hasta** (ad, soyad ya da tam T.C. no ile arama; sonuçta maskeli kimlik) ve **Kullanım tarihi** (varsayılan bugün, gelecek olamaz) alanları çıkar; hasta zorunludur. Formdan "Yeni hasta" eklenebilir (hasta yazma yetkisi varsa).
2. `StockMovementService::out` değişmeden çalışır.
3. Ardından `PatientUsageService::record($movements, $patient, $usedOn, $serials)` kullanım satırlarını yazar (aynı veritabanı işleminde değil, çıkışın hemen ardından; çıkış geçerli kalır).
4. `UtsUsageService::report($usage)` her kullanım satırı için ÜTS'ye gönderir. Başarısızlık stok çıkışını geri almaz; kayıt `failed` olur ve ekranda "Stok çıkışı kaydedildi; ÜTS kullanım bildirimi gönderilemedi: …, Gönderim Geçmişi'nden tekrar gönderebilirsiniz" yazar.
- Hızlı İşlem'de işaretli ürün "Klinik içi kullanım" ile çıkarılamaz: "Hasta seçmek için Stok Çıkışı ekranını kullanın." (Hızlı ekran sade kalır.)

### ÜTS isteği (3.1.9)
`UNO` = ürün GTIN'i; seri takipli: `SNO`; lot: `LNO` + `ADT` = miktar; `GIT` = kullanım tarihi (YYYY-AA-GG); `HAA`, `HAS`; kimlik türüne göre `TKN` / `YKN` / `PAN`; `TUR` = `TC_KIMLIK_NUMARASI_VAR` / `YABANCI_KIMLIK_NUMARASI_VAR` / `PASAPORT_NUMARASI_VAR` / `KIMLIGI_BELIRSIZ`; kimliği belirsizde `KTN` = hasta kayıt numarası. Cevap `SNC` = bildirim kodu.

### İptal
- Gönderim Geçmişi'nde gönderilmiş, iptal edilmemiş kullanım satırında "ÜTS'de iptal et" (onay paneliyle). `kullanim/iptal` + `BID`; başarıda `kullanim_iptal` satırı yazılır.
- Stok Hareketleri'nde çıkış hareketi iptal edilirken, bağlı kullanımın ÜTS'de geçerli bir bildirimi varsa iptal reddedilir: "Önce ÜTS kullanım bildirimini iptal edin." Yoksa hareket iptal edilir ve kullanım satırları `cancelled_at` alır.

### Gerçek ortam kilidi
Alma ile aynı `UTS_PRODUCTION_WRITES` kilidi; kontrol tek yere (`UtsWriteGuard`) taşınır.

## Ekranlar
- **Hastalar** (`/hastalar`): arama (ad/soyad, tam T.C. no), liste (ad soyad, maskeli kimlik, doğum yılı, kullanım sayısı), ekle/düzenle modalı. Menüde Stok grubunda ÜTS'nin yanında.
- **Hasta detayı** (`/hastalar/{id}`): kimlik (maskeli), kullanılan ürünler: tarih, ürün, lot/seri, miktar, şube/depo, ÜTS durumu.
- **Stok Çıkışı**: koşullu hasta + tarih alanları.
- **Gönderim Geçmişi**: tür sütunu (Alma / Kullanım / Kullanım iptali), kullanım satırında maskeli hasta adı, "Tekrar gönder", "ÜTS'de iptal et".
- **Ürün düzenleme**: işaret.

## Test stratejisi
- Hasta: T.C. doğrulama, şifreli saklama (DB'de düz metin yok), hash ile tam eşleşme arama ve mükerrer engeli, maskeleme, denetimde kimlik yok, organizasyon izolasyonu, yetkiler, kullanımı olan hastanın silinememesi.
- İstemci: `reportUsage` gövdesi (seri/lot, kimlik türleri), `cancelUsage`.
- Servisler: kayıt yazımı (seri başına satır), başarı/başarısızlık, tekrar gönderme, iptal, gerçek ortam kilidi.
- Ekranlar: Stok Çıkışı koşullu alanlar ve zorunluluk, hasta okuma yetkisi olmadan engel, Hızlı İşlem engeli, hareket iptali kuralı, geçmiş eylemleri, hasta detay.
- Kabul: gerçek `HttpUtsClient` + `Http::fake`: hasta ekle → implant çıkışı → ÜTS isteği → hasta kartında görünür → ÜTS'de iptal → hareket iptali.
- Görsel: sahte ÜTS sunucusu + başsız Chrome, 1280 ve 500 px.

## Uygulama adımları
1. Patients domain: tablo, model, kimlik doğrulama/hash/maske, modül yetkisi, Hastalar ve hasta detay ekranları.
2. Ürün işareti ve ürün formu.
3. ÜTS istemcisi `reportUsage` / `cancelUsage`, `UtsWriteGuard`, `uts_notifications` eklemeleri, `UtsUsageService`, `PatientUsageService`.
4. Stok Çıkışı entegrasyonu, Hızlı İşlem engeli, hareket iptali kuralı.
5. Gönderim Geçmişi eylemleri, kabul testi, belgeler (docs/uts.md, README, KVKK notu ve karar kaydı NotebookLM'de), aşama raporu.
