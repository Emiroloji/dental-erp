# ÜTS Alma Bildirimi (Aşama B, ilk adım) — Tasarım

Tarih: 18.10.2026 · Aşama 42 · Durum: onaylandı (kararlar kullanıcı tarafından Claude'a bırakıldı)

## Amaç

Kliniğe ÜTS'de yapılmış Verme bildirimlerini uygulamadan **kabul etmek** (Alma bildirimi). Rakip analizinde (18.10.2026, DentFlow ÜTS) en büyük açık "ÜTS'ye yazamıyoruz" çıktı; bu aşama ilk yazma işlemini ekler. Kullanım, Tüketiciye Verme, iade ve imha bildirimleri sonraki aşamalardır.

Kaynak: "ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı" **rev. 1.99 (ÜTS 9.8.x)**, uts.saglik.gov.tr'den indirildi. Bölümler: 2.1 (token), 3.1.6 (Alma Bildirimi), 3.2 (İptal Bildirimleri), 3.3 (Bildirim Cevabı), 3.4.10 (Kabul Edilecek Tekil Ürün Sorgula). Aşama 33 rev. 1.47'ye göre yazılmıştı; okuma servisinin adresi ve alanları 1.99'da aynıdır.

## Belgeden çıkan ve tasarımı belirleyen gerçekler

1. **Alma bildiriminin iptali yoktur** (3.2: "Alma ve Geri Çekme Alma bildirimlerine karşılık olarak iptal bildirimi yoktur"). Yanlış kabulün tek telafisi gönderene iade (Verme) bildirimidir. Bu yüzden her gönderim açık bir onay adımından geçer ve yerel olarak kaydedilir.
2. Servis: `POST /UTS/uh/rest/bildirim/alma/ekle`. Alanlar: `VBI` (karşılık gelen Verme bildiriminin kodu), `ADT` (isteğe bağlı; lot takipli üründe eksik alım için, varsayılan verme adedi), `GKK`, `UDI`, `UNO`, `LNO`, `SNO` (VBI verilmezse eşleştirme için).
3. Kabul Bekleyenler sorgusunun her satırı `BID` (bildirim kodu) taşır. **Kabul her zaman `VBI = BID` ile yapılır**; ürün/lot/seri ile eşleştirme yapılmaz. Böylece "birden fazla bildirim bulundu" (UTS-H171) hatası ve yanlış ürünü kabul etme riski ortadan kalkar.
4. Başarılı cevap: `SNC` = yeni Alma bildiriminin kodu (36 karakter), `MSJ` içinde `TIP: BILGI` (ör. UTS-B006). Hata: `MSJ` içinde `TIP: HATA`. Mevcut hata eşleme aynen kullanılır.

## Kararlar

| Konu | Karar | Neden |
|---|---|---|
| Stokla ilişki | Kabul yalnızca ÜTS'yi günceller; stok girişi bugünkü gibi satın alma teslim alma ekranından yapılır. "Teslim kaydı var/yok" rozeti kalır. | `StockMovementService` tek kapısına dokunmamak; ÜTS başarılı / stok başarısız tutarsızlığını önlemek. |
| Gönderim yeri | Kabul Bekleyenler ekranı: satır başına "Kabul et" ve sayfadaki seçili satırlar için toplu kabul. | Kullanıcı zaten bu listeye bakıyor. |
| Onay | Tarayıcı `confirm()` değil, ekranda onay paneli: ürün, lot/seri, adet, gönderen ve "Bu işlem geri alınamaz" uyarısı. | İptal servisi yok. |
| Eksik adet | Yalnızca lot takipli (seri yok) ve adedi 1'den büyük satırlarda adet değiştirilebilir (1 ≤ adet ≤ verme adedi). | Belge 3.1.6.2. |
| Yetki | `stock_movement.create` (stok yazma). Salt-okunur organizasyonda `.create` soneki kapatır. | Mevcut yetki matrisi; yeni modül açılmaz. |
| Gerçek ortam kilidi | `config('uts.production_writes')` (`UTS_PRODUCTION_WRITES`, varsayılan `false`). Bağlantı gerçek ortamdaysa ve kilit kapalıysa gönderim yapılmaz, anlaşılır mesaj gösterilir. Okuma ekranları etkilenmez. | Yazma henüz gerçek ÜTS'de denenmedi; klinikle test ortamında doğrulandıktan sonra açılacak. |
| Çift gönderim | Aynı Verme bildirimi kodu için başarılı yerel kayıt varsa yeniden gönderilmez. | Çift tık / iki sekme. |
| Kayıt | Yeni `uts_notifications` tablosu, yalnızca ekleme (güncelleme/silme yok). Başarılı ve başarısız her gönderim yazılır. Token yazılmaz. | Denetim ve "kim, ne zaman kabul etti" sorusu. |

## Bileşenler

### `UtsClient::receive(string $notificationId, ?int $quantity = null): string`
Döndürdüğü değer ÜTS'nin yeni bildirim kodu (`SNC`). `HttpUtsClient` gövdeyi `{"VBI": ..., "ADT": ...}` olarak gönderir (ADT yalnızca verildiyse); `SNC` boşsa `UtsException`. `FakeUtsClient` çağrıyı kaydeder ve sahte kod döndürür (ya da tanımlı hatayı fırlatır). `UnconfiguredUtsClient` mevcut mesajla hata verir.

### `UtsNotification` modeli (`app/Domain/Uts/Models`)
`uts_notifications`: `organization_id` (BelongsToOrganization), `user_id`, `type` (`alma`), `source_notification_id` (VBI), `uts_notification_id` (SNC, başarısızsa boş), `status` (`sent` | `failed`), `environment` (test|production), `uno`, `lot`, `serial`, `quantity`, `sender_code`, `sender_name`, `document_no`, `message` (ÜTS mesajı), zaman damgaları. `(organization_id, source_notification_id)` üzerinde dizin.

### `UtsReceiveService` (`app/Domain/Uts/Services`)
`receive(PendingReceipt $receipt, ?int $quantity): UtsNotification`
1. Adet doğrulaması (seri takipli → adet gönderilmez; lot → 1..ADT).
2. Gerçek ortam kilidi.
3. Aynı VBI için başarılı kayıt varsa `UtsException('Bu bildirim daha önce kabul edildi.')`.
4. `client->receive()`; başarıda `sent`, `UtsException`'da `failed` kaydı yazılır ve istisna yeniden fırlatılır.

### Ekranlar
- **Kabul Bekleyenler:** başlıktaki "yalnızca görüntüler" cümlesi değişir; satırlara onay kutusu ve "Kabul et" düğmesi (yetkisi olana). Onay paneli seçilen satırları listeler; gönderim sırayla yapılır, her satırın sonucu (başarılı: ÜTS kodu / hata: mesaj) panelde gösterilir, ardından liste yenilenir. Kabul edilecek satırlar, gönderim anında ÜTS'den aynı sayfa yeniden çekilip `BID` ile bulunur (ekrandaki bayat veriye güvenilmez).
- **Gönderim Geçmişi** (`/uts/gecmis`): `uts_notifications` listesi, 20'lik sayfalama; zaman, kullanıcı, ürün/lot/seri, adet, gönderen, durum, ÜTS kodu/mesaj. Yetki: `stock_movement.viewAny`.

## Hata ve sınır durumları
- Token yok / geçersiz / ÜTS kapalı: mevcut mesajlar.
- Gerçek ortam kilidi: "ÜTS'ye gerçek ortamda bildirim gönderimi henüz açılmadı. Önce test ortamında denenmeli."
- Satır artık bekleyenlerde yok (başka yerden kabul edilmiş): "Bu bildirim artık kabul bekleyenler arasında değil."
- Toplu gönderimde bir satırın hatası diğerlerini durdurmaz.

## Test stratejisi
- `HttpUtsClient`: `Http::fake` ile adres, `utsToken` başlığı, gövde (VBI, isteğe bağlı ADT), `SNC` ayrıştırma, `HATA` mesajı.
- `UtsReceiveService`: başarılı kayıt, başarısız kayıt, çift gönderim reddi, adet sınırları, seri takipli üründe adet gönderilmemesi, gerçek ortam kilidi (açık/kapalı), organizasyon izolasyonu.
- Ekran: yetkisiz kullanıcı düğmeyi görmez ve çağıramaz, salt-okunur organizasyon gönderemez, onay paneli, tekli ve toplu kabul, hata gösterimi, geçmiş ekranı.
- Gerçek doğrulama: kullanıcı bir klinikle **test ortamı** token'ıyla dener; sonuç aşama raporuna ve `docs/uts.md`'ye yazılır, ardından `UTS_PRODUCTION_WRITES=true`.

## Kapsam dışı
Almak İstemiyorum işaretleme (3.4.22), Kullanım / Tüketiciye Verme (hasta kimliği kararı bekliyor), iade/imha/HEK, arka planda eşitleme, e-fatura.

## Uygulama adımları
1. İstemci: arayüze `receive`, Http/Fake/Unconfigured uygulamaları, testler.
2. `uts_notifications` tablosu, model, `UtsReceiveService`, config kilidi, testler.
3. Kabul Bekleyenler ekranında seçim, onay paneli, tekli/toplu kabul.
4. Gönderim Geçmişi ekranı, menü.
5. `docs/uts.md`, README, kabul testi; aşama raporu NotebookLM'e.
