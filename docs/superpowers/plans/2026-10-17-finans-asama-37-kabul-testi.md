# Finans Aşama 37 — Faz 5 Kabul Testi ve Ertelenenler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aşama 36'dan ertelenen rapor eksiklerini (Gelir-Gider kategori süzgeci, dökümde aylık kırılım, küçük grafik/test boşlukları) kapatmak ve Faz 5'i bir aylık klinik senaryosunu ekranlardan uçtan uca yürüten kabul testiyle bitirmek.

**Architecture:** Mevcut mimari korunur: deftere yalnızca `LedgerService` yazar, bakiye saklanmaz, raporlar `app/Domain/Finance/Reports` servislerinden türetilir. Kategori süzgeci `FinanceReportFilters`'a yeni bir isteğe bağlı alan olarak eklenir (yapıcıda **son** parametre; mevcut konumsal çağrılar bozulmaz). Kabul testi Faz 4 kabul testinin desenini izler: adım metotları, ekranlar Livewire ile sürülür, her adımdan sonra ortak bir "defter tutarlı mı" denetimi çalışır.

**Tech Stack:** Laravel 12, Livewire 4 tek dosyalı sayfalar, PHPUnit (SQLite bellek içi), Pint, PhpSpreadsheet (maatwebsite/excel üzerinden).

**Spec:** `docs/superpowers/specs/2026-10-14-finans-modulu-design.md` (§4.1 kategori süzgeci ve aylık kırılım; §5 Aşama 37: "Bir aylık klinik senaryosu uçtan uca + izolasyon + yetki + salt-okunur — Her adımda tüm bakiye ve raporlar tutarlı"; §7 test stratejisi).

## Global Constraints

- Tutarlar kuruş cinsinden `int`; arayüz Türkçe biçim (`Money::parse('1.250,50')`, `Money::format(125050) → '1.250,50 ₺'`).
- "Bugün" yalnızca `FinanceCalendar::today()` (Europe/Istanbul). Testte saat hem `CarbonImmutable::setTestNow` hem `Carbon::setTestNow` ile, `Europe/Istanbul` diliminde sabitlenir.
- `finance_entries` tablosuna yalnızca `LedgerService` yazar; testler de hareketi servis veya ekran üzerinden yazar, `FinanceEntry::create` kullanmaz.
- Hiçbir bakiye/toplam saklanmaz; yeni kolon ve migration yok.
- Livewire sayfalarında `use InvalidArgumentException;` yazılmaz; `catch (\InvalidArgumentException $e)` kullanılır.
- Yeni JS bağımlılığı yok; grafikler sunucu tarafı SVG.
- Kullanıcıya görünen tüm metin Türkçe.
- Her görev sonunda: `php artisan test` tamamı yeşil, `vendor/bin/pint --test` temiz.
- Commit mesajı biçimi: `feat|fix|test|docs: <Türkçe özet> — Aşama 37 (N/5)`; sonda `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` satırı.

---

## Dosya Haritası

| Dosya | Sorumluluk | Görev |
|---|---|---|
| `app/Domain/Finance/Reports/FinanceReportFilters.php` | `categoryId` alanı: ayrıştırma, `toArray`, `describe` | 1 |
| `app/Domain/Finance/Reports/IncomeExpenseReportService.php` | kategori süzgeci (1), dökümde aylık kırılım (2) | 1, 2 |
| `app/Domain/Finance/Reports/FinanceReportExporter.php` | kategori süzgecini yalnızca Gelir-Gider'e geçirir | 1 |
| `resources/views/pages/finance/⚡reports.blade.php` | Kategori seçimi (yalnızca Gelir-Gider sekmesi) | 1 |
| `app/Domain/Finance/Support/FinanceChart.php` | sıfırdan büyük çubuğa en az görünür yükseklik | 3 |
| `tests/Unit/Finance/FinanceReportFiltersTest.php`, `tests/Feature/Finance/IncomeExpenseReportTest.php`, `tests/Feature/Finance/FinanceReportsScreenTest.php`, `tests/Feature/Finance/FinanceReportExportTest.php` | süzgeç ve döküm testleri | 1, 2 |
| `tests/Unit/Finance/FinanceChartTest.php`, `tests/Feature/Finance/FinanceEntriesScreenTest.php`, `tests/Feature/Finance/FinanceAccountScreenTest.php` | test boşlukları | 3 |
| `tests/Feature/Acceptance/Faz5FinanceAcceptanceTest.php` | Faz 5 uçtan uca kabul testi | 4 |
| `README.md` | Faz 5 tamamlandı notu | 5 |

---

### Task 1: Gelir-Gider raporuna kategori süzgeci

Spec §4.1: "Gelir-Gider: dönem, kategori ve şube filtresi". Kategori süzgeci yalnızca Gelir-Gider raporunda geçerlidir (şube süzgeciyle aynı kural): Nakit Akışı ve Yaşlandırma sekmelerine/dökümlerine taşınmaz.

