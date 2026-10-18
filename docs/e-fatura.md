# Gelen e-Fatura

Tedarikçilerin kliniğe kestiği e-faturalar entegratörden otomatik çekilir, okunur ve saklanır (Faz 6). Kliniğin hastaya kestiği faturalar (giden e-fatura / e-arşiv) kapsam dışıdır.

## Ne yapar

| Ekran | Yol | İşlev |
|---|---|---|
| Ayarlar → e-Fatura Bağlantısı | `/ayarlar` | Entegratör (şimdilik Nilvera), ortam, API anahtarı; "Bağlantıyı dene", kaldır. Yalnızca Admin. |
| Gelen e-Faturalar | `/e-fatura` | Tarih, fatura no, gönderen (ad + VKN/TCKN), senaryo, kalem sayısı, tutar. Arama ve tarih süzgeci. "Şimdi çek" (satın alma yazma yetkisi). |
| Faturadan stoğa al | `/e-fatura/{id}/stoga-al` | Depo seçilir; kalemler faturadan doldurulur, kontrol edilip onaylanır (Aşama 56). Stok girişi yazma yetkisi. |
| Fatura detayı | `/e-fatura/{id}` | Tutarlar, kalemler; ilaç/tıbbi cihaz kalemlerinde GTIN, lot, seri, üretim/son kullanma tarihi. "PDF'i aç" entegratörden getirir. **Eşleşmeler**: tedarikçi (yoksa "Tedarikçi olarak ekle"), satın alma teslimi, kalem ürün kartı, ÜTS'de bu faturaya ait kabul bekleyenler ve "Kabul Bekleyenler'de aç". |

Görmek için Satın Alma okuma yetkisi yeterlidir.

## Nasıl çalışır

- `einvoice:sync` her 30 dakikada, bağlantısı olan **aktif** organizasyonlar için kuyruğa bir iş atar (`SyncEInvoicesJob`, bağlantı başına tek iş).
- İlk çekim son 30 günü, sonrakiler son çekimden 1 gün öncesini tarar; aynı ETTN ikinci kez yazılmaz.
- Her faturanın UBL-TR XML'i indirilir, `storage/app/private/e-invoices/{organizasyon}/{ettn}.xml` altına saklanır ve `UblInvoiceParser` ile okunur. Okuyucu entegratörden bağımsızdır (GİB biçimi her yerde aynı).
- Okunamayan fatura atlanır, günlüğe yazılır ve sonraki çekimde yeniden denenir; diğerleri yazılır.
- Tutarlar kuruş (tam sayı) saklanır. UBL'de nokta her zaman ondalıktır (`190.000` = 190 TL); kuruşa yarım yukarı yuvarlanır.
- İlaç ve tıbbi cihaz faturasında (`ILAC_TIBBICIHAZ`) kalem kimlikleri GİB "İlaç ve Tıbbi Cihaz Teslimlerine İlişkin Fatura Teknik Kılavuzu" V1.2'ye göre okunur: `(UNO)…(LNO)…(SNO)…(URT)YYAAGG` ve `(GTIN)…(BN)…(SN)…(XD)YYAAGG`.

## Eşleştirme (Aşama 45)

Fatura gelir gelmez ve detay her açıldığında **yalnızca boş bağlar** doldurulur; elle yapılan eşleşme ezilmez.

- **Tedarikçi:** gönderenin VKN/TCKN'si = tedarikçi kartındaki vergi no (boşluklar yok sayılır).
- **Satın alma teslimi:** teslim kaydındaki fatura no = e-fatura numarası ve sipariş aynı tedarikçinin; iptal edilmiş teslim sayılmaz.
- **Ürün:** kalem kimliğindeki GTIN/UNO = ürünün GTIN'i (GS1 normalleştirmeli) ya da barkodu; kimlik yoksa satıcı kodu = ürün kodu.
- **ÜTS:** fatura numarası ÜTS'deki Verme bildiriminin belge no'su (`BNO`) olarak sorgulanır. Kabul yine Kabul Bekleyenler'in onay panelinden yapılır (Alma'nın iptali olmadığı için otomatik kabul yoktur). Kabul Bekleyenler `?belge=` ile süzülebilir.
- Eşitleme kuyrukta oturumsuz çalışır; eşleştirmedeki her sorgu organizasyonu açıkça verir (başka kliniğin kaydıyla eşleşmez, testle doğrulandı).

## Finans'a aktarım (Aşama 46)

