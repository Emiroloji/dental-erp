# Toplu Veri Aktarımı (Aşama 38) Tasarımı

Tarih: 16.10.2026 · Durum: kullanıcı tarafından onaylandı (16.10.2026, soru soru)

## Amaç

Sisteme geçen bir kliniğin 300–500 kalem ürününü ve elindeki mevcut stoğu (lotlarıyla) tek tek elle girmeden, Excel/CSV dosyasıyla içeri alabilmesi. Canlıya geçişteki asıl engel bu.

## Kullanıcı kararları (16.10.2026)

| # | Soru | Karar | Gerekçe |
|---|---|---|---|
| 1 | Kapsam | Ürün kartları + açılış stoğu, iki ayrı şablon | Geçişte asıl ihtiyaç bu ikisi; kategori/tedarikçi ürün dosyasından türer |
| 2 | Hatalı satır | Önizleme; tek hata bile varsa hiçbir satır yazılmaz | Yarım kalmış veri ve ikinci yüklemede tekrar kayıt riski olmaz |
| 3 | Var olan ürün kodu | Hata | İçe aktarma yalnızca yeni ürün ekler; mevcut kartlar ezilmez |
| 4 | Ürünü bulma | Ürün koduyla; içe aktarmada kod zorunlu ve organizasyonda tekil | Ad eşleşmesi yazım farkına kırılgan; elle giriş kuralı değişmez |
| 5 | Olmayan kategori/tedarikçi | Otomatik oluşturulur, önizlemede listelenir | İlk geçişte en az zahmet |
| 6 | Soğuk zincir / seri takipli | Şablonda "Sıcaklık" ve "Seri numaraları" sütunları; kurallar aynen uygulanır | İzlenebilirlik ve ÜTS bildirimi bozulmaz |

Reddedilen yaklaşımlar: **Ürünler ve Stok Girişi ekranlarına ayrı düğmeler** (önizleme/hata mantığı iki yerde tekrarlanır) ve **kuyrukta arka plan işi** (hepsi-ya-hiçbiri ve önizleme karmaşıklaşır; 2.000 satır için gereksiz).

## Kapsam dışı (bilinçli)

Var olan ürünleri güncelleme, birim dönüşüm kuralları ve uyarı eşiklerinin içe aktarılması (ürün ekranından ve toplu eşik atamadan yapılır), tedarikçi iletişim bilgileri, alternatif birimle miktar (açılış stoğu ana birimle girilir), arka plan kuyruğu, içe aktarma geçmişi tablosu, finans verisi.

## 1. Alan ve bileşenler

Yeni alan: `app/Domain/Import`.

| Birim | Görevi |
|---|---|
| `Support/SpreadsheetReader` | `.xlsx`/`.csv` dosyasını başlık satırına göre `array<int, array<string, string>>` satırlara çevirir (maatwebsite/excel `ToArray`). Başlıklar şablondaki Türkçe adlarla eşleşir; boş satırlar atlanır; her satır dosyadaki satır numarasını taşır. |
| `Support/ImportRow` | Tek satırın sonucu: satır no, normalize edilmiş değerler, hata listesi. |
| `Support/ImportResult` | Doğrulama sonucu: satırlar, toplam hata, "oluşturulacak" kategori/tedarikçi adları, `isValid()`. |
| `Importers/ProductImporter` | `validate(rows): ImportResult` ve `import(rows, actor): int`. Yazma `CategoryService`, `SupplierService`, `ProductService::create` üzerinden. |
| `Importers/OpeningStockImporter` | Aynı arayüz. Yazma yalnızca `StockMovementService::in` üzerinden (gerekçe: "Açılış stoğu"). |
| `Exports/ImportTemplateExport` | İndirilebilir şablon: başlıklar + bir örnek satır + "Açıklama" sayfası (sütun anlamları, geçerli değerler; stok şablonunda kullanıcının erişebildiği şube/depo listesi). `TableExport`'taki gibi metin hücreleri düz metin yazılır. |
| `Contracts/Importer` | `validate` / `import` / `headings` / `templateName` arayüzü; sayfa iki importer'ı aynı şekilde kullanır. |

