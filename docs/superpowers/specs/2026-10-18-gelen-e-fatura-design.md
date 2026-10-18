# Gelen e-Fatura (Faz 6, Aşama 44–46) — Tasarım

Tarih: 18.10.2026 · Durum: onaylandı (kullanıcı "e-fatura ile devam edelim" dedi; kapsam ve ayrıntı kararları Claude'a bırakıldı)

## Amaç

Tedarikçinin kliniğe kestiği e-faturaları entegratörden otomatik çekmek, okumak ve kliniğin kendi verisiyle eşleştirmek: tedarikçi kartı, satın alma teslimi, ürün kartı ve ÜTS'deki kabul bekleyenler. İstenirse faturayı Finans'a cari borç olarak aktarmak. Rakip (DentFlow) "fatura–ÜTS otomatik eşleştirme" sunuyor; biz buna stok ve finans bağlantısını ekliyoruz.

## Karar değişiklikleri

- Faz 3'te "e-fatura yok" kararı vardı (ödeme/e-fatura entegrasyonu istenmemişti); kullanıcı 18.10.2026'da e-faturayı istedi. Bu karar **gelen** faturayı kapsar; platformun kliniklerden ödeme alması yine yoktur.
- Faz 5'te "satın alma teslimlerinden otomatik borç" kapsam dışıydı ve finans stoktan ayrıydı. Burada da **otomatik** borç yoktur; finans yetkisi olan kullanıcı faturayı **elle** "cari borç olarak aktar"ır (Aşama 46). Finans defteri ve yükümlülük modeli değişmez.

## Kapsam dışı

Giden e-fatura / e-arşiv (kliniğin hastaya kestiği fatura), e-irsaliye, faturaya ticari yanıt (kabul/red), faturadan otomatik stok girişi, KDV muhasebesi, döviz çevirimi (döviz faturası tutarı olduğu gibi gösterilir, finansa aktarılmaz), birden fazla entegratör sürücüsü (arayüz hazır, ilk sürücü Nilvera).

## Kaynaklar

- Nilvera geliştirici belgesi: `GET einvoice/Purchase` (liste; sayfa, tarih aralığı), `GET einvoice/Purchase/{UUID}/xml` (UBL-TR XML), `.../pdf`; `Authorization: Bearer <API anahtarı>`; test `https://apitest.nilvera.com`, canlı `https://api.nilvera.com`.
- GİB UBL-TR 1.2 ve "İlaç ve Tıbbi Cihaz Teslimlerine İlişkin Fatura Teknik Kılavuzu" V1.2: `ILAC_TIBBICIHAZ` senaryosunda kalem `Item/AdditionalItemIdentification/ID` alanı; `schemeID="TIBBICIHAZ"` → `(UNO)…(LNO)…(SNO)…(URT)YYAAGG`, `schemeID="ILAC"` → `(GTIN)…(BN)…(SN)…(XD)YYAAGG`, `schemeID="DIGER"` → takip dışı kalem.

## Mimari (`app/Domain/EInvoice`)

Mevcut ÜTS ve doğal dil sorgulama desenleri: arayüz + gerçek / sahte / yapılandırılmamış sürücü, organizasyon başına şifreli anahtar.

| Bileşen | Görev |
|---|---|
| `Contracts\EInvoiceProvider` | `incoming(CarbonInterface $since, int $page): IncomingPage`, `xml(string $uuid): string`, `pdf(string $uuid): string`, `name()` |
| `Clients\NilveraProvider` | Nilvera REST; hata/yetki eşlemesi `EInvoiceException` / `EInvoiceAuthException` |
| `Clients\FakeEInvoiceProvider`, `UnconfiguredEInvoiceProvider` | test ve anahtar yokken |
| `Support\UblInvoiceParser` | Entegratörden bağımsız: UBL-TR XML → başlık + kalemler + takip kimlikleri |
| `Support\TrackingIdentity` | `(UNO)…(LNO)…` / `(GTIN)…(BN)…` metnini ayrıştırır |
| `Models\EInvoiceConnection` | `provider` (nilvera), `environment` (test/production), `api_key` (şifreli, gizli), `last_synced_at` |
| `Models\EInvoice`, `EInvoiceLine`, `EInvoiceLineIdentity` | saklanan fatura |
| `Services\EInvoiceSyncService` | yeni faturaları çeker, XML'i saklar ve ayrıştırır; aynı UUID ikinci kez yazılmaz |
| `Services\EInvoiceMatcher` (Aşama 45) | tedarikçi, satın alma teslimi, ürün, ÜTS eşleştirmesi |
| `Services\EInvoiceFinanceService` (Aşama 46) | cari borç aktarımı |

### Veri

- `e_invoice_connections`: organizasyon başına tek satır (ÜTS bağlantısı gibi).
- `e_invoices`: `organization_id`, `provider_uuid` (ETTN; organizasyon içinde tekil), `invoice_number`, `profile` (TEMELFATURA, TICARIFATURA, ILAC_TIBBICIHAZ…), `type` (SATIS, IADE…), `issue_date`, `sender_tax_number`, `sender_name`, `currency`, tutarlar **kuruş bigint** (finans ile aynı: `line_extension`, `tax_exclusive`, `tax_total`, `payable`), `xml_path` (özel disk, `e-invoices/{org}/{uuid}.xml`), `supplier_id`, `purchase_receipt_id`, `finance_obligation_id` (boş olabilir), `received_at`.
- `e_invoice_lines`: `line_no`, `name`, `seller_code`, `quantity` (decimal), `unit_code`, `unit_price` / `line_amount` (kuruş), `vat_rate`, `product_id` (eşleşme).
- `e_invoice_line_identities`: `scheme` (TIBBICIHAZ / ILAC), `gtin` (UNO veya GTIN), `lot`, `serial`, `production_date`, `expiry_date`, `raw`.

### Eşitleme
- `einvoice:sync` komutu her 30 dakikada bağlantısı olan organizasyonlar için kuyruğa iş atar; ekranda "Şimdi çek" düğmesi aynı işi çalıştırır.
- İlk eşitleme son 30 gün; sonra `last_synced_at − 1 gün` (kayma payı). UUID tekil olduğu için tekrar sorun değildir.
- Bir faturanın XML'i alınamaz ya da ayrıştırılamazsa o fatura atlanır, günlüğe yazılır ve sonraki eşitlemede yeniden denenir; diğerleri yazılır.

## Ekranlar

- **Ayarlar → e-Fatura Bağlantısı** (yalnızca Admin): entegratör (Nilvera), ortam, API anahtarı, "Bağlantıyı dene", kaldır. Anahtar geri gösterilmez.
- **Gelen Faturalar** (`/e-fatura`): tarih, fatura no, gönderen (ad + VKN), senaryo, tutar; arama ve tarih süzgeci; "Şimdi çek"; eşleşme rozetleri (Aşama 45).
- **Fatura detayı** (`/e-fatura/{id}`): başlık, tutarlar, kalemler; tıbbi cihaz kalemlerinde UNO/lot/seri/üretim tarihi; "PDF'i aç" (entegratörden). Aşama 45'te eşleşmeler ve ÜTS kısayolu, 46'da finans aktarımı.
- Menü: Satın Alma'nın yanında "e-Fatura".
- Yetki: görmek `purchasing.viewAny`; "Şimdi çek" `purchasing.create`; bağlantı `einvoice_connection.update` (Admin, salt-okunurda kapalı); finansa aktarım `finance.create`.

## Aşama 45 — Eşleştirme
- **Tedarikçi:** `sender_tax_number` = `suppliers.tax_number` (boşluksuz). Yoksa "Tedarikçi olarak ekle" (ad + VKN ile).
- **Satın alma teslimi:** fatura no = `purchase_receipts.invoice_number` ve tedarikçi aynı.
- **Ürün:** kalem kimliğindeki GTIN/UNO = ürün `gtin` (GS1 normalleştirme) ya da `barcode`; yoksa `seller_code` = ürün `code`.
- **ÜTS:** fatura no = kabul bekleyen Verme bildiriminin `BNO`'su (ve gönderen kurum); detayda "ÜTS'de kabul bekleyen N kalem" ve Kabul Bekleyenler'e süzülmüş bağlantı. Kabul yine Aşama 42 onay panelinden geçer (iptalsiz işlem; otomatik kabul yok).
- Eşleşmeler kaydedilir (`supplier_id`, `purchase_receipt_id`, `product_id`); kullanıcı elle değiştirebilir.

## Aşama 46 — Finansa aktarım ve kabul
- Detayda "Cari borç olarak aktar": tedarikçinin finans carisi (yoksa VKN ile açılır), tutar = `payable`, vade = fatura tarihi + carinin varsayılan vade günü, belge no = fatura no, açıklama. TL dışı fatura aktarılamaz. Aynı fatura ikinci kez aktarılamaz; aktarılan yükümlülük iptal edilirse bağ çözülür.
- Faz 6 uçtan uca kabul testi.

## Hata ve sınırlar
- Anahtar yok/geçersiz (401/403) → "e-Fatura bağlantısı kurulmamış / anahtar geçersiz"; entegratör kapalı → "e-Fatura servisi şu an yanıt vermiyor".
- API anahtarı günlüğe, denetim kaydına, hata mesajına yazılmaz.
- XML ayrıştırıcı XXE'ye kapalıdır (`LIBXML_NONET`, harici varlık yok).

## Test stratejisi
- `UblInvoiceParser`: GİB kılavuzundaki örneklerden kurulan UBL dosyaları (temel fatura, ILAC_TIBBICIHAZ tıbbi cihaz + ilaç + DIGER, iade, döviz), tutarların kuruşa çevrimi, takip kimliği ayrıştırma, bozuk XML.
- `NilveraProvider`: `Http::fake` ile adres, başlık, sayfalama, hata eşleme.
- `EInvoiceSyncService`: yeni/tekrar/hatalı XML, organizasyon izolasyonu, `last_synced_at`.
- Ekranlar ve yetkiler; eşleştirme ve finans aktarımı (45–46); uçtan uca kabul.
- Gerçek doğrulama: Nilvera test hesabı açılınca (kullanıcı) — ÜTS gibi proje sonunda.

## Açık noktalar (test hesabında doğrulanacak)
1. `xml` ve `pdf` uçlarının cevap biçimi belgede yazmıyor (ham dosya / base64 / JSON içinde metin). İstemci üçünü de tanır.
2. Liste cevabında tarih biçimi ve `TotalPages` dolu mu.
3. İstek hız sınırı belgede yok.