Süzgeç anlamı: `finance_entries.category_id = X`. Ödeme/tahsilat kayıtları yükümlülüğün kategorisini taşır; iptal kayıtları iptal ettiği kaydın kategorisini taşır (`LedgerService::reverse` `category_id`'yi kopyalar) — bu yüzden ek birleştirme gerekmez. Kategori bir gelir kategorisiyse rapor yalnızca gelir kovasında o kategoriyi, gider kategorisiyse yalnızca gider kovasında gösterir; aylık kırılım da yalnızca o kategoriyi toplar.

**Files:**
- Modify: `app/Domain/Finance/Reports/FinanceReportFilters.php`
- Modify: `app/Domain/Finance/Reports/IncomeExpenseReportService.php` (`dailyRows`)
- Modify: `app/Domain/Finance/Reports/FinanceReportExporter.php` (`build`)
- Modify: `resources/views/pages/finance/⚡reports.blade.php`
- Test: `tests/Unit/Finance/FinanceReportFiltersTest.php`, `tests/Feature/Finance/IncomeExpenseReportTest.php`, `tests/Feature/Finance/FinanceReportsScreenTest.php`, `tests/Feature/Finance/FinanceReportExportTest.php`

**Interfaces:**
- Produces: `FinanceReportFilters::__construct(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null, ?int $accountId = null, ?ObligationDirection $direction = null, int $days = 90, ?int $categoryId = null)` — `categoryId` **son** parametre. `fromArray` `'category_id'` anahtarını `parseId` ile okur; `toArray` `'category_id' => ?int` döndürür; `describe()` kategori varsa `'Kategori: <ad>'` parçasını hesap parçasından sonra ekler (`FinanceCategory::find($id)?->name ?? '—'`).
- Ekran: `#[Url] public string $category = ''`.

- [ ] **Step 1: Süzgeç sınıfı için başarısız testleri yaz** (`tests/Unit/Finance/FinanceReportFiltersTest.php` sonuna)

```php
    public function test_category_id_round_trips_and_rejects_garbage(): void
    {
        $from = CarbonImmutable::parse('2026-10-01');
        $to = CarbonImmutable::parse('2026-10-31');

        $filters = FinanceReportFilters::fromArray(['category_id' => '7'], $from, $to);
        $this->assertSame(7, $filters->categoryId);
        $this->assertSame(7, $filters->toArray()['category_id']);
        $this->assertSame(7, FinanceReportFilters::fromArray($filters->toArray(), $from, $to)->categoryId);

        $this->assertNull(FinanceReportFilters::fromArray(['category_id' => 'abc'], $from, $to)->categoryId);
        $this->assertNull(FinanceReportFilters::fromArray(['category_id' => '-3'], $from, $to)->categoryId);
        $this->assertNull(FinanceReportFilters::fromArray([], $from, $to)->categoryId);
    }
```

`describe()` testi veritabanı ister; `tests/Feature/Finance/IncomeExpenseReportTest.php` içine (Step 3'teki testlerin yanına) ekle:

```php
    public function test_describe_names_the_category(): void
    {
        $this->assertStringContainsString('Kategori: Hasılat', $this->filters(extra: ['category_id' => (string) $this->revenue->id])->describe());
    }
```

- [ ] **Step 2: Çalıştır, başarısız olduğunu gör**

Run: `php artisan test --filter=FinanceReportFiltersTest`
Expected: FAIL — `Undefined property: ...$categoryId`.

- [ ] **Step 3: Rapor servisi için başarısız testleri yaz** (`tests/Feature/Finance/IncomeExpenseReportTest.php`)

`setUp` veri seti (Ekim 2026): Hasılat geliri 500.000; Kira gideri 200.000; Malzeme gideri 100.000 (06.10'da iptal edildi); kasa→banka transferi, komisyon 5.000; Malzeme kategorili borca 150.000 ödeme; kategorisiz alacağa 70.000 tahsilat; −2.000 mutabakat düzeltmesi. `Kira` kategorisini `setUp` içinde bir özelliğe çevir (`private FinanceCategory $rent;`, `$rent` yerel değişkeni yerine `$this->rent`). Dosyadaki `filters(string $from = '2026-10-01', string $to = '2026-10-31', array $extra = [])` yardımcısını kullan.

```php
    public function test_category_filter_limits_report_to_that_category(): void
    {
        $report = $this->reports->report($this->filters(extra: ['category_id' => $this->rent->id]));

        $this->assertSame([], $report['income']);
        $this->assertSame(0, $report['income_total']);
        $this->assertSame([['category' => 'Kira', 'total' => 200_000]], $report['expense']);
        $this->assertSame(200_000, $report['expense_total']);
        $this->assertSame([['month' => 'Ekim 2026', 'income' => 0, 'expense' => 200_000, 'net' => -200_000]], $report['months']);
    }

    public function test_category_filter_covers_payments_and_reversals(): void
    {
        // Malzeme: 100.000 gider + onun iptali (net 0) ve yükümlülük kategorisiyle yazılan 150.000 ödeme.
        $material = FinanceCategory::where('name', 'Malzeme')->firstOrFail();

        $report = $this->reports->report($this->filters(extra: ['category_id' => $material->id]));

        $this->assertSame([['category' => 'Malzeme', 'total' => 150_000]], $report['expense']);
        $this->assertSame(0, $report['income_total']);
    }

    public function test_category_filter_on_income_category_shows_only_income(): void
    {
        $report = $this->reports->report($this->filters(extra: ['category_id' => $this->revenue->id]));

        $this->assertSame([['category' => 'Hasılat', 'total' => 500_000]], $report['income']);
        $this->assertSame([], $report['expense']);
        $this->assertSame(500_000, $report['net']);
    }
```

- [ ] **Step 4: Çalıştır, başarısız olduğunu gör**

Run: `php artisan test --filter=IncomeExpenseReportTest`
Expected: FAIL (süzgeç uygulanmadığı için tüm kategoriler döner).

- [ ] **Step 5: Süzgeç sınıfını uygula** (`FinanceReportFilters.php`)

Yapıcıya son parametre:

```php
        public int $days = 90,
        public ?int $categoryId = null,
    ) {}
```

`fromArray` dönüşüne: `categoryId: self::parseId($input['category_id'] ?? null),`
`toArray` dönüşüne (ve PHPDoc dönüş tipine): `'category_id' => $this->categoryId,`
`describe()` içinde hesap bloğundan sonra:

```php
        if ($this->categoryId !== null) {
            $parts[] = 'Kategori: '.(FinanceCategory::find($this->categoryId)?->name ?? '—');
        }
```

(`use App\Domain\Finance\Models\FinanceCategory;` ekle.)

- [ ] **Step 6: Servise süzgeci ekle** (`IncomeExpenseReportService::dailyRows`, hesap süzgecinin hemen altına)

```php
            ->when($filters->categoryId !== null, fn ($q) => $q->where('finance_entries.category_id', $filters->categoryId))
```

- [ ] **Step 7: Rapor testlerini çalıştır**

Run: `php artisan test --filter='IncomeExpenseReportTest|FinanceReportFiltersTest'`
Expected: PASS

- [ ] **Step 8: Ekran ve dışa aktarım için başarısız testleri yaz**

`tests/Feature/Finance/FinanceReportsScreenTest.php` — mevcut testlerin kullandığı kurulum yardımcılarını kullan (dosyanın üstündeki `setUp` / yardımcıları oku):

```php
    public function test_category_filter_narrows_income_expense_and_is_cleared_on_tab_change(): void
    {
        // setUp'taki gelir ve gider kategorilerinden birine ait hareket var olduğunu varsay; yoksa burada yaz.
        $rent = FinanceCategory::where('name', 'Kira')->firstOrFail();

        $component = Livewire::test('pages::finance.reports')
            ->set('category', (string) $rent->id)
            ->assertSet('category', (string) $rent->id);
        $this->assertSame([], $component->viewData('income')['income']);

        $component->call('selectReport', 'cash_flow')->assertSet('category', '');
    }

    public function test_foreign_or_unknown_category_is_ignored(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        $foreign = FinanceCategory::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Yabancı', 'direction' => CategoryDirection::Income]);

        Livewire::test('pages::finance.reports')
            ->set('category', (string) $foreign->id)
            ->assertSet('category', '');
        Livewire::withQueryParams(['category' => '99999'])->test('pages::finance.reports')->assertSet('category', '');
    }

    public function test_category_select_is_shown_only_on_income_expense(): void
    {
        Livewire::test('pages::finance.reports')->assertSee('Tüm kategoriler');
        Livewire::test('pages::finance.reports')->call('selectReport', 'aging')->assertDontSee('Tüm kategoriler');
    }
```

(`FinanceCategory::withoutGlobalScopes()->create([...])` yerine dosyada yabancı organizasyon kaydı için zaten kullanılan bir desen varsa — örn. `actingAs` ile diğer organizasyona geçip oluşturmak — onu kullan.)

`tests/Feature/Finance/FinanceReportExportTest.php` — mevcut dışa aktarım testlerinin desenini izle:

```php
    public function test_category_filter_reaches_income_expense_export_but_not_cash_flow(): void
    {
        // Gelir-Gider dökümü: yalnızca seçilen kategori satırı, açıklamada "Kategori: <ad>".
        // Nakit Akışı dökümü: aynı parametrelerle istenince açıklamada "Kategori:" geçmez.
    }
```

Bu testi dosyadaki mevcut "şube süzgeci nakit akışına taşınmaz" testinin (`grep -n "branch" tests/Feature/Finance/FinanceReportExportTest.php`) birebir karşılığı olarak kategoriyle yaz; boş gövde bırakma.

- [ ] **Step 9: Çalıştır, başarısız olduğunu gör**

Run: `php artisan test --filter='FinanceReportsScreenTest|FinanceReportExportTest'`
Expected: FAIL

- [ ] **Step 10: Ekranı uygula** (`⚡reports.blade.php`)

1. `use App\Domain\Finance\Models\FinanceCategory;`
2. Özellik: `#[Url] public string $category = '';`
3. `selectReport()` içinde `$this->branch = '';` satırının altına `$this->category = '';`
4. `filters()` içinde `fromArray` girdisine `'category_id' => $this->category,` ekle; şube satırının altına:

```php
        // Kategori de yalnızca Gelir-Gider süzgecidir; kapsamlı sorguda bulunmayan kimlik sessizce düşer.
        $categoryId = $this->report === 'income_expense' && $filters->categoryId !== null && FinanceCategory::whereKey($filters->categoryId)->exists() ? $filters->categoryId : null;
```

   `new FinanceReportFilters(...)` çağrısına son argüman olarak `$categoryId` ver ve `$this->category = (string) $categoryId;` satırını `$this->account = ...` satırının altına ekle.
5. `with()` dönüşüne: `'categories' => FinanceCategory::orderBy('direction')->orderBy('name')->get(['id', 'name', 'direction']),`
6. Şube `<select>` bloğunun hemen altına (aynı `@if ($report === 'income_expense')` içinde):

```blade
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Kategori</label>
                    <select wire:model.live="category" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
                        <option value="">Tüm kategoriler</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->direction->label() }})</option>
                        @endforeach
                    </select>
                </div>
```

   (`CategoryDirection` enum'unda `label()` yoksa `$c->direction === \App\Domain\Finance\Support\CategoryDirection::Income ? 'Gelir' : 'Gider'` kullan.)

- [ ] **Step 11: Dışa aktarımı uygula** (`FinanceReportExporter::build`)

`new FinanceReportFilters(...)` çağrısına son argüman: `$type === ReportType::FinanceIncomeExpense ? $filters->categoryId : null,` ve üstteki yorumu "şube ve kategori Gelir-Gider'e, yön Yaşlandırma'ya aittir" diye güncelle.

- [ ] **Step 12: Tüm testleri ve Pint'i çalıştır**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, Pint temiz (gerekirse `vendor/bin/pint` çalıştırıp yeniden dene).

- [ ] **Step 13: Commit**

```bash
git add -A app/Domain/Finance/Reports resources/views/pages/finance tests
git commit -m "feat: Gelir-Gider raporuna kategori süzgeci — Aşama 37 (1/5)"
```

---

### Task 2: Gelir-Gider dökümünde aylık kırılım

Ekranda aylık kırılım var, Excel/PDF dökümünde yok. Döküm tablosu beş sütuna çıkar; önce kategori satırları, bir boş satır, sonra ay satırları gelir; en altta tek bir toplam satırı (`totals`) iki bölümün de toplamıdır (ikisi aynı toplamı verir, çünkü aylar kategorilerin dönem içi dağılımıdır).

Yeni tablo biçimi (tutarlar kuruş `int`; boş hücre `''`):

| Tür | Kategori / Ay | Gelir | Gider | Net |
|---|---|---|---|---|
| Gelir | Hasılat | 500000 | '' | '' |
| Gider | Kira | '' | 200000 | '' |
| '' | '' | '' | '' | '' |
| Ay | Ekim 2026 | 570000 | 355000 | 215000 |
| **totals:** Toplam | '' | income_total | expense_total | net |

**Files:**
- Modify: `app/Domain/Finance/Reports/IncomeExpenseReportService.php` (`table`)
- Test: `tests/Feature/Finance/IncomeExpenseReportTest.php` (mevcut `test_table_lists_income_then_expense_with_net_total` yeniden yazılır), `tests/Feature/Finance/FinanceReportExportTest.php` (başlık satırı beklentisi)

**Interfaces:**
- Consumes: `report()` dönüşündeki `income`, `expense`, `months` (`month` alanı `RecurringFrequency::Monthly->periodLabel()` ile "Ekim 2026" biçiminde).
- Produces: `table(FinanceReportFilters $filters): array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}` — `headings === ['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net']`.

- [ ] **Step 1: Mevcut tablo testini yeni biçime göre yeniden yaz**

`test_table_lists_income_then_expense_with_net_total` testini aşağıdakiyle değiştir. Beklentiler `setUp` veri setinden: 2 gelir satırı (Hasılat 500.000, Kategorisiz 70.000), 3 gider satırı (Kira 200.000, Malzeme 150.000, Banka/POS Komisyonu 5.000), tek ay Ekim 2026: 570.000 / 355.000 / 215.000.

```php
    public function test_table_lists_categories_then_months_with_one_total_row(): void
    {
        $table = $this->reports->table($this->filters());

        $this->assertSame(['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net'], $table['headings']);
        $this->assertSame(['Gelir', 'Hasılat', 500_000, '', ''], $table['rows'][0]);
        $this->assertSame(['Gider', 'Kira', '', 200_000, ''], $table['rows'][2]);

        $this->assertSame(['Gider', 'Banka/POS Komisyonu', '', 5_000, ''], $table['rows'][4]);
        $this->assertSame(['', '', '', '', ''], $table['rows'][5]);
        $this->assertSame(['Ay', 'Ekim 2026', 570_000, 355_000, 215_000], $table['rows'][6]);
        $this->assertCount(7, $table['rows']);
        $this->assertSame(['Toplam', '', 570_000, 355_000, 215_000], $table['totals']);
    }

    public function test_table_lists_every_month_of_a_multi_month_period(): void
    {
        $table = $this->reports->table($this->filters('2026-09-01'));

        $this->assertSame(['Ay', 'Eylül 2026', 0, 0, 0], $table['rows'][6]);
        $this->assertSame(['Ay', 'Ekim 2026', 570_000, 355_000, 215_000], $table['rows'][7]);
    }
```

Boş aylar da 0 ile listelenir (ekrandaki gibi).

- [ ] **Step 2: Çalıştır, başarısız olduğunu gör**

Run: `php artisan test --filter=test_table_lists_categories_then_months_with_one_total_row`
Expected: FAIL (başlık `['Tür', 'Kategori', 'Tutar']`).

- [ ] **Step 3: `table()`'ı yeniden yaz**

```php
    /**
     * Dışa aktarım için düz tablo; tutarlar kuruş. Önce kategori satırları, bir boş
     * satır, sonra dönemin her ayı; tek toplam satırı iki bölümün de toplamıdır.
     *
     * @return array{headings: list<string>, rows: list<list<string|int>>, totals: list<string|int>}
     */
    public function table(FinanceReportFilters $filters): array
    {
        $report = $this->report($filters);
        $rows = [];

        foreach ($report['income'] as $line) {
            $rows[] = ['Gelir', $line['category'], $line['total'], '', ''];
        }

        foreach ($report['expense'] as $line) {
            $rows[] = ['Gider', $line['category'], '', $line['total'], ''];
        }

        $rows[] = ['', '', '', '', ''];

        foreach ($report['months'] as $month) {
            $rows[] = ['Ay', $month['month'], $month['income'], $month['expense'], $month['net']];
        }

        return [
            'headings' => ['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net'],
            'rows' => $rows,
            'totals' => ['Toplam', '', $report['income_total'], $report['expense_total'], $report['net']],
        ];
    }
```

- [ ] **Step 4: Dışa aktarım testlerini güncelle**

Run: `php artisan test --filter=FinanceReportExportTest`
`['Tür', 'Kategori', 'Tutar']` başlığını bekleyen satırı (`grep -n "'Tür', 'Kategori'" tests/Feature/Finance/FinanceReportExportTest.php`) `['Tür', 'Kategori / Ay', 'Gelir', 'Gider', 'Net']` yap. Aynı dosyaya, mevcut Excel okuma desenini kullanan bir test ekle: üretilen Excel'de "Ay" satırı ilgili ay etiketini ve lira cinsinden **sayı** hücrelerini taşır (örn. `500000` kuruş → `5000` sayı; `is_numeric` / `assertIsFloat|assertIsInt`), ve PDF dökümünde ay etiketi metni geçer.

- [ ] **Step 5: Tüm testleri ve Pint'i çalıştır**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS. (`FinanceReportExporter::present` boş `''` hücreleri olduğu gibi bırakır; ek değişiklik gerekmez. Kırılırsa bunu düzelt, `present`'in sayı/metin ayrımını bozma.)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Finance/Reports/IncomeExpenseReportService.php tests
git commit -m "feat: Gelir-Gider dökümüne aylık kırılım — Aşama 37 (2/5)"
```

---

### Task 3: Küçük çubuk görünürlüğü ve test boşlukları

Aşama 36 incelemesinde kalan üç küçük madde:
1. Çubuk grafikte en büyük değere göre çok küçük (ör. 1 TL'ye karşı 100.000 TL) bir tutar 0 piksel yükseklik alıp görünmez oluyor; "bu ay gider var mı" sorusu yanlış cevaplanıyor. Sıfırdan büyük her değer **en az 2 piksel** yükseklik alır; sıfır değer 0 kalır; en büyük değer yine tam yüksekliktir.
2. Form hata eşlemelerinin ekran testi eksik: Hareketler'de pasif cari hatası `partyId` alanına, aynı hesaba transfer hatası `toAccountId` alanına; hesap detayında gelecek tarihli ekstre hatası `statementDate` alanına düşmeli.
3. Gelir-Gider'de aynı adlı gelir ve gider kategorisi (`Diğer` / gelir ve `Diğer` / gider — `(organization_id, direction, name)` tekil olduğu için ikisi birlikte var olabilir) kendi kovalarında ayrı kalmalı, birbirine karışmamalı.

**Files:**
- Modify: `app/Domain/Finance/Support/FinanceChart.php` (`bars`)
- Test: `tests/Unit/Finance/FinanceChartTest.php`, `tests/Feature/Finance/FinanceEntriesScreenTest.php`, `tests/Feature/Finance/FinanceAccountScreenTest.php`, `tests/Feature/Finance/IncomeExpenseReportTest.php`

**Interfaces:**
- Produces: `FinanceChart::bars()` imzası değişmez; yalnızca `height`/`y` hesabı değişir.

- [ ] **Step 1: Grafik için başarısız test** (`tests/Unit/Finance/FinanceChartTest.php`)

```php
    public function test_tiny_non_zero_bar_stays_visible(): void
    {
        $chart = FinanceChart::bars([['in' => 10_000_000, 'out' => 100]], 720, 200, 8);

        [$in, $out] = $chart['bars'];
        $this->assertSame(184.0, $in['height']);
        $this->assertSame(2.0, $out['height']);
        $this->assertSame(round($chart['zero_y'] - 2.0, 2), $out['y']);
    }

    public function test_zero_bar_keeps_zero_height(): void
    {
        $chart = FinanceChart::bars([['in' => 5_000, 'out' => 0]]);

        $this->assertSame(0.0, $chart['bars'][1]['height']);
    }
```

- [ ] **Step 2: Çalıştır, başarısız olduğunu gör**

Run: `php artisan test --filter=FinanceChartTest`
Expected: FAIL — `0.0` ≠ `2.0`.

- [ ] **Step 3: Uygula** (`FinanceChart::bars`, `$h` satırı)

Sınıfa sabit: `private const MIN_BAR_HEIGHT = 2.0; // sıfırdan büyük tutar her zaman görünür`

```php
                $h = $max === 0 || $g[$kind] === 0 ? 0.0 : max(self::MIN_BAR_HEIGHT, $g[$kind] / $max * $plotH);
```

- [ ] **Step 4: Çalıştır**

Run: `php artisan test --filter='FinanceChartTest|FinanceChartComponentTest'`
Expected: PASS

- [ ] **Step 5: Hata eşleme ekran testlerini yaz**

Önce `grep -n "assertHasErrors" tests/Feature/Finance/FinanceEntriesScreenTest.php tests/Feature/Finance/FinanceAccountScreenTest.php` ile hangi alanların zaten test edildiğine bak; zaten var olanı ikinci kez yazma. Eksik olanlar için, dosyaların mevcut kurulum yardımcılarıyla:

`FinanceEntriesScreenTest`:

```php
    public function test_inactive_party_error_lands_on_party_field(): void
    {
        // Etkin bir cari oluştur, pasife al (FinancePartyService::deactivate veya status => 'inactive').
        // openForm('income'); accountId, amount '100,00', date bugün, categoryId (gelir kategorisi), partyId (pasif cari) set et; call('save').
        // ->assertHasErrors(['partyId'])->assertHasNoErrors(['amount']);
    }

    public function test_same_account_transfer_error_lands_on_target_account_field(): void
    {
        // openForm('transfer'); accountId ve toAccountId aynı hesap; amount '100,00', date bugün; call('save').
        // Ekran doğrulaması `different:accountId` kuralı nedeniyle toAccountId'ye düşer: ->assertHasErrors(['toAccountId']).
    }
```

`FinanceAccountScreenTest`:

```php
    public function test_future_statement_date_error_lands_on_date_field(): void
    {
        // Hesap detayı; statementDate bugün + 1 gün (Y-m-d), statementBalance '100,00'; call('compare').
        // ->assertHasErrors(['statementDate'])->assertHasNoErrors(['statementBalance']).
    }
```

Yorumları gerçek koda çevir (yorum satırı bırakma). Ekran bir hatayı beklenen alana düşürmüyorsa bu bir hatadır: testi değiştirme, `FORM_FIELDS` / `RECONCILE_FIELDS` eşlemesini ve ilgili servisin `FinanceRuleException` alan ipucunu düzelt ve raporunda belirt.

- [ ] **Step 6: Aynı adlı kategoriler için test** (`tests/Feature/Finance/IncomeExpenseReportTest.php`)

```php
    public function test_same_name_income_and_expense_categories_stay_in_their_own_buckets(): void
    {
        $otherIncome = FinanceCategory::create(['name' => 'Diğer', 'direction' => CategoryDirection::Income]);
        $otherExpense = FinanceCategory::create(['name' => 'Diğer', 'direction' => CategoryDirection::Expense]);
        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 7_000, $this->day('2026-10-10'), $otherIncome, $this->actor);
        $ledger->expense($this->cash, 3_000, $this->day('2026-10-10'), $otherExpense, $this->actor);

        $report = $this->reports->report($this->filters());

        $this->assertContains(['category' => 'Diğer', 'total' => 7_000], $report['income']);
        $this->assertContains(['category' => 'Diğer', 'total' => 3_000], $report['expense']);
    }
```


- [ ] **Step 7: Tüm testleri ve Pint'i çalıştır**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Finance/Support/FinanceChart.php tests
git commit -m "fix: küçük çubuklar görünür, hata alanı ve kategori testleri — Aşama 37 (3/5)"
```

---

### Task 4: Faz 5 uçtan uca kabul testi

Spec §5: "Bir aylık klinik senaryosu uçtan uca + izolasyon + yetki + salt-okunur — Her adımda tüm bakiye ve raporlar tutarlı."

**Desen:** `tests/Feature/Acceptance/Faz4AcceptanceTest.php`'yi oku ve izle: tek bir `test_faz5_end_to_end_acceptance()` metodu adım metotlarını sırayla çağırır; adım metotları `step1_...` biçiminde adlandırılır; durum `private` özelliklerde tutulur. Kullanıcının yaptığı her işlem **ekrandan** (Livewire `Livewire::test('pages::finance.<sayfa>')` + `set`/`call`) yürütülür; yalnızca zamanlanmış işler `Artisan::call` ile, dışa aktarım ekranın `export` metoduyla tetiklenir. Doğrulamalar servisleri ve ekran çıktısını birlikte kullanır.

**Rakamlar elle hesaplanmıştır; test bunları sabit olarak doğrular.** Bir doğrulama tutmazsa önce senaryo adımını ekranın gerçekte ne yaptığına göre tekrar oku. Rakam yanlışsa nedenini raporuna yaz. Kodda hata varsa düzeltme, `DONE_WITH_CONCERNS` ile raporla. Beklenen değeri testten geçsin diye değiştirme.

**Files:**
- Create: `tests/Feature/Acceptance/Faz5FinanceAcceptanceTest.php`

**Interfaces (kullanılan ekran özellikleri ve metotları — hepsi mevcut):**
- `pages::finance.categories`: `call('addSuggested')`.
- `pages::finance.accounts`: `call('openForm')`, `set('name'|'type'|'iban'|'openingBalance'|'openingDate')`, `call('save')`. `type` değerleri `cash|bank|pos`.
- `pages::finance.parties`: `call('openForm')`, `set('name'|'kind'|'defaultTermDays')`, `call('save')`. `kind` değerleri `customer|supplier|other`.
- `pages::finance.recurrings`: `call('openForm')`, `set('formDirection'|'name'|'amount'|'categoryId'|'partyId'|'frequency'|'startsOn'|'endsOn')`, `call('save')`.
- `pages::finance.entries`: `call('openForm', 'income'|'expense'|'transfer')`, `set('accountId'|'toAccountId'|'amount'|'date'|'categoryId'|'branchId'|'partyId'|'description'|'commission'|'commissionCategoryId')`, `call('save')`; iptal: `call('startReverse', $entryId)`, `set('reverseReason')`, `call('confirmReverse')`.
- `pages::finance.obligations`: `call('openForm', null, 'payable'|'receivable')`, `set('formDirection'|'amount'|'dueOn'|'partyId'|'categoryId'|'description'|'documentNo'|'branchId')`, `call('save')`; ödeme: `call('startPay', $id)`, `set('payAccountId'|'payAmount'|'payDate')`, `call('confirmPay')`.
- `pages::finance.account` (`['account' => $account]`): `set('statementDate'|'statementBalance'|'note')`, `call('compare')`, `call('reconcile', true)`.
- `pages::finance.reports`: `set('report'...)` yerine `call('selectReport', ...)`; `set('from'|'to'|'branch'|'account'|'category'|'days'|'direction')`; `viewData('income'|'cash'|'projection'|'aging')`; `call('export', 'xlsx'|'pdf')`.
- `pages::finance.overview`: `assertSee(...)`.
- Komutlar: `finance:generate-recurring`, `finance:scan-due`.
- Servisler (yalnızca doğrulama için): `FinanceBalanceService::balance/total/remaining/partyBalances`, `IncomeExpenseReportService::report`, `CashFlowReportService::actual`, `CashFlowProjectionService::project(int $days, ?CarbonImmutable $today = null, ?int $start = null)`, `AgingReportService::report(ObligationDirection)`, `AccountStatementService::statement(FinanceAccount, CarbonImmutable $from, CarbonImmutable $to)`.

Bir ekran metodu veya özellik adı yukarıdakinden farklıysa sayfayı (`resources/views/pages/finance/⚡<sayfa>.blade.php`) oku ve gerçeğini kullan. Livewire alan adları değişmez kabul edilir.

**Saat:** `private function at(string $datetime): void` — `CarbonImmutable::setTestNow(CarbonImmutable::parse($datetime, 'Europe/Istanbul'))` ve `Carbon::setTestNow(Carbon::parse($datetime, 'Europe/Istanbul'))`. `tearDown`'da ikisi de sıfırlanır (Aşama 36 kabul testindeki gibi). Her adım kendi saatini kurar (saat 10:00).

**Tutarlılık denetimi (her adımın sonunda çağrılır):** `private function assertBooksAgree(int $expectedTotal): void`

1. `FinanceBalanceService::total()` = `$expectedTotal`.
2. Her hesap için `AccountStatementService::statement($account, açılış tarihi, bugün)['closing']` = `FinanceBalanceService::balance($account)`. Hesaplar arası toplam = `$expectedTotal`.
3. `CashFlowReportService::actual(FinanceReportFilters(2026-10-01 → bugün))` son satırın `closing` değeri = `$expectedTotal`.
4. Defter kimliği: `$expectedTotal − Σ açılış bakiyeleri` = `IncomeExpenseReportService::report(2026-10-01 → bugün)['net']` + defterdeki mutabakat düzeltmelerinin imzalı toplamı. Düzeltme toplamını `FinanceEntry` üzerinden `type = adjustment` olan kayıtların ve bu kayıtları iptal eden `reversal` kayıtlarının `direction × amount` toplamı olarak hesapla. Transfer ayakları birbirini sıfırlar; komisyon gider olarak raporda yer alır.
5. Her açık ya da kısmi yükümlülük için durum, `FinanceBalanceService::remaining()` ile tutarlı olmalı: kalan = tutar → `open`, 0 < kalan < tutar → `partial`, kalan = 0 → `paid`.
6. `Livewire::test('pages::finance.overview')->assertSee(Money::format($expectedTotal))`.

**Senaryo** (klinik: "Gülüş Ağız ve Diş Sağlığı", plan `professional`; şubeler "Merkez" ve "Kuzey"; Admin kullanıcı ekranlardan çalışır):

| Adım | Saat | Ekrandan yapılan | Beklenen (elle hesaplandı) |
|---|---|---|---|
| 1 Kurulum | 01.10.2026 | Kategoriler: "Önerilenleri ekle". Hesaplar: Kasa (cash, açılış `20.000,00`, 2026-10-01), Banka (bank, `150.000,00`, 2026-10-01), POS (pos, `0`, 2026-10-01). Cariler: "Anadolu Sigorta" (customer, vade 30 gün), "Dental Depo" (supplier, vade 15 gün). Tekrarlayan: borç "Kira", `40.000,00`, kategori Kira, aylık, başlangıç 2026-10-06. `finance:generate-recurring` çalıştırılır. | 10 kategori; tek bir kira yükümlülüğü üretilir: "Kira — Ekim 2026", vadesi 06.10.2026. 06.11.2026, 01.10 + 35 gün = 05.11 ufkunun dışında kalır. Toplam `17_000_000` kuruş. |
| 2 İlk hafta | 02.10 → 10.10 (her işlem kendi gününde) | 02.10 gelir Kasa `12.000,00` Hasılat, şube Merkez. 03.10 gelir POS `30.000,00` Hasılat, şube Kuzey. 05.10 gider Kasa `2.500,00` "Fatura (elektrik/su/internet)", şube Merkez. 06.10 Ödemeler'den kira yükümlülüğüne Banka'dan `40.000,00` ödeme. 07.10 transfer POS → Banka `30.000,00`, komisyon `600,00`, komisyon kategorisi Banka/POS Komisyonu. 08.10: tahsil edilecek kayıt, Anadolu Sigorta, `25.000,00`, Kurum Tahsilatı, vade boş; ödenecek kayıt, Dental Depo, `18.000,00`, Malzeme, vade boş. 09.10 gider Kasa `1.000,00` Malzeme. 10.10 bu gider Hareketler'den "Yanlış kayıt" gerekçesiyle iptal edilir. | Vade boş girilen kayıtlar carinin vade gününe göre dolar: alacak 07.11.2026, borç 23.10.2026. Kira: `paid`. 10.10 bakiyeleri: Kasa `2_950_000`, POS `0`, Banka `13_940_000`; toplam `16_890_000`. Komisyon, transferin hedefi olan Banka'dan gider olarak düşer. Gelir-Gider 01–10.10: gelir `4_200_000`, gider `4_310_000` (Kira 4.000.000 + Fatura 250.000 + Komisyon 60.000). İptal edilen Malzeme satırı listede yer almaz, net `-110_000`. |
| 3 Ay ortası | 20.10 ve 23.10 | 20.10: gelir Kasa `8.000,00` Hasılat (Merkez); Dental Depo borcuna Banka'dan `10.000,00` ödeme. 23.10: aynı borca Banka'dan `8.000,00` ödeme. | 20.10 sonrası borç `partial`, kalan `800_000`. 23.10 sonrası borç `paid`. Kasa `3_750_000`, Banka `12_140_000`, toplam `15_890_000`. Dental Depo cari bakiyesi net `0`. |
| 4 Ay sonu | 31.10 | Banka hesap detayında ekstre tarihi 2026-10-31, ekstre bakiyesi `121.250,00` (kaydedilmemiş 150 TL banka masrafı), "Karşılaştır", sonra "Farkı düzeltme hareketiyle kapat". `finance:generate-recurring` çalıştırılır. | Önizleme: sistem `12_140_000`, fark `-15_000`. Banka `12_125_000`, toplam `15_875_000`. "Kira — Kasım 2026" üretilir (vade 06.11), çünkü 31.10 + 35 gün = 05.12. Gelir-Gider Ekim: gelir `5_000_000`, yalnız Hasılat. Gider `6_110_000`: Kira 4.000.000, Malzeme 1.800.000, Fatura 250.000, Komisyon 60.000. Net `-1_110_000`; düzeltme bu rapora girmez. Şube Merkez: gelir `2_000_000`, gider `250_000`. Şube Kuzey: gelir `3_000_000`. Kategori Kira: gider `4_000_000`, gelir `0`. Nakit Akışı Ekim: net `-1_125_000`, dönem sonu `15_875_000`. 30 günlük projeksiyon başlangıcı `15_875_000`. 06.11'de `11_875_000`, 07.11'de `14_375_000`. Eksiye düşmez. Özet: "Toplam nakit" `158.750,00 ₺`. Bu ay gelir `50.000,00 ₺`, gider `61.100,00 ₺`. 7 gün içinde ödenecek `40.000,00 ₺`, tahsil edilecek `25.000,00 ₺`. |
| 5 Dışa aktarım | 31.10 | Raporlar → Gelir-Gider (01.10–31.10) → `export('xlsx')`. `Storage::fake('local')`; kuyruk testte senkron. | Dışa aktarım `Completed`, dosya var, indirme rotası 200. Excel okunur. "Ay" satırı "Ekim 2026", gelir `50000`, gider `61100`, net `-11100` sayıları. Toplam satırı aynı. Sayılar lira cinsinden. |
| 6 Gecikme | 10.11 | `finance:scan-due` çalıştırılır. Özet ve Yaşlandırma açılır. Sonra Ödemeler'den Anadolu Sigorta alacağı Banka'ya `25.000,00` tahsil edilir. | Tahsilattan önce: Admin'e kira ve sigorta için `FinanceDueNotification` gider, `state = overdue`. Özet'te geciken ödeme `40.000,00 ₺`, geciken tahsilat `25.000,00 ₺`, "(2 kayıt)". Yaşlandırma alacak: Anadolu Sigorta `2_500_000` "1–30 gün" kovasında (kova anahtarını `AgingReportService::BUCKETS`'tan oku). 90 günlük projeksiyon 10.11 noktası `15_875_000 − 4_000_000 + 2_500_000 = 14_375_000`, çünkü gecikenler bugüne yığılır. Tahsilattan sonra: alacak `paid`, Banka `14_625_000`, toplam `18_375_000`. Ekim Gelir-Gider değişmez (gelir `5_000_000`, gider `6_110_000`). Kasım 1–10: gelir `2_500_000`, Kurum Tahsilatı. |
| 7 Yetki | 10.11 | Finans yetkisi olmayan personel. Finans okuma yetkili personel: `Permission` tüm şubeler kapsamında, `can_read` true, `can_write` false. Şube kapsamlı finans yetkisi verilmeye çalışılır. | Yetkisiz personel tüm finans rotalarında 403 alır: `/finans`, hareketler, ödemeler, cariler, cari detay, tekrarlayanlar, hesaplar, hesap detay, raporlar, kategoriler. Okuma yetkili personel Özet'te `183.750,00 ₺` görür. Hareketler'de `openForm('income')` → `save` 403 verir ve hareket sayısı değişmez. Ödemeler'de `confirmPay` yetkisiz. Şube kapsamlı yetkide `StaffService::createStaff` `StaffRuleException` atar ("Finans yetkisi yalnızca "Tüm şubeler" kapsamıyla verilebilir."). |
| 8 Salt-okunur | 10.11 | Organizasyon `OrganizationStatus::ReadOnly` yapılır. Admin tazelenir. | Admin Özet'te `183.750,00 ₺` görür, raporlar açılır. `finance.create` yetkisi yoktur. Hareketler sayfasında "+ Gelir" görünmez. Hareketler'de doğrudan `save` çağrısı 403 verir. `LedgerService::income` doğrudan çağrılınca `ReadOnlyOrganizationException` atar. Defter satır sayısı değişmez. |
| 9 İzolasyon | 10.11 | İkinci organizasyonun Admin'i giriş yapar. | Özet "Önce bir kasa veya banka hesabı tanımla" der, `183.750,00 ₺` görünmez. Birinci kliniğin hesap detay ve cari detay rotaları 404 döner. Birinci kliniğin dışa aktarım indirmesi 403 ya da 404 döner. `FinanceAccount`, `FinanceEntry`, `FinanceObligation`, `FinanceParty`, `FinanceCategory`, `FinanceRecurring` sayıları 0'dır. Raporlar ekranında Gelir-Gider toplamı 0'dır. |

Adım 2'de günler ilerlerken her işlemden önce `at('2026-10-0X 10:00:00')` çağrılır (hareket tarihi `date` alanına aynı gün `Y-m-d` olarak yazılır). Kimlikler (`accountId`, `categoryId` …) ekran üzerinden oluşturulan kayıtların sorgulanmasıyla alınır (ör. `FinanceAccount::where('name', 'Kasa')->value('id')`).

`assertBooksAgree` çağrıları: Adım 1 sonunda `17_000_000`, Adım 2 sonunda `16_890_000`, Adım 3 sonunda `15_890_000`, Adım 4 sonunda `15_875_000`, Adım 6 sonunda `18_375_000`.

Okuma yetkili personel için `Permission::create(['user_id' => …, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value])` (bkz. `tests/Feature/Finance/FinancePermissionTest.php`). Personelin `branch_id`'si Merkez şubesidir.

Excel okuma için mevcut testlerdeki deseni kullan (`grep -rn "IOFactory\|toArray(" tests/Feature/Finance/FinanceReportExportTest.php`).

- [ ] **Step 1: Test iskeletini yaz** — `Faz5FinanceAcceptanceTest` sınıfı, `RefreshDatabase`, `tearDown`, `at()`, `assertBooksAgree()`, `test_faz5_end_to_end_acceptance()` ve dokuz boş olmayan adım metodu (yukarıdaki tablonun her satırı bir metot). Sınıf docblock'u Faz 4'teki gibi senaryoyu ve tutarlılık kuralını Türkçe özetler.

- [ ] **Step 2: Adımları sırayla doldur ve her adımdan sonra çalıştır**

Run: `php artisan test --filter=Faz5FinanceAcceptanceTest`
Her yeni adımla yeşil kalmalı. Kırmızıysa yukarıdaki "rakamlar elle hesaplanmıştır" kuralını uygula.

- [ ] **Step 3: Tüm testler ve Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 4: Commit** (uzun iş: Adım 1–4 bitince ara commit at, kalanlar ikinci commit)

```bash
git add tests/Feature/Acceptance/Faz5FinanceAcceptanceTest.php
git commit -m "test: Faz 5 finans uçtan uca kabul testi — Aşama 37 (4/5)"
```

---

### Task 5: Belge

**Files:**
- Modify: `README.md` (Finans satırı)

- [ ] **Step 1: README'yi güncelle**

`README.md` içindeki `- **Finans (Faz 5):**` maddesine Gelir-Gider raporunun kategori süzgecini ve Excel/PDF dökümündeki aylık kırılımı ekle. Maddenin sonuna "Faz 5 uçtan uca kabul testiyle tamamlandı (Aşama 37)" cümlesini ekle. Maddenin mevcut üslubunu ve satır kırma düzenini koru.

- [ ] **Step 2: Doğrula ve commit**

Run: `php artisan test && vendor/bin/pint --test && npm run build`
Expected: PASS, derleme hatasız.

```bash
git add README.md
git commit -m "docs: Faz 5 tamamlandı — README — Aşama 37 (5/5)"
```

---

## Plan dışı, kontrolcünün işi

- Görsel kontrol: Raporlar ekranı, kategori süzgeciyle birlikte 1280 ve 500 piksel genişlikte açılır ve küçük çubuk görüntülenir. Yardımcı: `.superpowers/tools/finance-screenshot.php`.
- NotebookLM: `asama-37-rapor.md`. Spec §8'e göre Faz 5 tamamlandığında `veri-modeli.md` ve `terimler-sozlugu.md` de güncellenir.
- Bilinçli olarak bu aşamaya alınmadı: Özet'te açık kayıtların iki kez yüklenmesi. Organizasyon başına birkaç yüz açık kayıtta ölçülebilir bir maliyeti yok. Düzeltmek, projeksiyon servisine önceden yüklenmiş koleksiyon geçirmeyi gerektirir ve bu iki servisi birbirine bağlar. Bilinen eksiklerde kalır.