Finans yazma yetkisi olan kullanıcı detaydaki **"Cari borç olarak aktar"** ile faturayı Finans'a **ödenecek** yükümlülük olarak aktarır. Otomatik borç yoktur (Faz 5 kararı); aktarım her zaman elle yapılır.

- Tutar = faturanın ödenecek tutarı (KDV dahil), belge no = fatura no, vade kullanıcının seçtiği tarih (varsayılan fatura tarihi).
- Cari gönderenin VKN/TCKN'siyle bulunur; yoksa "Tedarikçi" türünde açılır (aynı adlı başka cari varsa ada VKN eklenir).
- Yükümlülük Finans'ın kendi servisiyle (`ObligationService`) açılır; ödeme, vade bildirimi ve raporlar her zamanki gibi çalışır.
- Aynı fatura ikinci kez aktarılamaz; aktarılan yükümlülük Finans'ta iptal edilirse fatura yeniden aktarılabilir.
- TL dışı ve iade faturaları aktarılmaz.

## Stoğa alma (Aşama 56)

Siparişsiz gelen malzeme için: detaydaki **"Stoğa al"** ayrı bir ekran açar. Satın alma teslimiyle eşleşmiş fatura buradan alınmaz (teslimde zaten stoğa girdi); iade ve döviz faturaları da alınmaz (`EInvoiceStockService::refusal`).

- Kalem ürün kartıyla eşleşmişse işaretli gelir; eşleşmemiş kalem (ör. kargo) işaretsiz gelir, istenirse işaretlenip kart seçilir.
- Miktar yalnızca adet birimli kalemlerde (`C62`, `NIU`, `H87`) doldurulur; diğerlerinde ürün birimine çevirip kullanıcı yazar.
- Lot, son kullanma tarihi ve seri numaraları kalem kimliklerinden gelir. Seri takipli üründe her lot ayrı satırdır ve miktar seri sayısıdır. Lot takipli kalemde birden çok lot varsa (lot başına adet faturada yok) kalem dışarıda bırakılır, uyarı gösterilir; Stok Girişi'nden girilir.
- Birim maliyet = kalemin KDV hariç tutarı / girilen toplam miktar.
- Girişler tek işlemde yapılır: biri hata verirse hiçbiri girmez. Hareketler faturaya bağlanır (`related_entity`), gerekçe "e-Fatura {no}, {gönderen}"; fatura `stocked_at`/`stocked_by` alır ve ikinci kez alınamaz.
- **Stok girişini geri al** faturanın hareketlerini iptal eder (ürün kullanıldıysa iptal hatası gösterilir) ve faturayı yeniden alınabilir yapar. Bu hareketler Stok Hareketleri'nden tek tek iptal edilemez.
- ÜTS kabulü ayrıdır; Kabul Bekleyenler'den yapılır.

## Nilvera API anahtarı

1. Nilvera Portal'a girin (test hesabı için Nilvera'dan test kullanıcısı isteyin).
2. Portal'dan API anahtarı üretin; gelen fatura okuma yetkisi yeterlidir.
3. Ayarlar → e-Fatura Bağlantısı'na yapıştırın, ortamı seçin, "Bağlantıyı dene".

Adresler: test `https://apitest.nilvera.com`, canlı `https://api.nilvera.com` (`EINVOICE_NILVERA_TEST_URL`, `EINVOICE_NILVERA_PRODUCTION_URL`, `EINVOICE_TIMEOUT`).

## Güvenlik

- API anahtarı `APP_KEY` ile şifreli (`e_invoice_connections.api_key`), arayüzde geri gösterilmez, günlüğe ve hata mesajına yazılmaz.
- XML okuyucu DOCTYPE içeren belgeyi reddeder ve ağ erişimi kapalıdır (XXE).
- Faturalar organizasyon kapsamlıdır; başka organizasyonun faturası ve PDF'i 404.

## Yeni entegratör eklemek

`App\Domain\EInvoice\Contracts\EInvoiceProvider`'ı uygulayan bir sürücü yazın (`incoming`, `xml`, `pdf`), `EInvoiceConnection::PROVIDERS` ve `EInvoiceProviderFactory::for` içine birer satır ekleyin. Okuma ve saklama değişmez.

## Bilinen sınırlar (gerçek hesapla doğrulanacak)

- Nilvera `xml`/`pdf` uçlarının cevap biçimi belgede yazmıyor; istemci ham dosya, JSON içinde metin ve base64'ü tanır.
- Liste cevabındaki sayfa numarasının 1'den başladığı ve `TotalPages` alanı varsayıldı.
- İstek hız sınırı bilinmiyor.
- Gerçek Nilvera test hesabıyla henüz denenmedi.