Ürün doğrulama kuralları bugün `pages/catalog/⚡products.blade.php` içinde. İçe aktarmayla aynı kuralların iki yerde ayrı ayrı yaşamaması için ortak alanlar `App\Domain\Catalog\Support\ProductRules` sınıfına çıkarılır ve ürün ekranı da onu kullanır (davranış değişmez; mevcut testler bunu doğrular).

## 2. Şablonlar

### Ürün şablonu (`urun-sablonu.xlsx`)

| Sütun | Zorunlu | Kural |
|---|---|---|
| Ürün Adı | ✔ | ≤255 |
| Ürün Kodu | ✔ | ≤255; dosya içinde ve organizasyonda tekil (büyük/küçük harf ve baş/son boşluk yok sayılarak) |
| Kategori | | Ad; yoksa oluşturulur |
| Tedarikçi | | Ad; yoksa oluşturulur |
| Ana Birim | ✔ | ≤50 (ör. Adet, Kutu) |
| Alış Fiyatı | | sayı ≥0, boşsa 0; `1.250,50` ve `1250.50` kabul |
| Min Stok / Max Stok | | tam sayı ≥0; Min boşsa 0 |
| Barkod | | organizasyonda ve dosyada tekil |
| GTIN | | `Gs1::normalizeGtin` geçerli olmalı; organizasyonda ve dosyada tekil |
| ÜTS No, Ruhsat No, Üretici, Saklama Koşulu | | ≤255 |
| Ürün Türü | | Sarf Malzeme / Ekipman / İlaç (etiketle); boşsa Sarf Malzeme |
| Soğuk Zincir, Kontrollü, Seri Takipli | | E/H (Evet/Hayır, 1/0 da kabul); boşsa H |
| Min Sıcaklık / Max Sıcaklık | soğuk zincirde ✔ | −100…100; max ≥ min |

Seri takip kuralı `ProductService::ensureValidTracking` ile aynen uygulanır.

### Açılış stoğu şablonu (`acilis-stogu-sablonu.xlsx`)

| Sütun | Zorunlu | Kural |
|---|---|---|
| Ürün Kodu | ✔ | Organizasyonda tek bir **aktif** ürüne çözülmeli; birden çok ürün aynı kodu taşıyorsa hata |
| Şube | ✔ | Ad |
| Depo | ✔ | Ad; şube + depo, kullanıcının `stock_movement` kapsamındaki **işleme açık** bir depoya çözülmeli |
| Miktar | ✔ | >0, ana birim; seri takipli üründe tam sayı ve seri sayısına eşit |
| Lot No | | ≤255 |
| SKT | | `GG.AA.YYYY` veya Excel tarih hücresi |
| Birim Maliyet | | ≥0, boşsa 0 |
| Sıcaklık | soğuk zincirde ✔ | −100…100; saklama aralığı dışındaysa hata (içe aktarmada gerekçeyle kabul yok — elle girilir) |
| Seri Numaraları | seri takipli üründe ✔ | virgül veya noktalı virgülle ayrılmış; `SerialRegistry::parseList` kuralları; dosya içinde ve sistemde stokta tekrar etmemeli |

Aynı ürün/depo/lot birden çok satırda gelebilir (her biri ayrı giriş hareketi olur, `resolveOrCreateLot` aynı lota ekler).

## 3. Akış ve ekran

Yeni sayfa **Veri Aktarımı** (`/veri-aktarimi`, `pages::import.index`), menüde Ayarlar'ın yanında. Görünürlük: `product_management.create` **veya** `stock_movement.create` yetkisi. İki sekme: **Ürünler** ve **Açılış Stoğu**; her sekme kendi yetkisiyle açılır.

