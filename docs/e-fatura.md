# Gelen e-Fatura

Tedarikçilerin kliniğe kestiği e-faturalar entegratörden otomatik çekilir, okunur ve saklanır (Faz 6). Kliniğin hastaya kestiği faturalar (giden e-fatura / e-arşiv) kapsam dışıdır.

## Ne yapar

| Ekran | Yol | İşlev |
|---|---|---|
| Ayarlar → e-Fatura Bağlantısı | `/ayarlar` | Entegratör (şimdilik Nilvera), ortam, API anahtarı; "Bağlantıyı dene", kaldır. Yalnızca Admin. |
| Gelen e-Faturalar | `/e-fatura` | Tarih, fatura no, gönderen (ad + VKN/TCKN), senaryo, kalem sayısı, tutar. Arama ve tarih süzgeci. "Şimdi çek" (satın alma yazma yetkisi). |
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
