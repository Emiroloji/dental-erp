# Finans Modülü (Faz 5) Tasarımı

Tarih: 14.10.2026 · Durum: kullanıcı tarafından onaylandı (bölüm bölüm sunuldu, 14.10.2026)

## Amaç

Her kliniğin (organizasyonun) kendi parasını tek yerden yönetmesi: gelir ve gider kaydı, kasa/banka/POS hesapları, cari hesaplar (kurum ve tedarikçi), yaklaşan ödemeler ve tahsilatlar, finansal raporlar ve anlık nakit akışı. Ürün bir **ön muhasebe** aracıdır; resmi muhasebe değildir. Yıl sonunda muhasebeciye Excel dökümü verilir.

## Kullanıcı kararları (14.10.2026)

| # | Soru | Karar | Gerekçe |
|---|---|---|---|
| 1 | Kimin finansı? | Her kliniğin kendi finansı (tenant modülü) | Platform Sahibi'nin finansı kapsam dışı |
| 2 | Gelir nasıl kaydedilir? | Hastasız: kategori, tutar, tarih, ödeme hesabı, şube | Karar 2.6 (hasta kaydı yok) ve KVKK yükü korunur |
| 3 | Muhasebe derinliği | Ön muhasebe; tek taraflı defter | Tek Düzen Hesap Planı / mizan ayrı ürün büyüklüğünde |
| 4 | Stok/satın alma bağı | Yok, finans tamamen ayrı | Kliniğin parası satın alma kayıtlarıyla birebir örtüşmeyebilir |
| 5 | KDV | Yok, tek tutar | Diş hekimliği hizmeti KDV istisnası; KDV'yi muhasebeci tutar |
| 6 | Para birimi | Yalnızca TL; `currency` alanı `TRY` varsayılanla hazır | Döviz ileride göçsüz eklenebilsin |
| 7 | Yaklaşan ödeme/tahsilat | Vadeli kayıt + tekrarlayan kalem; çek/senet yok | Çek/senet ayrı alt modül, istenirse sonra |
| 8 | Banka | Elle; açılış bakiyesi + hareket + mutabakat. API ve ekstre içe aktarma yok | Karar 2.1'in ruhu; her bankanın biçimi farklı |
| 9 | Şube ve yetki | Organizasyon geneli; harekette isteğe bağlı şube etiketi; yalnızca Admin ve "tüm şubeler" kapsamlı personel | Finansı genelde sahip tek elden yönetir |
| 10 | Mimari | Tek defter + yükümlülükler (Yaklaşım 1) | Stoktaki "tek kapı + ters kayıt" deseni; bakiye saklanmaz, türetilir |