1. **Şablonu indir** — sekmenin şablonu.
2. **Dosya yükle** — Livewire `WithFileUploads`; yalnızca `xlsx,csv`, en fazla 5 MB.
3. **Önizleme** — dosya okunur, tüm satırlar doğrulanır; veritabanına hiçbir şey yazılmaz. Gösterilen: toplam satır, hatalı satır sayısı, satır satır hata listesi ("Satır 14 · SKT: geçerli bir tarih değil"), ürün sekmesinde "oluşturulacak: 4 kategori, 2 tedarikçi", ilk 20 satırın özeti. Hata varsa "İçe aktar" düğmesi kapalı ve "Dosyayı düzeltip yeniden yükleyin" yazar.
4. **İçe aktar** — dosya yeniden okunur ve **yeniden doğrulanır** (önizleme ile onay arasında veri değişmiş olabilir); sonra tek bir `DB::transaction` içinde yazılır. Herhangi bir hata/istisna tüm işlemi geri alır ve önizlemeye hatayla döner. Başarıda "312 ürün içe aktarıldı" bildirimi.

Satırlar Livewire durumunda tutulmaz (2.000 satırı her istekte taşımamak için); yalnızca yüklenen geçici dosya ve doğrulama özeti tutulur. Satır sınırı: 2.000 (aşılırsa dosya reddedilir). Düğmeler istek sırasında kilitlenir (`wire:loading.attr="disabled"`).

## 4. Güvenlik ve tutarlılık

- Kiracı izolasyonu: tüm modeller `BelongsToOrganization`; depo `Warehouse::operational()->inBranches(accessibleBranchIds(Module::StockMovement))` ile çözülür (Stok Girişi ekranıyla aynı).
- Yetki her eylemde (`Gate::authorize`) yeniden denetlenir: şablon indirme, önizleme, içe aktarma.
- Salt-okunur organizasyon koruması Eloquent yazmaları üzerinden zaten çalışır.
- Denetim kaydı: ürün/kategori/tedarikçi `Auditable` olduğu için her kayıt ayrı izlenir; stok hareketleri kendi izidir.
- Dosya içeriği hiçbir zaman formül olarak yorumlanmaz: okuma hesaplanmış değer yerine ham değeri alır (`WithCalculatedFormulas` kullanılmaz); `=` ile başlayan metin düz metin kalır.
- Yüklenen geçici dosya işlem bitince (başarı ya da vazgeç) silinir.

## 5. Testler

- `ProductImporterTest`: zorunlu alanlar, kod tekilliği (dosya içi + sistem), barkod/GTIN, E/H ayrıştırma, soğuk zincir sıcaklık aralığı, seri takip kuralı, kategori/tedarikçi oluşturma ve var olanla eşleşme, hata varken hiçbir şeyin yazılmaması, başka organizasyonun kodunun çakışma sayılmaması.
- `OpeningStockImporterTest`: ürün kodu çözümü (yok/pasif/çoklu), kapsam dışı ve pasif depo, miktar/SKT/maliyet ayrıştırma, soğuk zincir, seri sayısı ve tekrar, aynı lota çoklu satır, hareket gerekçesi, hepsi-ya-hiçbiri (son satırda servis istisnası → hiçbir lot yok).
- `SpreadsheetReaderTest`: xlsx ve csv, Türkçe başlıklar, boş satır atlama, satır numarası, tarih hücresi, satır sınırı.
- `ImportScreenTest` (Livewire): yetkisiz kullanıcı 403, sekme yetkileri, önizleme hatalarının görünmesi, hata varken içe aktarma reddi, başarılı içe aktarma.
- `ProductManagementTest` değişmeden geçer (`ProductRules` çıkarımı davranışı değiştirmez).
- Kabul testi `BulkImportAcceptanceTest` — şablonu indir → doldur → ürünleri al → açılış stoğunu al → Stok Durumu'nda miktarlar ve lotlar doğru.