Reddedilen yaklaşımlar: **belge tabanlı** (fatura + ödeme; KDV ve fatura istenmediği için belge katmanı boş kalır, transfer/mutabakat zorlama oturur) ve **saklanan bakiye** (iptal/eşzamanlı yazmada bakiye ile geçmiş ayrışır — karar 1.3'ün stokta kaçındığı hata).

## Kapsam dışı (bilinçli)

Çek/senet portföyü, döviz ve kur, KDV, banka API'si ve ekstre içe aktarma, e-fatura/e-arşiv, hasta cari hesabı ve hasta taksiti, satın alma teslimlerinden otomatik borç, çift taraflı muhasebe (hesap planı, yevmiye, mizan, bilanço), şube bazlı finans yetkisi, bütçe/hedef planlama.

## 1. Alan ve veri modeli

Yeni alan: `app/Domain/Finance` (`Models`, `Services`, `Support`, `Policies`, `Exceptions`, `Notifications`, `Jobs`, `Reports`). Tüm modeller `BelongsToOrganization` kullanır; defter (`FinanceEntry`) dışındakiler `Auditable` kullanır. Defter zaten kendi denetim izidir (karar 1.5).

### Para

Tutarlar **kuruş cinsinden tam sayı** saklanır (`bigInteger`, 1.250,50 TL → `125050`). Float yuvarlama hatası üretir; `decimal` ise PHP'de string/float dönüşümü ister. `App\Domain\Finance\Support\Money` küçük bir değer yardımcısıdır: `Money::parse('1.250,50') → 125050`, `Money::format(125050) → '1.250,50 ₺'`. Arayüz tutarı Türkçe biçimde alır ve gösterir; servisler yalnızca kuruş (int) ile çalışır.

### Tablolar

**`finance_accounts`** — kasa, banka, POS hesabı
`id, organization_id, name, type (cash|bank|pos), iban (nullable), opening_balance (bigint), opening_date, currency (default 'TRY'), status (active|inactive), timestamps`

**`finance_categories`** — gelir/gider kategorisi
`id, organization_id, name, direction (income|expense), status, timestamps`. Önerilen kategoriler organizasyon açılışında eklenmez (Platform domain'i klinik verisine dokunmaz); Kategoriler ekranındaki "Önerilenleri ekle" düğmesi eksik olanları ekler: Gelir — Hasılat, Kurum Tahsilatı, Diğer Gelir; Gider — Kira, Maaş, Malzeme, Fatura (elektrik/su/internet), Vergi/SGK, Banka/POS Komisyonu, Diğer Gider. `(organization_id, direction, name)` tekil.

**`finance_parties`** — cari hesap
`id, organization_id, name, kind (customer|supplier|other), phone, email, tax_number, default_term_days (nullable), note, status, timestamps`. Kişi (hasta) açılmaz; ekranda "kurum veya firma" diye yönlendirilir.

**`finance_entries`** — defter (yalnızca eklenir, güncellenmez, silinmez)
`id, organization_id, account_id, type, direction (+1|-1, smallint), amount (bigint, > 0), occurred_on (date), category_id (nullable), party_id (nullable), obligation_id (nullable), branch_id (nullable), transfer_group (uuid, nullable), reverses_entry_id (nullable, unique), description, document_no (nullable), actor_id, created_at`

`type` değerleri (`FinanceEntryType` enum):

| type | direction | Anlamı |
|---|---|---|
| `income` | +1 | Gelir (kategori zorunlu) |
| `expense` | −1 | Gider (kategori zorunlu) |
| `transfer_out` / `transfer_in` | −1 / +1 | Hesaplar arası; iki ayak aynı `transfer_group` |
| `payment` | ±1 | Yükümlülük kapatma: borç ödemesi −1, alacak tahsilatı +1 (`obligation_id` zorunlu) |
| `adjustment` | ±1 | Mutabakat düzeltmesi |
| `reversal` | asıl kaydın tersi | İptal; `reverses_entry_id` asıl kayıt |

İmzalı tutar = `direction × amount`. İndeksler: `(organization_id, account_id, occurred_on)`, `(organization_id, party_id)`, `(organization_id, obligation_id)`, `(organization_id, occurred_on)`.

**`finance_obligations`** — vadeli borç/alacak ("beklenen para")
`id, organization_id, direction (payable|receivable), party_id (nullable), category_id (nullable), amount (bigint), due_on, description, document_no, recurring_id (nullable), branch_id (nullable), status (open|partial|paid|cancelled), timestamps`. `(recurring_id, due_on)` tekil.

**`finance_recurrings`** — tekrarlayan kalem tanımı
`id, organization_id, direction (payable|receivable), name, amount, category_id, party_id (nullable), frequency (weekly|monthly|yearly), day (ayın günü 1–31 / haftanın günü 1–7 / yıl için starts_on'un günü-ayı), starts_on, ends_on (nullable), next_due_on, status, timestamps`. Ayın günü ay uzunluğunu aşarsa ayın son günü kullanılır (31 → Şubat 28/29).

**`finance_reconciliations`** — mutabakat kaydı
`id, organization_id, account_id, statement_date, statement_balance, system_balance, difference, adjustment_entry_id (nullable), actor_id, note, created_at`

### Türetilen değerler (hiçbir yerde saklanmaz)

- Hesap bakiyesi (tarih itibarıyla) = `opening_balance + Σ(direction × amount)` (`occurred_on ≤ tarih`)
- Yükümlülüğün ödenen = `|Σ(direction × amount)|` (bağlı `payment` ve onların `reversal` kayıtları); kalan = `amount − ödenen`
- Cari bakiye = Σ(açık alacakların kalanı) − Σ(açık borçların kalanı). Pozitif: cari bize borçlu; negatif: biz cariye borçluyuz. Yükümlülüğe bağlanmamış cari hareketleri cari ekstresinde görünür ama bakiyeyi değiştirmez (avans kavramı yok, bkz. karar).

Bakiye sorguları `FinanceBalanceService` içinde tek yerde toplanır; organizasyon başına birkaç bin satırda doğrudan toplam yeterli. Büyürse önbellek eklenir (dashboard önbellek deseniyle, hareket sonrası anahtar silinir).

## 2. Servisler ve kurallar

### `LedgerService` — defterin tek kapısı

`finance_entries` tablosuna başka hiçbir sınıf yazmaz (karar 1.3'ün finanstaki karşılığı).

- `income(account, amount, date, category, ?party, ?branch, description, ?documentNo, actor)`
- `expense(...)` aynı imza
- `transfer(from, to, amount, date, description, actor, ?commission)` — iki ayak aynı `transfer_group`; `commission > 0` ise hedef hesaptan "Banka/POS Komisyonu" gideri yazılır (POS → banka geçişinde net tutar yatar).
- `pay(obligation, account, amount, date, actor, ?description)` — kısmi ödeme; `amount > kalan` reddedilir; işlem sonunda yükümlülük durumu `ObligationStatus` kuralına göre güncellenir.
- `adjust(account, signedAmount, date, actor, reconciliation)` — yalnızca `ReconciliationService` çağırır.
- `reverse(entry, reason, actor)` — ters kayıt; gerekçe zorunlu; zaten iptal edilmiş ya da kendisi `reversal` olan kayıt iptal edilemez; transfer iptali iki ayağı (ve varsa komisyon giderini) birlikte ters kayda alır; ödeme iptalinde yükümlülük durumu yeniden hesaplanır.

Her metod `DB::transaction` içinde çalışır. Hesap satırı kilitlenmez: eksi bakiyeye izin verildiği için korunacak bir bakiye değişmezi yoktur; eşzamanlı çift iptali `reverses_entry_id` tekil indeksi veritabanında engeller. (Aşama 35'te `pay` kalan kontrolü yaptığı için yükümlülük satırını `lockForUpdate` ile kilitler.)

"Bugün" `FinanceCalendar::today()` ile Europe/Istanbul takvim günüdür: uygulama UTC çalışır ve 00:00–03:00 arasında UTC hâlâ dündür; aksi hâlde bugünün tarihli hareketi "ileri tarihli" diye reddedilirdi.

### Kurallar

1. Tutar > 0; yön `type` ile belirlenir.
2. Pasif hesaba, pasif kategoriye, pasif cariye hareket yazılmaz (`InactiveFinanceRecordException`).
3. Kategori yönü harekete uymalı (gider kategorisiyle gelir yazılmaz).
4. **Eksi bakiyeye izin var**, ekranda uyarı gösterilir (ek hesaplı banka gerçekliği). Stoktan farklı, bilinçli.
5. Gelecek tarihli hareket yazılmaz (`occurred_on ≤ bugün`); geleceğe ait para yükümlülüktür. Açılış tarihinden önceki tarihe hareket yazılmaz.
6. Transferde kaynak ve hedef aynı olamaz.
7. Her iptal gerekçeli; iptal kaydı orijinalle aynı `occurred_on` değil, iptal günü tarihlenir (geçmiş dönem raporu değişmesin diye: kapanmış ayın raporu sabit kalır, iptal iptal edildiği ayda görünür).

### `ObligationService`

- `create(direction, amount, dueOn, ?party, ?category, description, ?documentNo, ?branch)`; cari seçilmiş ve `dueOn` boşsa `default_term_days` ile hesaplanır.
- `update(...)` yalnızca hiç ödeme bağlanmamışsa.
- `cancel(obligation, reason)` yalnızca etkin ödemesi yoksa (önce ödemeler iptal edilir); durum `cancelled`.
- Durum kuralı: ödenen = 0 → `open`; 0 < ödenen < tutar → `partial`; ödenen = tutar → `paid`.

### `RecurringService` ve `GenerateRecurringObligationsJob`

Zamanlanmış iş her gün çalışır; her etkin tanım için `next_due_on ≤ bugün + 35 gün` olduğu sürece yükümlülük üretir ve `next_due_on`'u ilerletir. `(recurring_id, due_on)` tekil kısıtı ile idempotenttir (iki kez çalışsa da çift kayıt olmaz). Tanımın tutarı değişirse yalnızca henüz üretilmemiş dönemler etkilenir.

### `ReconciliationService`

`reconcile(account, statementDate, statementBalance, actor, ?note)`: o tarihteki sistem bakiyesini hesaplar, farkı kaydeder; fark ≠ 0 ise kullanıcı onayıyla `LedgerService::adjust` çağrılır. Mutabakat geçmişi hesap ekstresinde listelenir.

### `FinanceAlertService` ve bildirim

Günlük taramada (`ScanFinanceDueJob`) vadesi 3 gün içinde dolacak veya geçmiş açık yükümlülükler için mevcut uygulama içi bildirim altyapısıyla `FinanceDueNotification` gönderilir. Alıcılar: Admin + finans okuma yetkili personel. Aynı yükümlülük için aynı türde bildirim günde bir kez (son gönderim tarihi bildirim verisinden kontrol edilir).

## 3. Yetki ve ekranlar

- `Module::Finance = 'finance'`, etiket "Finans"; `FinancePolicy` (diğer modül policy'leri gibi `AuthServiceProvider::MODULE_POLICIES` içinde).
- Personele finans yetkisi yalnızca kapsamı `all` ise verilebilir; `StaffService` doğrulamasında `StaffRuleException`.
- Salt-okunur organizasyonda `.create/.update/.delete` sonekleri sayesinde yazma kendiliğinden kapalı.
- İptal (`reverse`) `finance.delete` değil `finance.update` yetkisi ister (kayıt silinmez; karar 1.5).

Ekranlar `resources/views/pages/finance/⚡*.blade.php`, menüde "Finans" grubu:

| Rota | Ekran | İçerik |
|---|---|---|
| `/finans` | Finans Özeti | Toplam nakit, hesap bakiyeleri, bu ay gelir/gider/net, bu hafta ödenecek ve tahsil edilecek, gecikenler, 90 günlük nakit projeksiyonu grafiği |
| `/finans/hareketler` | Hareketler | Filtreli liste (tarih, hesap, tür, kategori, cari, şube); hızlı gelir/gider/transfer girişi; iptal |
| `/finans/hesaplar` | Hesaplar | Kasa/banka/POS tanımı, pasife alma, hesap ekstresi (yürüyen bakiye), mutabakat |
| `/finans/cariler` | Cari Hesaplar | Cari kartları, bakiye, cari ekstresi |
| `/finans/odemeler` | Ödemeler ve Tahsilatlar | Sekmeler: Geciken / Bu hafta / Bu ay / Tümü; yükümlülük ekle, ödeme yap; tekrarlayan kalem tanımları |
| `/finans/raporlar` | Finans Raporları | Dört rapor, Excel/PDF dışa aktarım |
| `/finans/kategoriler` | Kategoriler | Basit CRUD, pasife alma |

Arayüz `frontend-design` ile, mevcut `app.css` token'larıyla yapılır; telefonda kullanılabilir (PWA). `status-*` renkleri finansta kullanılmaz (karar 4.2); gelir/gider için ayrı `finance-in` / `finance-out` token'ları eklenir. Grafikler sunucu tarafında üretilen satır içi SVG ile çizilir, yeni JS bağımlılığı eklenmez.

## 4. Raporlar ve nakit akışı

`app/Domain/Finance/Reports`; mevcut `ReportFilters` ve `GenerateReportExportJob` (kuyruklu Excel/PDF) yeniden kullanılır, `ReportType` enum'una finans türleri eklenir.

1. **Gelir-Gider:** dönem, kategori ve şube filtresi; kategori toplamları ve aylık kırılım. Transfer, mutabakat düzeltmesi gelir/gider sayılmaz; `payment` kayıtları yükümlülüğün kategorisine göre gelir/gidere dahil edilir (kategorisizse "Kategorisiz").
2. **Nakit Akışı:**
   - Gerçekleşen: aylık giriş, çıkış, net, dönem sonu toplam nakit (transferler iç hareket olduğu için toplamda net sıfır).
   - Projeksiyon (`CashFlowProjectionService`): bugünkü toplam nakit + açık alacakların kalanı − açık borçların kalanı, vade gününe göre günlük birikimli; 30/60/90 gün. Vadesi geçmiş açık kalemler "bugün"e yığılır. Eksiye düşülen ilk gün vurgulanır.
3. **Cari Bakiyeler ve Yaşlandırma:** cari başına açık kalan; vadesi geçmişler 0–30, 31–60, 61–90, 90+ gün kovalarında.
4. **Hesap Ekstresi:** tek hesap, dönem; açılış (dönem başı) bakiyesi, hareketler, yürüyen bakiye, kapanış.

## 5. Aşamalar (Faz 5)

| Aşama | Kapsam | Doğrulama |
|---|---|---|
| 34 — Defter çekirdeği | Göçler (hesap, kategori, defter), `Money`, `LedgerService` (gelir, gider, transfer, iptal), `FinanceBalanceService`, yetki modülü, Hesaplar, Kategoriler, Hareketler ekranları | Açılışı 10.000 TL kasaya 2.500 gelir + 1.000 gider → bakiye 11.500; gider iptali → 12.500; 3.000 kasadan bankaya transfer iki hesapta aynı anda doğru; ikinci organizasyon hiçbirini göremez; yetkisiz personel ekranlara giremez |
| 35 — Cari ve vadeler | Cariler, yükümlülükler, `pay`, tekrarlayan kalemler ve günlük iş, vade bildirimi, Cari ve Ödemeler ekranları | 15.000 TL borç 5.000 + 10.000 ödenir → partial → paid; ödeme iptali → partial; aylık kira tanımı dönemi bir kez üretir (iş iki kez çalışsa da); vadesi geçen için bildirim düşer |
| 36 — Mutabakat, özet, raporlar | Mutabakat, Finans Özeti, dört rapor, dışa aktarım | Ekstre farkı düzeltme hareketiyle kapanır; projeksiyon elle hesaplanmış senaryoyla birebir; Excel dökümü açılır; rakamlar özet ile raporlar arasında tutarlı |
| 37 — Faz 5 kabul testi | Bir aylık klinik senaryosu uçtan uca + izolasyon + yetki + salt-okunur | Her adımda tüm bakiye ve raporlar tutarlı |

## 6. Hata ve boş durumlar

Hesap yok → Finans Özeti "Önce bir kasa veya banka hesabı tanımlayın" ve Hesaplar'a bağlantı. Kategori pasif/yön uyuşmazlığı, kalan aşımı, tekrar iptal, gelecek tarih → servis istisnası, formda Türkçe hata. Eksi bakiye → kayıt yapılır, sarı olmayan nötr bir uyarı gösterilir.

## 7. Test stratejisi

- Birim: `Money` ayrıştırma/biçimlendirme (Türkçe ondalık, binlik ayırıcı, negatif, geçersiz girdi); tekrarlama tarih hesabı (31 → Şubat, artık yıl, haftalık, yıllık).
- `LedgerService`: stok motoru düzeyinde kapsam — her tür, her kural, transaction geri alma, transfer iptalinde iki ayak, ödeme sonrası durum geçişleri, çift iptal reddi.
- Özellik: tenant izolasyonu (ikinci organizasyon), yetki (finans yetkisiz personel, kapsamı sınırlı personele yetki verilememesi, salt-okunur organizasyon), her ekranın boş/dolu/hata durumu.
- Rapor: elle hesaplanmış küçük veri setleriyle birebir rakam karşılaştırması.
- Görsel: her ekran masaüstü ve telefon genişliğinde tarayıcıda gözle kontrol, `npm run build` (karar 5.5).

## 8. Belge güncellemeleri

Kod yazılmadan önce NotebookLM'de: tasarım belgesi (`faz-5-finans-tasarim.md`), `karar-kaydi.md` (2.9 finans kapsamı, 1.7 kuruş ve tek defter kapısı, 2.6'ya not), `bilinen-eksikler.md` (Faz 5 bilinçli dışarıda bırakılanlar). `proje.md` ve `fazlar-adimlar.md` kullanıcının kendi yüklediği kaynaklardır; Faz 5 eki tasarım belgesinde verilir, kullanıcı isterse bu dosyaların yeni sürümünü yükler. Aşama sonlarında `asama-N-rapor.md`; tamamlanınca `veri-modeli.md` ve `terimler-sozlugu.md` güncellenir.
