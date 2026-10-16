# Toplu Veri Aktarımı (Aşama 38) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kliniğin ürün kartlarını ve açılış stoğunu Excel/CSV şablonlarıyla, önizlemeli ve hepsi-ya-hiçbiri olarak içeri alabilmesi.

**Architecture:** Yeni `app/Domain/Import` alanı: dosya okuyucu (`SpreadsheetReader`) + değer ayrıştırıcı (`ImportValue`) + aynı arayüzü uygulayan iki importer (`ProductImporter`, `OpeningStockImporter`). Importer'lar önce tüm satırları yazmadan doğrular (`validate`), onayda tek transaction içinde var olan servislerle yazar (`ProductService`, `CategoryService`, `SupplierService`, `StockMovementService::in`). Tek Livewire sayfası (`pages::import.index`) iki sekmeyle iki importer'ı aynı şekilde sürer.

**Tech Stack:** Laravel 12, Livewire 4 (tek dosyalı `⚡` sayfalar), PhpSpreadsheet 5.10 (okuma), maatwebsite/excel (şablon indirme, var olan `TableExport`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-16-toplu-veri-aktarimi-design.md`

## Global Constraints

- Arayüz metinleri, hata mesajları ve yorumlar Türkçe; kod adları İngilizce (mevcut stil).
- Hata varsa hiçbir satır yazılmaz; yazma tek `DB::transaction` içinde.
- Satır sınırı 2.000; dosya `xlsx,csv`, en fazla 5 MB (5120 KB).
- Ürün kodu içe aktarmada zorunlu ve organizasyonda (büyük/küçük harf ve baş/son boşluk yok sayılarak) tekil; var olan kod = hata.
- Stok yazımı yalnızca `StockMovementService::in`, gerekçe metni tam olarak `Açılış stoğu`.
- Depo çözümü: `Warehouse::operational()->inBranches($actor->accessibleBranchIds(Module::StockMovement))`.
- Hücre değeri hiçbir zaman formül olarak hesaplanmaz (`getValue()`, `getCalculatedValue()` değil).
- Commit mesajları: `feat|fix|test|docs: ... — Aşama 38 (n/7)`; sonuna `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Testler: `php artisan test --compact` (tam paket) veya `php artisan test --compact --filter=SınıfAdı`.

---

## Dosya haritası

| Dosya | Görev |
|---|---|
| `app/Domain/Import/Support/ImportValue.php` | Hücre metnini sayı/tam sayı/E-H/tarih/karşılaştırma anahtarına çevirir |
| `app/Domain/Import/Support/SpreadsheetReader.php` | xlsx/csv → `[satırNo => [başlık => metin]]` |
| `app/Domain/Import/Exceptions/ImportFileException.php` | Dosya düzeyindeki hata (eksik sütun, boş dosya, satır sınırı, okunamayan dosya) |
| `app/Domain/Import/Support/ImportRow.php` | Bir satırın değerleri ve hataları |
| `app/Domain/Import/Support/ImportResult.php` | Doğrulama sonucu ve özet |
| `app/Domain/Import/Contracts/Importer.php` | İki importer'ın ortak arayüzü |
| `app/Domain/Import/Exports/ImportTemplateExport.php` | İki sayfalı şablon (veri + Açıklama) |
| `app/Domain/Import/Importers/ProductImporter.php` | Ürün şablonu |
| `app/Domain/Import/Importers/OpeningStockImporter.php` | Açılış stoğu şablonu |
| `app/Domain/Catalog/Support/ProductRules.php` | Ürün ekranı ve importer'ın ortak doğrulama kuralları |
| `resources/views/pages/import/⚡index.blade.php` | Veri Aktarımı sayfası |
| `app/Providers/AuthServiceProvider.php` | `data_import.create` kapısı |
| `routes/web.php`, `resources/views/layouts/authenticated.blade.php` | Rota ve menü |
| `tests/Feature/Import/*` | Testler |

---

### Task 1: Değer ayrıştırıcı ve dosya okuyucu

**Files:**
- Create: `app/Domain/Import/Support/ImportValue.php`, `app/Domain/Import/Support/SpreadsheetReader.php`, `app/Domain/Import/Exceptions/ImportFileException.php`
- Create: `tests/Feature/Import/BuildsSpreadsheets.php`
- Test: `tests/Unit/Import/ImportValueTest.php`, `tests/Feature/Import/SpreadsheetReaderTest.php`

**Interfaces:**
- Produces:
  - `ImportValue::number(string $value): string` — `1.250,50`→`1250.50`, `12,5`→`12.5`, `1250.50`→`1250.50`, ayrıştırılamazsa girdiyi aynen döndürür (doğrulama `numeric` kuralıyla yakalar), boş → `''`.
  - `ImportValue::boolean(string $value): ?bool` — boş/`H`/`Hayır`/`0` → false; `E`/`Evet`/`1` → true; diğer → null.
  - `ImportValue::date(string $value): ?string` — `GG.AA.YYYY`, `YYYY-AA-GG`, Excel seri sayısı → `Y-m-d`; boş → `''`... (boş için `''` döner, geçersiz için null).
  - `ImportValue::key(string $value): string` — trim + `mb_strtolower` (karşılaştırma anahtarı).
  - `SpreadsheetReader::MAX_ROWS = 2000`
  - `SpreadsheetReader::read(string $path, string $extension, array $headings): array<int, array<string, string>>` — anahtar dosyadaki satır no (başlık satırı 1, veri 2'den başlar).
  - `ImportFileException extends RuntimeException`
  - Test yardımcı trait `BuildsSpreadsheets::spreadsheet(array $rows, string $extension = 'xlsx'): string` (ilk satır başlıklar; geçici dosya yolu döner).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Import/ImportValueTest.php`:

```php
<?php

namespace Tests\Unit\Import;

use App\Domain\Import\Support\ImportValue;
use PHPUnit\Framework\TestCase;

class ImportValueTest extends TestCase
{
    public function test_number_accepts_turkish_and_dotted_formats(): void
    {
        $this->assertSame('1250.50', ImportValue::number('1.250,50'));
        $this->assertSame('12.5', ImportValue::number('12,5'));
        $this->assertSame('1250.50', ImportValue::number('1250.50'));
        $this->assertSame('40', ImportValue::number(' 40 '));
        $this->assertSame('', ImportValue::number(''));
        $this->assertSame('on iki', ImportValue::number('on iki'));
    }

    public function test_boolean_understands_evet_hayir(): void
    {
        $this->assertTrue(ImportValue::boolean('E'));
        $this->assertTrue(ImportValue::boolean('evet'));
        $this->assertTrue(ImportValue::boolean('1'));
        $this->assertFalse(ImportValue::boolean(''));
        $this->assertFalse(ImportValue::boolean('Hayır'));
        $this->assertFalse(ImportValue::boolean('h'));
        $this->assertNull(ImportValue::boolean('belki'));
    }

    public function test_date_accepts_turkish_iso_and_excel_serial(): void
    {
        $this->assertSame('2027-03-31', ImportValue::date('31.03.2027'));
        $this->assertSame('2027-03-31', ImportValue::date('2027-03-31'));
        $this->assertSame('2027-03-31', ImportValue::date('46477'));
        $this->assertSame('', ImportValue::date(''));
        $this->assertNull(ImportValue::date('31.02.2027'));
        $this->assertNull(ImportValue::date('yarın'));
    }

    public function test_key_ignores_case_and_surrounding_space(): void
    {
        $this->assertSame(ImportValue::key(' ENJ-01 '), ImportValue::key('enj-01'));
    }
}
```

`tests/Feature/Import/BuildsSpreadsheets.php`:

```php
<?php

namespace Tests\Feature\Import;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

trait BuildsSpreadsheets
{
    /**
     * İlk satır başlıklar. Değerler metin olarak yazılır; sayı hücresi gerekiyorsa
     * int/float verilir.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    protected function spreadsheet(array $rows, string $extension = 'xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r + 1]);
                is_string($value)
                    ? $cell->setValueExplicit($value, DataType::TYPE_STRING)
                    : $cell->setValue($value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.'.$extension;
        IOFactory::createWriter($spreadsheet, ucfirst($extension))->save($path);

        return $path;
    }
}
```

`tests/Feature/Import/SpreadsheetReaderTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Exceptions\ImportFileException;
use App\Domain\Import\Support\SpreadsheetReader;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    use BuildsSpreadsheets;

    public function test_reads_rows_keyed_by_heading_and_line_number(): void
    {
        $path = $this->spreadsheet([
            ['Ürün Kodu', 'Miktar', 'Fazla Sütun'],
            ['ENJ-01', 40, 'x'],
            ['', '', ''],
            ['  KMP-02 ', 12.5, ''],
        ]);

        $rows = (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu', 'Miktar']);

        $this->assertSame([
            2 => ['Ürün Kodu' => 'ENJ-01', 'Miktar' => '40'],
            4 => ['Ürün Kodu' => 'KMP-02', 'Miktar' => '12.5'],
        ], $rows);
    }

    public function test_reads_semicolon_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "Ürün Kodu;Miktar\nENJ-01;1.250,50\n");

        $rows = (new SpreadsheetReader)->read($path, 'csv', ['Ürün Kodu', 'Miktar']);

        $this->assertSame([2 => ['Ürün Kodu' => 'ENJ-01', 'Miktar' => '1.250,50']], $rows);
    }

    public function test_formula_text_is_not_evaluated(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu'], ['=1+1']]);

        $rows = (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu']);

        $this->assertSame('=1+1', $rows[2]['Ürün Kodu']);
    }

    public function test_missing_columns_are_rejected(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu'], ['ENJ-01']]);

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Şablondaki şu sütunlar dosyada yok: Miktar');

        (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu', 'Miktar']);
    }

    public function test_file_without_data_rows_is_rejected(): void
    {
        $path = $this->spreadsheet([['Ürün Kodu']]);

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Dosyada veri satırı yok.');

        (new SpreadsheetReader)->read($path, 'xlsx', ['Ürün Kodu']);
    }

    public function test_row_limit_is_enforced(): void
    {
        $rows = [['Ürün Kodu']];
        for ($i = 0; $i <= SpreadsheetReader::MAX_ROWS; $i++) {
            $rows[] = ["K-{$i}"];
        }
        $path = $this->spreadsheet($rows, 'csv');

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('en fazla 2.000 satır');

        (new SpreadsheetReader)->read($path, 'csv', ['Ürün Kodu']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter='ImportValueTest|SpreadsheetReaderTest'`
Expected: FAIL — `Class "App\Domain\Import\Support\ImportValue" not found`.

- [ ] **Step 3: Implement**

`app/Domain/Import/Exceptions/ImportFileException.php`:

```php
<?php

namespace App\Domain\Import\Exceptions;

use RuntimeException;

/**
 * Dosya düzeyinde içe aktarma hatası: okunamayan dosya, eksik sütun, boş
 * dosya veya satır sınırı. Satır hataları bu istisnayla değil ImportRow
 * üzerinden bildirilir.
 */
class ImportFileException extends RuntimeException {}
```

`app/Domain/Import/Support/ImportValue.php`:

```php
<?php

namespace App\Domain\Import\Support;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Excel/CSV hücre metnini uygulamanın beklediği biçime çevirir. Türk
 * kullanıcıların yazdığı biçimler (1.250,50 — 31.03.2027 — Evet/Hayır)
 * ve Excel'in kendi hücre biçimleri (nokta ondalık, tarih seri sayısı)
 * birlikte kabul edilir.
 */
final class ImportValue
{
    /**
     * Sayıyı nokta ondalıklı metne çevirir. Ayrıştırılamazsa girdiyi aynen
     * döndürür; geçersizliği doğrulamadaki `numeric` kuralı bildirir.
     */
    public static function number(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return $value;
    }

    /**
     * E/Evet/1 → true, boş/H/Hayır/0 → false, diğer → null (geçersiz).
     */
    public static function boolean(string $value): ?bool
    {
        return match (self::key($value)) {
            '', 'h', 'hayır', 'hayir', '0' => false,
            'e', 'evet', '1' => true,
            default => null,
        };
    }

    /**
     * GG.AA.YYYY, YYYY-AA-GG veya Excel tarih seri sayısı → Y-m-d.
     * Boş → '' (tarih girilmemiş), geçersiz → null.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{1,6}$/', $value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((int) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * Ad/kod karşılaştırma anahtarı: büyük/küçük harf ve baş/son boşluk yok sayılır.
     */
    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
```

Not: `createFromFormat` geçersiz tarihte `false` döndürmüyor, taşırıyorsa (31.02 → 03.03) format karşılaştırması yakalar. `31.03.2027` için `d.m.Y` biçimi `31.03.2027` verir; tek haneli `1.3.2027` kabul edilmez (şablon açıklamasında `GG.AA.YYYY` yazar).

`app/Domain/Import/Support/SpreadsheetReader.php`:

```php
<?php

namespace App\Domain\Import\Support;

use App\Domain\Import\Exceptions\ImportFileException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Şablon dosyasını başlık adlarına göre satırlara çevirir. Hücreler hiçbir
 * zaman formül olarak hesaplanmaz (getValue): `=` ile başlayan metin düz
 * metin olarak okunur. Yalnızca ilk sayfa okunur; "Açıklama" sayfası
 * yok sayılır.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * @param  array<int, string>  $headings  Şablonun sütun başlıkları; hepsi dosyada olmalı
     * @return array<int, array<string, string>> dosyadaki satır no => [başlık => metin]
     */
    public function read(string $path, string $extension, array $headings): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new Xlsx,
            'csv' => (new Csv)->setInputEncoding(Csv::GUESS_ENCODING)->setFallbackEncoding('CP1254'),
            default => throw new ImportFileException('Yalnızca .xlsx ve .csv dosyaları yüklenebilir.'),
        };

        try {
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (Throwable) {
            throw new ImportFileException('Dosya okunamadı. Şablonu indirip onunla doldurduğunuzdan emin olun.');
        }

        $grid = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $this->text($cell->getValue());
            }
            $grid[$row->getRowIndex()] = $cells;
        }

        $header = array_map('trim', array_shift($grid) ?? []);
        $missing = array_values(array_diff($headings, $header));

        if ($missing !== []) {
            throw new ImportFileException('Şablondaki şu sütunlar dosyada yok: '.implode(', ', $missing).'. Şablonu yeniden indirin.');
        }

        $positions = array_map(fn (string $heading) => array_search($heading, $header, true), $headings);

        $rows = [];
        foreach ($grid as $line => $cells) {
            $values = [];
            foreach ($headings as $i => $heading) {
                $values[$heading] = $cells[$positions[$i]] ?? '';
            }

            if (implode('', $values) === '') {
                continue;
            }

            $rows[$line] = $values;

            if (count($rows) > self::MAX_ROWS) {
                throw new ImportFileException('Bir dosyada en fazla 2.000 satır olabilir. Dosyayı bölerek yükleyin.');
            }
        }

        if ($rows === []) {
            throw new ImportFileException('Dosyada veri satırı yok.');
        }

        return $rows;
    }

    private function text(mixed $value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter='ImportValueTest|SpreadsheetReaderTest'`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Import tests/Unit/Import tests/Feature/Import
git commit -m "feat: içe aktarma için dosya okuyucu ve değer ayrıştırıcı — Aşama 38 (1/7)"
```

---

### Task 2: Ürün doğrulama kurallarını ortaklaştır

**Files:**
- Create: `app/Domain/Catalog/Support/ProductRules.php`
- Modify: `resources/views/pages/catalog/⚡products.blade.php` (save() içindeki `validate([...])` çağrısı)
- Test: mevcut `tests/Feature/Catalog/ProductManagementTest.php` (değişmeden geçmeli)

**Interfaces:**
- Produces:
  - `ProductRules::fields(int $organizationId, ?int $ignoreId = null): array<string, array>` — ürün kartının alan kuralları (uyarı eşiği ve birim dönüşümü hariç).
  - `ProductRules::messages(): array<string, string>`

- [ ] **Step 1: Run existing tests as baseline**

Run: `php artisan test --compact --filter=ProductManagementTest`
Expected: PASS.

- [ ] **Step 2: Create `ProductRules`**

```php
<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Validation\Rule;

/**
 * Ürün kartının alan doğrulama kuralları. Ürün ekranı ve toplu içe aktarma
 * (Aşama 38) aynı kuralları kullanır; kural tek yerde değişir.
 */
final class ProductRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fields(int $organizationId, ?int $ignoreId = null): array
    {
        $unique = fn (string $column) => Rule::unique('products', $column)->where('organization_id', $organizationId)->ignore($ignoreId);

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            // Barkod organizasyon içinde tekil: okutulan barkod tek bir ürüne çözülmeli (Aşama 20).
            'barcode' => ['nullable', 'string', 'max:255', $unique('barcode')],
            'category_id' => ['nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'base_unit' => ['required', 'string', 'max:50'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'gtin' => ['nullable', 'string', 'max:20'],
            'uts_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'cold_chain' => ['boolean'],
            'storage_min_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100'],
            'storage_max_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100', 'gte:storage_min_temp'],
            'is_controlled' => ['boolean'],
            'tracks_serials' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'storage_min_temp.required_if' => 'Soğuk zincir ürününde en düşük saklama sıcaklığı girilmeli.',
            'storage_max_temp.required_if' => 'Soğuk zincir ürününde en yüksek saklama sıcaklığı girilmeli.',
            'storage_max_temp.gte' => 'En yüksek sıcaklık en düşükten küçük olamaz.',
        ];
    }
}
```

- [ ] **Step 3: Use it in the product page**

In `⚡products.blade.php` `save()`: delete the `$unique` closure line and replace the `$this->validate([...], [...])` call with:

```php
        $validated = $this->validate([
            ...ProductRules::fields($organizationId, $this->editingId),
            'alert_mode' => ['nullable', Rule::in(array_column(AlertMode::cases(), 'value'))],
            'alert_quantity_low' => ['nullable', Rule::requiredIf($tracksQuantity), 'numeric', 'min:0'],
            'alert_quantity_critical' => ['nullable', Rule::requiredIf($tracksQuantity), 'numeric', 'min:0', 'lt:alert_quantity_low'],
            'alert_expiry_low_days' => ['nullable', Rule::requiredIf($tracksExpiry), 'integer', 'min:0', 'max:3650'],
            'alert_expiry_critical_days' => ['nullable', Rule::requiredIf($tracksExpiry), 'integer', 'min:0', 'max:3650', 'lt:alert_expiry_low_days'],
            'conversionRules.*.unit' => ['required_with:conversionRules.*.factor', 'nullable', 'string', 'max:50'],
            'conversionRules.*.factor' => ['required_with:conversionRules.*.unit', 'nullable', 'numeric', 'min:0.01'],
        ], [
            ...ProductRules::messages(),
            'alert_quantity_low.required' => 'Miktar eşiği seçildiğinde sarı miktar girilmeli.',
            'alert_quantity_critical.required' => 'Miktar eşiği seçildiğinde kırmızı miktar girilmeli.',
            'alert_quantity_critical.lt' => 'Kırmızı miktar sarı miktardan küçük olmalı.',
            'alert_expiry_low_days.required' => 'SKT eşiği seçildiğinde sarı gün sayısı girilmeli.',
            'alert_expiry_critical_days.required' => 'SKT eşiği seçildiğinde kırmızı gün sayısı girilmeli.',
            'alert_expiry_critical_days.lt' => 'Kırmızı gün sayısı sarı gün sayısından küçük olmalı (daha az gün = daha kritik).',
        ]);
```

Add `use App\Domain\Catalog\Support\ProductRules;` to the imports. Keep `ProductType` import only if still used elsewhere in the file (check with grep; the view uses `ProductType::cases()` for the select, so it stays).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter='ProductManagementTest|BulkAlertThresholdTest|Medical'`
Expected: PASS, same count as baseline.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Catalog/Support/ProductRules.php "resources/views/pages/catalog/⚡products.blade.php"
git commit -m "refactor: ürün doğrulama kuralları ProductRules'a taşındı — Aşama 38 (2/7)"
```

---

### Task 3: Ortak içe aktarma yapısı ve ürün importer'ı

**Files:**
- Create: `app/Domain/Import/Support/ImportRow.php`, `app/Domain/Import/Support/ImportResult.php`, `app/Domain/Import/Contracts/Importer.php`, `app/Domain/Import/Exports/ImportTemplateExport.php`, `app/Domain/Import/Importers/ProductImporter.php`
- Test: `tests/Feature/Import/ProductImporterTest.php`

**Interfaces:**
- Consumes: `ImportValue`, `ProductRules::fields/messages`, `ProductService::create(array): Product`, `CategoryService::create(['name' => ..]): Category`, `SupplierService::create(['name' => ..]): Supplier`, `Gs1::normalizeGtin(?string): ?string`, `TableExport(string $title, array $headings, Collection $rows)`.
- Produces:
  - `ImportRow(int $line, array $raw)`; props `line`, `raw`, `values` (array, importer doldurur), `errors` (array<string>); `fail(string $column, string $message): void`, `isValid(): bool`.
  - `ImportResult(array<int, ImportRow> $rows, array<string, array<int, string>> $creates = [])`; `isValid(): bool`, `rowCount(): int`, `errorCount(): int`, `errorMessages(): array<int, string>` (`"Satır 14 · Ürün Kodu: ..."`), `validRows(): array<int, ImportRow>`.
  - `interface Importer { headings(): array; template(User $actor): ImportTemplateExport; templateFileName(): string; validate(array $rows, User $actor): ImportResult; import(ImportResult $result, User $actor): int; }`
  - `ImportTemplateExport(string $title, array $headings, array $examples, array $notes)` — `WithMultipleSheets`.
  - `ProductImporter` başlıkları (sırasıyla): `Ürün Adı, Ürün Kodu, Kategori, Tedarikçi, Ana Birim, Alış Fiyatı, Min Stok, Max Stok, Barkod, GTIN, ÜTS No, Ruhsat No, Üretici, Saklama Koşulu, Ürün Türü, Soğuk Zincir, Min Sıcaklık, Max Sıcaklık, Kontrollü, Seri Takipli`. `ImportResult::$creates` anahtarları `Kategori` ve `Tedarikçi`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Import/ProductImporterTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImporterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private ProductImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->importer = app(ProductImporter::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [...array_fill_keys($this->importer->headings(), ''), 'Ürün Adı' => 'Enjektör 2ml', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', ...$overrides];
    }

    public function test_valid_rows_are_imported_with_new_categories_and_suppliers(): void
    {
        Category::create(['name' => 'Sarf', 'status' => 'active']);

        $result = $this->importer->validate([
            2 => $this->row(['Kategori' => ' sarf ', 'Tedarikçi' => 'Medikal A.Ş.', 'Alış Fiyatı' => '1.250,50', 'Min Stok' => '5', 'Ürün Türü' => 'İlaç', 'Kontrollü' => 'E']),
            3 => $this->row(['Ürün Adı' => 'Kompozit', 'Ürün Kodu' => 'KMP-01', 'Kategori' => 'Dolgu', 'Tedarikçi' => 'medikal a.ş.']),
        ], $this->admin);

        $this->assertTrue($result->isValid(), implode("\n", $result->errorMessages()));
        $this->assertSame(['Kategori' => ['Dolgu'], 'Tedarikçi' => ['Medikal A.Ş.']], $result->creates);

        $this->assertSame(2, $this->importer->import($result, $this->admin));

        $product = Product::where('code', 'ENJ-01')->sole();
        $this->assertSame('Sarf', $product->category->name);
        $this->assertSame('Medikal A.Ş.', $product->supplier->name);
        $this->assertEquals(1250.50, $product->purchase_price);
        $this->assertSame(5, $product->min_stock);
        $this->assertSame('medicine', $product->product_type->value);
        $this->assertTrue($product->is_controlled);
        $this->assertSame('active', $product->status);
        $this->assertSame(1, Supplier::count());
        $this->assertSame(2, Category::count());
        $this->assertSame($this->organization->id, $product->organization_id);
    }

    public function test_required_fields_and_formats_are_reported_per_row(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Ürün Adı' => '', 'Ürün Kodu' => '', 'Ana Birim' => '']),
            3 => $this->row(['Ürün Kodu' => 'X-1', 'Alış Fiyatı' => 'on lira', 'Min Stok' => '2,5', 'Ürün Türü' => 'Cihaz', 'Soğuk Zincir' => 'belki', 'GTIN' => '123']),
        ], $this->admin);

        $this->assertFalse($result->isValid());
        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Ürün Adı', $messages);
        $this->assertStringContainsString('Satır 2 · Ürün Kodu', $messages);
        $this->assertStringContainsString('Satır 2 · Ana Birim', $messages);
        $this->assertStringContainsString('Satır 3 · Alış Fiyatı', $messages);
        $this->assertStringContainsString('Satır 3 · Min Stok', $messages);
        $this->assertStringContainsString('Satır 3 · Ürün Türü', $messages);
        $this->assertStringContainsString('Satır 3 · Soğuk Zincir', $messages);
        $this->assertStringContainsString('Satır 3 · GTIN', $messages);
    }

    public function test_duplicate_codes_in_file_and_in_system_are_errors(): void
    {
        Product::create(['name' => 'Eski', 'code' => 'ESK-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'A-1']),
            3 => $this->row(['Ürün Kodu' => ' a-1 ']),
            4 => $this->row(['Ürün Kodu' => 'esk-01']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 3 · Ürün Kodu: Dosyada satır 2 ile aynı kod.', $messages);
        $this->assertStringContainsString('Satır 4 · Ürün Kodu: Bu kodla kayıtlı bir ürün zaten var.', $messages);
        $this->assertStringNotContainsString('Satır 2', $messages);
    }

    public function test_another_organizations_code_does_not_conflict(): void
    {
        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        Product::create(['organization_id' => $other->id, 'name' => 'Onların', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->assertTrue($this->importer->validate([2 => $this->row()], $this->admin)->isValid());
    }

    public function test_cold_chain_requires_a_temperature_range(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Soğuk Zincir' => 'E']),
            3 => $this->row(['Ürün Kodu' => 'B-1', 'Soğuk Zincir' => 'Evet', 'Min Sıcaklık' => '8', 'Max Sıcaklık' => '2']),
            4 => $this->row(['Ürün Kodu' => 'C-1', 'Soğuk Zincir' => 'E', 'Min Sıcaklık' => '2', 'Max Sıcaklık' => '8']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Min Sıcaklık', $messages);
        $this->assertStringContainsString('Satır 3 · Max Sıcaklık', $messages);
        $this->assertStringNotContainsString('Satır 4', $messages);
    }

    public function test_duplicate_barcode_and_gtin_in_file_are_errors(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'A', 'Barkod' => '111', 'GTIN' => '08699999999990']),
            3 => $this->row(['Ürün Kodu' => 'B', 'Barkod' => '111', 'GTIN' => '8699999999990']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 3 · Barkod: Dosyada satır 2 ile aynı barkod.', $messages);
        $this->assertStringContainsString('Satır 3 · GTIN: Dosyada satır 2 ile aynı GTIN.', $messages);
    }

    public function test_nothing_is_written_when_any_row_is_invalid(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Kategori' => 'Yeni']),
            3 => $this->row(['Ürün Kodu' => 'B', 'Ana Birim' => '']),
        ], $this->admin);

        $this->expectException(\LogicException::class);

        try {
            $this->importer->import($result, $this->admin);
        } finally {
            $this->assertSame(0, Product::count());
            $this->assertSame(0, Category::count());
        }
    }

    public function test_template_has_all_headings_and_an_example(): void
    {
        $sheets = $this->importer->template($this->admin)->sheets();

        $this->assertSame($this->importer->headings(), $sheets[0]->headings());
        $this->assertCount(1, $sheets[0]->collection());
        $this->assertSame('Açıklama', $sheets[1]->title());
    }
}
```

Not: GTIN `8699999999990`'ın kontrol hanesi doğru olmalı. Step 2'de test bu değerle GTIN hatası verirse `Gs1::checkDigit` ile geçerli bir 13 haneli değer hesaplayıp iki satırda da (14 haneli olanı başına `0` ekleyerek) onu kullan.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProductImporterTest`
Expected: FAIL — `Class "App\Domain\Import\Importers\ProductImporter" not found`.

- [ ] **Step 3: Implement the shared pieces**

`app/Domain/Import/Support/ImportRow.php`:

```php
<?php

namespace App\Domain\Import\Support;

/**
 * Dosyanın bir satırı: ham metin değerleri, importer'ın hazırladığı yazılacak
 * değerler ve satır hataları.
 */
final class ImportRow
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @param  array<string, string>  $raw  başlık => hücre metni
     */
    public function __construct(
        public readonly int $line,
        public readonly array $raw,
    ) {}

    public function fail(string $column, string $message): void
    {
        $this->errors[] = "{$column}: {$message}";
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
```

`app/Domain/Import/Support/ImportResult.php`:

```php
<?php

namespace App\Domain\Import\Support;

/**
 * Bir dosyanın doğrulama sonucu. Tek satır bile hatalıysa dosya içe
 * aktarılmaz (hepsi ya da hiçbiri).
 */
final class ImportResult
{
    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<string, array<int, string>>  $creates  içe aktarmada oluşturulacak kayıtlar (ör. 'Kategori' => ['Dolgu'])
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $creates = [],
    ) {}

    public function isValid(): bool
    {
        return $this->rows !== [] && $this->errorCount() === 0;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** Hatalı satır sayısı. */
    public function errorCount(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row) => ! $row->isValid()));
    }

    /**
     * @return array<int, string>
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->rows as $row) {
            foreach ($row->errors as $error) {
                $messages[] = "Satır {$row->line} · {$error}";
            }
        }

        return $messages;
    }
}
```

`app/Domain/Import/Contracts/Importer.php`:

```php
<?php

namespace App\Domain\Import\Contracts;

use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Models\User;

/**
 * Bir içe aktarma şablonu: sütunları, indirilebilir şablonu, yazmadan
 * doğrulaması ve tek transaction'da yazması.
 */
interface Importer
{
    /** @return array<int, string> şablon sütun başlıkları, sırasıyla */
    public function headings(): array;

    public function template(User $actor): ImportTemplateExport;

    public function templateFileName(): string;

    /**
     * Veritabanına yazmadan tüm satırları doğrular.
     *
     * @param  array<int, array<string, string>>  $rows  SpreadsheetReader çıktısı
     */
    public function validate(array $rows, User $actor): ImportResult;

    /**
     * Geçerli sonucu tek transaction'da yazar; yazılan satır sayısını döndürür.
     * Geçersiz sonuçla çağrılırsa LogicException fırlatır.
     */
    public function import(ImportResult $result, User $actor): int;
}
```

`app/Domain/Import/Exports/ImportTemplateExport.php`:

```php
<?php

namespace App\Domain\Import\Exports;

use App\Domain\Reporting\Exports\TableExport;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * İndirilebilir şablon: ilk sayfa doldurulacak tablo (başlıklar + örnek satır),
 * ikinci sayfa sütun açıklamaları. Okuyucu yalnızca ilk sayfayı okur.
 */
class ImportTemplateExport implements WithMultipleSheets
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string>>  $examples
     * @param  array<int, array{0: string, 1: string}>  $notes  [sütun, açıklama]
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $examples,
        private readonly array $notes,
    ) {}

    /**
     * @return array<int, TableExport>
     */
    public function sheets(): array
    {
        return [
            new TableExport($this->title, $this->headings, collect($this->examples)),
            new TableExport('Açıklama', ['Sütun', 'Açıklama'], collect($this->notes)),
        ];
    }
}
```

- [ ] **Step 4: Implement `ProductImporter`**

`app/Domain/Import/Importers/ProductImporter.php`:

```php
<?php

namespace App\Domain\Import\Importers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Catalog\Support\ProductRules;
use App\Domain\Catalog\Support\ProductType;
use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\ImportRow;
use App\Domain\Import\Support\ImportValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Ürün kartı şablonu (Aşama 38). Yalnızca yeni ürün ekler: kod zorunlu ve
 * organizasyonda tekil; var olan kod hata sayılır, mevcut kart ezilmez.
 * Olmayan kategori ve tedarikçiler adıyla oluşturulur.
 */
class ProductImporter implements Importer
{
    /** Şablon başlığı => ürün alanı (doğrulama mesajlarında başlık adı görünür). */
    private const COLUMNS = [
        'Ürün Adı' => 'name',
        'Ürün Kodu' => 'code',
        'Kategori' => null,
        'Tedarikçi' => null,
        'Ana Birim' => 'base_unit',
        'Alış Fiyatı' => 'purchase_price',
        'Min Stok' => 'min_stock',
        'Max Stok' => 'max_stock',
        'Barkod' => 'barcode',
        'GTIN' => 'gtin',
        'ÜTS No' => 'uts_number',
        'Ruhsat No' => 'license_number',
        'Üretici' => 'manufacturer',
        'Saklama Koşulu' => 'storage_condition',
        'Ürün Türü' => 'product_type',
        'Soğuk Zincir' => 'cold_chain',
        'Min Sıcaklık' => 'storage_min_temp',
        'Max Sıcaklık' => 'storage_max_temp',
        'Kontrollü' => 'is_controlled',
        'Seri Takipli' => 'tracks_serials',
    ];

    private const BOOLEAN_COLUMNS = ['Soğuk Zincir', 'Kontrollü', 'Seri Takipli'];

    public function __construct(
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly SupplierService $suppliers,
    ) {}

    public function headings(): array
    {
        return array_keys(self::COLUMNS);
    }

    public function templateFileName(): string
    {
        return 'urun-sablonu.xlsx';
    }

    public function template(User $actor): ImportTemplateExport
    {
        return new ImportTemplateExport('Ürünler', $this->headings(), [[
            'Enjektör 2ml', 'ENJ-01', 'Sarf Malzeme', 'Dental Medikal A.Ş.', 'Adet', '12,50', '20', '200',
            '8690000000017', '', '', '', '', '', 'Sarf Malzeme', 'H', '', '', 'H', 'H',
        ]], [
            ['Ürün Adı', 'Zorunlu.'],
            ['Ürün Kodu', 'Zorunlu ve tekil. Açılış stoğu dosyası ürünü bu kodla bulur. Sistemde aynı kodla ürün varsa satır hatalı sayılır.'],
            ['Kategori / Tedarikçi', 'Ad yazın. Sistemde yoksa içe aktarmada oluşturulur.'],
            ['Ana Birim', 'Zorunlu (ör. Adet, Kutu). Stok bu birimle tutulur.'],
            ['Alış Fiyatı', 'Boşsa 0. 1.250,50 ya da 1250.50 yazılabilir.'],
            ['Min Stok / Max Stok', 'Tam sayı. Min boşsa 0.'],
            ['Barkod / GTIN', 'İsteğe bağlı; ikisi de tekil olmalı. GTIN 8, 12, 13 veya 14 hane, kontrol hanesi doğru.'],
            ['Ürün Türü', 'Sarf Malzeme, Ekipman veya İlaç. Boşsa Sarf Malzeme.'],
            ['Soğuk Zincir / Kontrollü / Seri Takipli', 'E veya H. Boşsa H.'],
            ['Min Sıcaklık / Max Sıcaklık', 'Soğuk zincir ürününde zorunlu (°C, −100 ile 100 arası).'],
        ]);
    }

    public function validate(array $rows, User $actor): ImportResult
    {
        $rules = ProductRules::fields($actor->organization_id);
        unset($rules['category_id'], $rules['supplier_id']);
        $rules['code'] = ['required', 'string', 'max:255'];
        $attributeNames = array_flip(array_filter(self::COLUMNS));

        $existingCodes = $this->keySet(Product::whereNotNull('code')->pluck('code'));
        $existingGtins = $this->keySet(Product::whereNotNull('gtin')->pluck('gtin'));
        $categories = $this->keySet(Category::pluck('name'));
        $suppliers = $this->keySet(Supplier::pluck('name'));
        $types = collect(ProductType::cases())->mapWithKeys(fn (ProductType $type) => [ImportValue::key($type->label()) => $type->value]);

        $seen = ['code' => [], 'barcode' => [], 'gtin' => []];
        $creates = ['Kategori' => [], 'Tedarikçi' => []];
        $result = [];

        foreach ($rows as $line => $raw) {
            $row = new ImportRow($line, $raw);
            $data = [
                'name' => $raw['Ürün Adı'],
                'code' => $raw['Ürün Kodu'],
                'base_unit' => $raw['Ana Birim'],
                'purchase_price' => ImportValue::number($raw['Alış Fiyatı']) ?: '0',
                'min_stock' => ImportValue::number($raw['Min Stok']) ?: '0',
                'max_stock' => ImportValue::number($raw['Max Stok']) ?: null,
                'barcode' => $raw['Barkod'] ?: null,
                'gtin' => $raw['GTIN'] ?: null,
                'uts_number' => $raw['ÜTS No'] ?: null,
                'license_number' => $raw['Ruhsat No'] ?: null,
                'manufacturer' => $raw['Üretici'] ?: null,
                'storage_condition' => $raw['Saklama Koşulu'] ?: null,
                'product_type' => $raw['Ürün Türü'] === '' ? ProductType::Consumable->value : ($types[ImportValue::key($raw['Ürün Türü'])] ?? $raw['Ürün Türü']),
                'storage_min_temp' => ImportValue::number($raw['Min Sıcaklık']) ?: null,
                'storage_max_temp' => ImportValue::number($raw['Max Sıcaklık']) ?: null,
            ];

            foreach (self::BOOLEAN_COLUMNS as $column) {
                $value = ImportValue::boolean($raw[$column]);

                if ($value === null) {
                    $row->fail($column, 'E veya H yazın.');
                }

                // Geçersiz değer hata olarak kaydedildi; diğer alanların hataları da
                // aynı önizlemede görünsün diye doğrulamaya "hayır" olarak girer.
                $data[self::COLUMNS[$column]] = $value ?? false;
            }

            $validator = Validator::make($data, $rules, [
                ...ProductRules::messages(),
                'product_type.in' => 'Sarf Malzeme, Ekipman veya İlaç yazın.',
            ], $attributeNames);

            foreach ($validator->errors()->messages() as $field => $messages) {
                $row->fail($attributeNames[$field], $messages[0]);
            }

            $this->checkUnique($row, 'Ürün Kodu', 'kod', $data['code'], $seen['code'], $existingCodes, 'Bu kodla kayıtlı bir ürün zaten var.');
            $this->checkUnique($row, 'Barkod', 'barkod', (string) $data['barcode'], $seen['barcode']);

            if ($data['gtin'] !== null) {
                $gtin = Gs1::normalizeGtin($data['gtin']);
                $gtin === null
                    ? $row->fail('GTIN', 'Geçerli bir GTIN değil (8, 12, 13 veya 14 hane, kontrol hanesi doğru olmalı).')
                    : $this->checkUnique($row, 'GTIN', 'GTIN', $gtin, $seen['gtin'], $existingGtins, 'Bu GTIN başka bir üründe kayıtlı.');
                $data['gtin'] = $gtin;
            }

            foreach (['Kategori' => $categories, 'Tedarikçi' => $suppliers] as $column => $existing) {
                $name = trim($raw[$column]);
                $key = ImportValue::key($name);

                if ($name !== '' && ! isset($existing[$key]) && ! isset($creates[$column][$key])) {
                    $creates[$column][$key] = $name;
                }
            }

            $row->values = $data;
            $result[$line] = $row;
        }

        return new ImportResult($result, array_map(fn (array $names) => array_values($names), $creates));
    }

    public function import(ImportResult $result, User $actor): int
    {
        if (! $result->isValid()) {
            throw new LogicException('Hatalı satır içeren dosya içe aktarılamaz.');
        }

        return DB::transaction(function () use ($result) {
            $categoryIds = $this->idsByName(Category::pluck('id', 'name')->all());
            $supplierIds = $this->idsByName(Supplier::pluck('id', 'name')->all());

            foreach ($result->rows as $row) {
                $values = $row->values;

                $values['category_id'] = $this->resolve($row->raw['Kategori'], $categoryIds, fn (string $name) => $this->categories->create(['name' => $name])->id);
                $values['supplier_id'] = $this->resolve($row->raw['Tedarikçi'], $supplierIds, fn (string $name) => $this->suppliers->create(['name' => $name])->id);

                $this->products->create($values);
            }

            return $result->rowCount();
        });
    }

    /**
     * Dosya içinde ve (verildiyse) sistemde tekillik. $seen anahtar => ilk görüldüğü satır.
     *
     * @param  array<string, int>  $seen
     * @param  array<string, true>  $existing
     */
    private function checkUnique(ImportRow $row, string $column, string $noun, string $value, array &$seen, array $existing = [], ?string $existsMessage = null): void
    {
        $key = ImportValue::key($value);

        if ($key === '') {
            return;
        }

        if (isset($seen[$key])) {
            $row->fail($column, "Dosyada satır {$seen[$key]} ile aynı {$noun}.");

            return;
        }

        $seen[$key] = $row->line;

        if ($existsMessage !== null && isset($existing[$key])) {
            $row->fail($column, $existsMessage);
        }
    }

    /**
     * @param  iterable<int, string>  $values
     * @return array<string, true>
     */
    private function keySet(iterable $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $set[ImportValue::key((string) $value)] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, int>  $idsByName
     * @return array<string, int>
     */
    private function idsByName(array $idsByName): array
    {
        $ids = [];
        foreach ($idsByName as $name => $id) {
            $ids[ImportValue::key((string) $name)] ??= $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $ids
     * @param  callable(string): int  $create
     */
    private function resolve(string $name, array &$ids, callable $create): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return $ids[ImportValue::key($name)] ??= $create($name);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProductImporterTest`
Expected: PASS (8 tests). If the Turkish validation attribute message differs (e.g. `lang/tr/validation.php` wording), adjust only the test's expectations of message text after `Satır N · Sütun`, not the importer.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Import tests/Feature/Import/ProductImporterTest.php
git commit -m "feat: ürün şablonu içe aktarımı — önizleme doğrulaması ve tek transaction — Aşama 38 (3/7)"
```

---

### Task 4: Açılış stoğu importer'ı

**Files:**
- Create: `app/Domain/Import/Importers/OpeningStockImporter.php`
- Test: `tests/Feature/Import/OpeningStockImporterTest.php`

**Interfaces:**
- Consumes: `Importer`, `ImportRow`, `ImportResult`, `ImportValue`, `ImportTemplateExport`, `StockMovementService::in(Product, Warehouse, float, array $lotAttributes, ?User, ?string $reason, ?Model $related = null, array $tracking = [])`, `SerialRegistry::parseList(?string): array`, `SerialRegistry::ensureMatchesQuantity(Product, float, array)`, `Warehouse::operational()`, `scopeInBranches`, `User::accessibleBranchIds(Module ...)`.
- Produces: `OpeningStockImporter::REASON = 'Açılış stoğu'`; başlıklar `Ürün Kodu, Şube, Depo, Miktar, Lot No, SKT, Birim Maliyet, Sıcaklık, Seri Numaraları`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Import/OpeningStockImporterTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningStockImporterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private User $admin;

    private OpeningStockImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        Product::create(['name' => 'Enjektör', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->importer = app(OpeningStockImporter::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [...array_fill_keys($this->importer->headings(), ''), 'Ürün Kodu' => 'ENJ-01', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '40', ...$overrides];
    }

    public function test_rows_become_stock_in_movements_with_opening_reason(): void
    {
        $result = $this->importer->validate([
            2 => $this->row(['Lot No' => 'L1', 'SKT' => '31.03.2027', 'Birim Maliyet' => '12,50', 'Ürün Kodu' => ' enj-01 ', 'Şube' => 'merkez']),
            3 => $this->row(['Lot No' => 'L1', 'Miktar' => '10']),
        ], $this->admin);

        $this->assertTrue($result->isValid(), implode("\n", $result->errorMessages()));
        $this->assertSame(2, $this->importer->import($result, $this->admin));

        $lot = StockLot::sole();
        $this->assertEquals(50, $lot->quantity);
        $this->assertSame('2027-03-31', $lot->expiry_date->toDateString());
        $this->assertEquals(12.50, $lot->unit_cost);
        $this->assertSame(2, StockMovement::where('reason', 'Açılış stoğu')->count());
    }

    public function test_product_warehouse_and_values_are_validated(): void
    {
        Product::create(['name' => 'Pasif', 'code' => 'PSF', 'base_unit' => 'Adet', 'status' => 'passive']);
        Product::create(['name' => 'Çift 1', 'code' => 'CFT', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['name' => 'Çift 2', 'code' => 'cft', 'base_unit' => 'Adet', 'status' => 'active']);
        Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Kapalı Depo', 'is_default' => false, 'status' => 'passive']);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'YOK']),
            3 => $this->row(['Ürün Kodu' => 'PSF']),
            4 => $this->row(['Ürün Kodu' => 'CFT']),
            5 => $this->row(['Depo' => 'Kapalı Depo']),
            6 => $this->row(['Miktar' => '0', 'SKT' => '31.02.2027', 'Birim Maliyet' => '-1']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Ürün Kodu: Bu kodla aktif bir ürün yok.', $messages);
        $this->assertStringContainsString('Satır 3 · Ürün Kodu: Bu kodla aktif bir ürün yok.', $messages);
        $this->assertStringContainsString('Satır 4 · Ürün Kodu: Bu kod birden çok üründe kayıtlı', $messages);
        $this->assertStringContainsString('Satır 5 · Depo:', $messages);
        $this->assertStringContainsString('Satır 6 · Miktar', $messages);
        $this->assertStringContainsString('Satır 6 · SKT', $messages);
        $this->assertStringContainsString('Satır 6 · Birim Maliyet', $messages);
    }

    public function test_staff_cannot_load_into_a_branch_outside_their_scope(): void
    {
        $other = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Şube 2', 'status' => 'active']);
        Warehouse::create(['branch_id' => $other->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
        $this->actingAs($staff);

        $result = $this->importer->validate([2 => $this->row(['Şube' => 'Şube 2']), 3 => $this->row()], $staff->fresh());

        $this->assertSame(['Satır 2 · Depo: Bu şube ve depo bulunamadı ya da bu depoda stok girişi yetkiniz yok.'], $result->errorMessages());
    }

    public function test_cold_chain_rows_need_an_in_range_temperature(): void
    {
        Product::create(['name' => 'Aşı', 'code' => 'ASI', 'base_unit' => 'Adet', 'status' => 'active', 'cold_chain' => true, 'storage_min_temp' => 2, 'storage_max_temp' => 8]);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'ASI']),
            3 => $this->row(['Ürün Kodu' => 'ASI', 'Sıcaklık' => '12']),
            4 => $this->row(['Ürün Kodu' => 'ASI', 'Sıcaklık' => '4,5']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Sıcaklık: Soğuk zincir ürününde ölçülen sıcaklık zorunlu.', $messages);
        $this->assertStringContainsString('Satır 3 · Sıcaklık: Saklama aralığı (2–8 °C) dışında.', $messages);
        $this->assertStringNotContainsString('Satır 4', $messages);
    }

    public function test_serial_tracked_rows_need_matching_unique_serials(): void
    {
        Product::create(['name' => 'İmplant', 'code' => 'IMP', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);

        $result = $this->importer->validate([
            2 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S1']),
            3 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S2; S3']),
            4 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '1', 'Seri Numaraları' => 'S3']),
            5 => $this->row(['Seri Numaraları' => 'X1']),
        ], $this->admin);

        $messages = implode("\n", $result->errorMessages());
        $this->assertStringContainsString('Satır 2 · Seri Numaraları', $messages);
        $this->assertStringNotContainsString('Satır 3', $messages);
        $this->assertStringContainsString('Satır 4 · Seri Numaraları: Dosyada satır 3 ile aynı seri: S3', $messages);
        $this->assertStringContainsString('Satır 5 · Seri Numaraları: Bu ürün seri takipli değil', $messages);
    }

    public function test_valid_serial_rows_register_serials(): void
    {
        Product::create(['name' => 'İmplant', 'code' => 'IMP', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true]);

        $result = $this->importer->validate([2 => $this->row(['Ürün Kodu' => 'IMP', 'Miktar' => '2', 'Seri Numaraları' => 'S1, S2'])], $this->admin);
        $this->importer->import($result, $this->admin);

        $this->assertSame(['S1', 'S2'], StockSerial::orderBy('serial_no')->pluck('serial_no')->all());
    }

    public function test_service_failure_rolls_back_every_row(): void
    {
        $result = $this->importer->validate([2 => $this->row(), 3 => $this->row(['Miktar' => '5'])], $this->admin);
        $this->assertTrue($result->isValid());

        // Önizleme ile onay arasında depo kapatıldı: ikinci satırda servis reddeder.
        $result->rows[3]->values['warehouse']->status = 'passive';

        try {
            $this->importer->import($result, $this->admin);
            $this->fail('İstisna bekleniyordu.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, (int) StockLot::sum('quantity'));
    }
}
```

Not: son testte iki satır aynı `Warehouse` nesnesini paylaşırsa birinci satır da reddedilir; testin amacı (hiçbir hareket yazılmaması) yine sağlanır. Importer depo nesnelerini satırlar arasında paylaşıyorsa (aynı anahtar → aynı nesne) bu beklenen davranıştır.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OpeningStockImporterTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Domain/Import/Importers/OpeningStockImporter.php`:

```php
<?php

namespace App\Domain\Import\Importers;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\ImportRow;
use App\Domain\Import\Support\ImportValue;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\SerialRegistry;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Açılış stoğu şablonu (Aşama 38). Her satır bir stok giriş hareketidir ve
 * yalnızca StockMovementService::in üzerinden yazılır; soğuk zincir ve seri
 * kuralları elle girişteki gibi uygulanır. Aralık dışı sıcaklık içe aktarmada
 * gerekçeyle kabul edilmez — o ürün Stok Girişi ekranından girilir.
 */
class OpeningStockImporter implements Importer
{
    public const REASON = 'Açılış stoğu';

    public function __construct(private readonly StockMovementService $stock) {}

    public function headings(): array
    {
        return ['Ürün Kodu', 'Şube', 'Depo', 'Miktar', 'Lot No', 'SKT', 'Birim Maliyet', 'Sıcaklık', 'Seri Numaraları'];
    }

    public function templateFileName(): string
    {
        return 'acilis-stogu-sablonu.xlsx';
    }

    public function template(User $actor): ImportTemplateExport
    {
        $warehouses = $this->warehouses($actor)
            ->map(fn (Warehouse $warehouse) => ["{$warehouse->branch->name} / {$warehouse->name}", 'Şube ve Depo sütunlarına bu adları yazın.'])
            ->values()
            ->all();

        return new ImportTemplateExport('Açılış Stoğu', $this->headings(), [
            ['ENJ-01', 'Merkez', 'Ana Depo', '40', 'LOT-2027-01', '31.03.2027', '12,50', '', ''],
        ], [
            ['Ürün Kodu', 'Zorunlu. Ürün kartındaki kod; ürün aktif olmalı.'],
            ['Şube / Depo', 'Zorunlu. Aşağıdaki listeden; yalnızca stok girişi yetkiniz olan, açık depolar.'],
            ['Miktar', 'Zorunlu, sıfırdan büyük, ürünün ana biriminde. Seri takipli üründe tam sayı.'],
            ['Lot No', 'İsteğe bağlı. Aynı ürün, depo ve lot numarası tek lotta toplanır.'],
            ['SKT', 'İsteğe bağlı, GG.AA.YYYY.'],
            ['Birim Maliyet', 'Boşsa 0.'],
            ['Sıcaklık', 'Soğuk zincir ürününde zorunlu (°C); saklama aralığında olmalı.'],
            ['Seri Numaraları', 'Seri takipli üründe zorunlu; virgül veya noktalı virgülle ayırın. Sayısı miktara eşit olmalı.'],
            ...$warehouses,
        ]);
    }

    public function validate(array $rows, User $actor): ImportResult
    {
        $products = Product::where('status', 'active')->whereNotNull('code')->get()
            ->groupBy(fn (Product $product) => ImportValue::key($product->code));
        $warehouses = $this->warehouses($actor)
            ->keyBy(fn (Warehouse $warehouse) => ImportValue::key($warehouse->branch->name).'|'.ImportValue::key($warehouse->name));

        $seenSerials = [];
        $result = [];

        foreach ($rows as $line => $raw) {
            $row = new ImportRow($line, $raw);

            $matches = $products->get(ImportValue::key($raw['Ürün Kodu']), collect());
            $product = $matches->count() === 1 ? $matches->first() : null;

            if ($raw['Ürün Kodu'] === '') {
                $row->fail('Ürün Kodu', 'Zorunlu.');
            } elseif ($matches->isEmpty()) {
                $row->fail('Ürün Kodu', 'Bu kodla aktif bir ürün yok.');
            } elseif ($matches->count() > 1) {
                $row->fail('Ürün Kodu', 'Bu kod birden çok üründe kayıtlı; ürün kartlarında kodları tekilleştirin.');
            }

            $warehouse = $warehouses->get(ImportValue::key($raw['Şube']).'|'.ImportValue::key($raw['Depo']));
            if ($warehouse === null) {
                $row->fail('Depo', 'Bu şube ve depo bulunamadı ya da bu depoda stok girişi yetkiniz yok.');
            }

            $quantity = ImportValue::number($raw['Miktar']);
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                $row->fail('Miktar', 'Sıfırdan büyük bir sayı olmalı.');
            }

            if (mb_strlen($raw['Lot No']) > 255) {
                $row->fail('Lot No', 'En fazla 255 karakter olabilir.');
            }

            $expiry = ImportValue::date($raw['SKT']);
            if ($expiry === null) {
                $row->fail('SKT', 'Geçerli bir tarih değil (GG.AA.YYYY).');
            }

            $unitCost = ImportValue::number($raw['Birim Maliyet']) ?: '0';
            if (! is_numeric($unitCost) || (float) $unitCost < 0) {
                $row->fail('Birim Maliyet', 'Sıfır veya daha büyük bir sayı olmalı.');
            }

            $temperature = ImportValue::number($raw['Sıcaklık']);
            if ($temperature !== '' && (! is_numeric($temperature) || abs((float) $temperature) > 100)) {
                $row->fail('Sıcaklık', '−100 ile 100 arasında bir sayı olmalı.');
            } elseif ($product?->cold_chain && $temperature === '') {
                $row->fail('Sıcaklık', 'Soğuk zincir ürününde ölçülen sıcaklık zorunlu.');
            } elseif ($product?->cold_chain && ! $product->temperatureInRange((float) $temperature)) {
                $row->fail('Sıcaklık', "Saklama aralığı ({$product->storageRangeLabel()}) dışında.");
            }

            $serials = $product ? $this->serials($row, $product, $quantity, $seenSerials) : [];

            $row->values = [
                'product' => $product,
                'warehouse' => $warehouse,
                'quantity' => (float) $quantity,
                'lot' => ['lot_no' => $raw['Lot No'] ?: null, 'expiry_date' => $expiry ?: null, 'unit_cost' => (float) $unitCost],
                'tracking' => ['temperature' => $temperature === '' ? null : $temperature, 'serials' => $serials],
            ];
            $result[$line] = $row;
        }

        return new ImportResult($result);
    }

    public function import(ImportResult $result, User $actor): int
    {
        if (! $result->isValid()) {
            throw new LogicException('Hatalı satır içeren dosya içe aktarılamaz.');
        }

        return DB::transaction(function () use ($result, $actor) {
            foreach ($result->rows as $row) {
                $values = $row->values;

                $this->stock->in(
                    $values['product'], $values['warehouse'], $values['quantity'], $values['lot'],
                    $actor, self::REASON, tracking: $values['tracking'],
                );
            }

            return $result->rowCount();
        });
    }

    /**
     * @return Collection<int, Warehouse>
     */
    private function warehouses(User $actor): Collection
    {
        return Warehouse::operational()
            ->inBranches($actor->accessibleBranchIds(Module::StockMovement))
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seri takipli üründe seri listesi; dosya içinde ve stokta tekrar etmemeli.
     *
     * @param  array<string, int>  $seen  "ürünId|seri" => ilk görüldüğü satır
     * @return array<int, string>
     */
    private function serials(ImportRow $row, Product $product, string $quantity, array &$seen): array
    {
        $text = $row->raw['Seri Numaraları'];

        if (! $product->tracks_serials) {
            if ($text !== '') {
                $row->fail('Seri Numaraları', 'Bu ürün seri takipli değil; sütunu boş bırakın.');
            }

            return [];
        }

        try {
            $serials = SerialRegistry::parseList($text);

            if (is_numeric($quantity)) {
                SerialRegistry::ensureMatchesQuantity($product, (float) $quantity, $serials);
            }
        } catch (SerialException $e) {
            $row->fail('Seri Numaraları', $e->getMessage());

            return [];
        }

        foreach ($serials as $serial) {
            $key = "{$product->id}|{$serial}";

            if (isset($seen[$key])) {
                $row->fail('Seri Numaraları', "Dosyada satır {$seen[$key]} ile aynı seri: {$serial}");
            }

            $seen[$key] ??= $row->line;
        }

        $inStock = StockSerial::where('product_id', $product->id)
            ->whereIn('serial_no', $serials)
            ->whereIn('status', [SerialStatus::InStock, SerialStatus::InTransit])
            ->pluck('serial_no');

        if ($inStock->isNotEmpty()) {
            $row->fail('Seri Numaraları', 'Bu seriler zaten stokta: '.$inStock->implode(', '));
        }

        return $serials;
    }
}
```

Note: `Warehouse::operational()` already applies `whereHas('branch', ...)`, so the Branch organization scope isolates tenants (same as Stock Girişi). Check `storageRangeLabel()` output format; if it is `2–8 °C`, the test message matches; otherwise adjust the test string to the actual label.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=OpeningStockImporterTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Import/Importers/OpeningStockImporter.php tests/Feature/Import/OpeningStockImporterTest.php
git commit -m "feat: açılış stoğu içe aktarımı — kapsam, soğuk zincir ve seri kuralları — Aşama 38 (4/7)"
```

---

### Task 5: Veri Aktarımı ekranı, yetki, rota ve menü

**Files:**
- Modify: `app/Providers/AuthServiceProvider.php` (son `Gate::define`'dan sonra)
- Modify: `routes/web.php` (`/ayarlar` rotasından önce)
- Modify: `resources/views/layouts/authenticated.blade.php` (Ayarlar bağlantısından önce)
- Create: `resources/views/pages/import/⚡index.blade.php`
- Test: `tests/Feature/Import/ImportScreenTest.php`

**Interfaces:**
- Consumes: `ProductImporter`, `OpeningStockImporter` (`Importer` arayüzü), `SpreadsheetReader::read`, `ImportFileException`.
- Produces: kapı `data_import.create`; rota adı `import.index` (`/veri-aktarimi`); Livewire bileşeni `pages::import.index` — public `string $tab` (`products|opening-stock`), `$file`, `?array $preview`; eylemler `selectTab(string)`, `downloadTemplate()`, `import()`, `resetUpload()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Import/ImportScreenTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportScreenTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    private function upload(array $rows, string $name = 'dosya.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents($this->spreadsheet($rows)));
    }

    private function staff(Module $module, bool $write): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    public function test_page_requires_a_write_permission(): void
    {
        $this->actingAs($this->staff(Module::ProductManagement, false))->get('/veri-aktarimi')->assertForbidden();
        $this->actingAs($this->admin)->get('/veri-aktarimi')->assertOk()->assertSee('Veri Aktarımı');
    }

    public function test_tabs_follow_their_own_permission(): void
    {
        $this->actingAs($this->staff(Module::StockMovement, true));

        Livewire::test('pages::import.index')
            ->assertSet('tab', 'opening-stock')
            ->call('selectTab', 'products')
            ->assertForbidden();
    }

    public function test_template_download(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::import.index')
            ->call('downloadTemplate')
            ->assertFileDownloaded('urun-sablonu.xlsx');
    }

    public function test_preview_lists_errors_and_blocks_import(): void
    {
        $this->actingAs($this->admin);
        $headings = app(\App\Domain\Import\Importers\ProductImporter::class)->headings();

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([$headings, array_replace(array_fill(0, count($headings), ''), [0 => 'Enjektör'])]))
            ->assertSet('preview.errorCount', 1)
            ->assertSee('Satır 2 · Ürün Kodu')
            ->call('import')
            ->assertHasErrors('file');

        $this->assertSame(0, Product::count());
    }

    public function test_missing_columns_are_shown_as_a_file_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([['Ürün Adı'], ['Enjektör']]))
            ->assertHasErrors('file')
            ->assertSet('preview', null);
    }

    public function test_products_then_opening_stock_are_imported(): void
    {
        $this->actingAs($this->admin);
        $productHeadings = app(\App\Domain\Import\Importers\ProductImporter::class)->headings();
        $product = array_combine($productHeadings, array_fill(0, count($productHeadings), ''));
        $product = [...$product, 'Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet'];

        Livewire::test('pages::import.index')
            ->set('file', $this->upload([$productHeadings, array_values($product)]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('preview', null)
            ->assertSee('1 ürün içe aktarıldı.')
            ->call('selectTab', 'opening-stock')
            ->set('file', $this->upload([
                ['Ürün Kodu', 'Şube', 'Depo', 'Miktar', 'Lot No', 'SKT', 'Birim Maliyet', 'Sıcaklık', 'Seri Numaraları'],
                ['ENJ-01', 'Merkez', 'Ana Depo', '40', 'L1', '31.03.2027', '12,50', '', ''],
            ]))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('1 satır açılış stoğu içe aktarıldı.');

        $this->assertEquals(40, StockLot::sole()->quantity);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ImportScreenTest`
Expected: FAIL — 404 on `/veri-aktarimi` / component not found.

- [ ] **Step 3: Gate, route, menu**

`app/Providers/AuthServiceProvider.php` — after the `warehouse_stock.viewAny` definition:

```php

        // Toplu veri aktarımı (Aşama 38): ürün veya stok yazma yetkisi yeterli;
        // her sekme kendi modül yetkisiyle ayrıca denetlenir. ".create" soneki
        // salt-okunur organizasyonda kapatır.
        Gate::define('data_import.create', fn (User $user) => $user->canModule(Module::ProductManagement, 'write')
            || $user->canModule(Module::StockMovement, 'write'));
```

`routes/web.php` — before the `/ayarlar` route:

```php
    Route::livewire('/veri-aktarimi', 'pages::import.index')
        ->middleware(['auth', 'can:data_import.create'])
        ->name('import.index');

```

`resources/views/layouts/authenticated.blade.php` — before `@can('system_settings.viewAny')`:

```blade
                    @can('data_import.create')
                        <x-nav-link :href="route('import.index')" :active="request()->routeIs('import.index')">
                            <x-slot:icon><path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            Veri Aktarımı
                        </x-nav-link>
                    @endcan
```

- [ ] **Step 4: Create the page**

`resources/views/pages/import/⚡index.blade.php`:

```blade
<?php

use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exceptions\ImportFileException;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Catalog\Exceptions\ProductRuleException;
use App\Domain\Stock\Exceptions\ColdChainException;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\SerialException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Toplu veri aktarımı (Aşama 38): şablonu indir → doldur → yükle → önizle →
 * içe aktar. Tek hatalı satır varsa hiçbir satır yazılmaz. Satırlar bileşen
 * durumunda taşınmaz; onayda dosya yeniden okunup yeniden doğrulanır.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithFileUploads;

    /** Sekme => [importer, gerekli yetki, başarı mesajı kalıbı]. */
    private const TABS = [
        'products' => [ProductImporter::class, 'product_management.create', ':count ürün içe aktarıldı.'],
        'opening-stock' => [OpeningStockImporter::class, 'stock_movement.create', ':count satır açılış stoğu içe aktarıldı.'],
    ];

    private const PREVIEW_ERRORS = 100;

    private const PREVIEW_ROWS = 20;

    public string $tab = 'products';

    public $file = null;

    /** @var array{rowCount: int, errorCount: int, errors: array<int, string>, moreErrors: int, creates: array<string, array<int, string>>, headings: array<int, string>, sample: array<int, array<string, string>>}|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->tab = collect(self::TABS)->keys()->first(fn (string $tab) => Gate::allows(self::TABS[$tab][1])) ?? 'products';
    }

    public function selectTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        Gate::authorize(self::TABS[$tab][1]);

        $this->resetUpload();
        $this->tab = $tab;
    }

    public function downloadTemplate()
    {
        Gate::authorize(self::TABS[$this->tab][1]);
        $importer = $this->importer();

        return Excel::download($importer->template(auth()->user()), $importer->templateFileName());
    }

    public function updatedFile(): void
    {
        Gate::authorize(self::TABS[$this->tab][1]);
        $this->preview = null;

        $result = $this->validateFile();

        if ($result === null) {
            return;
        }

        $this->preview = [
            'rowCount' => $result->rowCount(),
            'errorCount' => $result->errorCount(),
            'errors' => array_slice($result->errorMessages(), 0, self::PREVIEW_ERRORS),
            'moreErrors' => max(0, count($result->errorMessages()) - self::PREVIEW_ERRORS),
            'creates' => $result->creates,
            'headings' => $this->importer()->headings(),
            'sample' => array_values(array_map(fn ($row) => $row->raw, array_slice($result->rows, 0, self::PREVIEW_ROWS, true))),
        ];
    }

    public function import(): void
    {
        Gate::authorize(self::TABS[$this->tab][1]);

        // Önizleme ile onay arasında veri değişmiş olabilir: yeniden okunur ve doğrulanır.
        $result = $this->validateFile();

        if ($result === null) {
            return;
        }

        if (! $result->isValid()) {
            $this->updatedFile();
            $this->addError('file', 'Dosyada hatalı satırlar var. Dosyayı düzeltip yeniden yükleyin.');

            return;
        }

        try {
            $count = $this->importer()->import($result, auth()->user());
        } catch (ColdChainException|SerialException|InactiveLocationException|ProductRuleException|InvalidArgumentException $e) {
            $this->addError('file', 'İçe aktarma geri alındı, hiçbir kayıt yazılmadı: '.$e->getMessage());

            return;
        }

        $this->resetUpload();
        session()->flash('status', str_replace(':count', (string) $count, self::TABS[$this->tab][2]));
    }

    public function resetUpload(): void
    {
        $this->file?->delete();
        $this->reset(['file', 'preview']);
        $this->resetValidation();
    }

    private function validateFile(): ?ImportResult
    {
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']], [
            'file.mimes' => 'Yalnızca .xlsx veya .csv dosyası yükleyin.',
        ]);

        try {
            $rows = app(SpreadsheetReader::class)->read(
                $this->file->getRealPath(),
                $this->file->getClientOriginalExtension(),
                $this->importer()->headings(),
            );
        } catch (ImportFileException $e) {
            $this->addError('file', $e->getMessage());

            return null;
        }

        return $this->importer()->validate($rows, auth()->user());
    }

    private function importer(): Importer
    {
        return app(self::TABS[$this->tab][0]);
    }

    public function with(): array
    {
        return [
            'tabs' => collect(['products' => 'Ürünler', 'opening-stock' => 'Açılış Stoğu'])
                ->filter(fn ($label, $tab) => Gate::allows(self::TABS[$tab][1])),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Veri Aktarımı</h1>
        <p class="mt-1 text-[14px] text-ink-muted">Ürünlerinizi ve elinizdeki stoğu Excel şablonuyla toplu olarak içeri alın. Önce ürünleri, sonra açılış stoğunu yükleyin.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-brand-500/30 bg-brand-500/5 px-4 py-3 text-[14px] text-ink">{{ session('status') }}</div>
    @endif

    <div class="mb-6 flex gap-1 border-b border-line">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="selectTab('{{ $key }}')"
                @class(['px-4 py-2.5 text-[14px] -mb-px border-b-2 transition-colors', 'border-panel-900 text-ink font-medium' => $tab === $key, 'border-transparent text-ink-muted hover:text-ink' => $tab !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    <ol class="max-w-3xl space-y-4">
        <li class="border border-line rounded-lg bg-surface p-5">
            <h2 class="text-[15px] font-medium text-ink">1. Şablonu indirin ve doldurun</h2>
            <p class="mt-1 text-[13px] text-ink-muted">
                @if ($tab === 'products')
                    Her satır bir ürün kartıdır. Ürün kodu zorunlu ve tekildir; sistemde olan kodlar içe aktarılmaz. "Açıklama" sayfasında her sütunun kuralı yazar.
                @else
                    Her satır bir stok girişidir ve "Açılış stoğu" gerekçesiyle kaydedilir. Ürünler kodlarıyla bulunur; önce ürünleri içe aktarın.
                @endif
            </p>
            <button type="button" wire:click="downloadTemplate" wire:loading.attr="disabled" class="mt-4 text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Şablonu indir (.xlsx)</button>
        </li>

        <li class="border border-line rounded-lg bg-surface p-5">
            <h2 class="text-[15px] font-medium text-ink">2. Dosyayı yükleyin</h2>
            <p class="mt-1 text-[13px] text-ink-muted">.xlsx veya .csv, en fazla 2.000 satır. Yükleyince dosya kontrol edilir; henüz hiçbir şey kaydedilmez.</p>
            <input type="file" wire:model="file" accept=".xlsx,.csv" class="mt-4 block text-[14px]">
            <p wire:loading wire:target="file" class="mt-2 text-[13px] text-ink-muted">Dosya kontrol ediliyor…</p>
            @error('file') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror
        </li>

        @if ($preview)
            <li class="border border-line rounded-lg bg-surface p-5">
                <h2 class="text-[15px] font-medium text-ink">3. Önizleme</h2>
                <p class="mt-1 text-[14px] text-ink">
                    {{ $preview['rowCount'] }} satır okundu ·
                    @if ($preview['errorCount'] === 0)
                        <span class="text-brand-600">hata yok</span>
                    @else
                        <span class="text-red-600">{{ $preview['errorCount'] }} satırda hata var</span>
                    @endif
                </p>

                @foreach ($preview['creates'] as $label => $names)
                    @if ($names)
                        <p class="mt-2 text-[13px] text-ink-muted">Oluşturulacak {{ mb_strtolower($label) }} ({{ count($names) }}): {{ implode(', ', $names) }}</p>
                    @endif
                @endforeach

                @if ($preview['errors'])
                    <ul class="mt-4 max-h-72 overflow-y-auto rounded-md border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 space-y-1">
                        @foreach ($preview['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                        @if ($preview['moreErrors'])
                            <li>… ve {{ $preview['moreErrors'] }} hata daha.</li>
                        @endif
                    </ul>
                    <p class="mt-3 text-[13px] text-ink-muted">Dosyayı düzeltip yeniden yükleyin. Hata varken hiçbir satır kaydedilmez.</p>
                @endif

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-[12px]">
                        <thead>
                            <tr class="text-left text-ink-muted">
                                @foreach ($preview['headings'] as $heading)
                                    <th class="px-2 py-1.5 font-medium whitespace-nowrap">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['sample'] as $row)
                                <tr class="border-t border-line">
                                    @foreach ($preview['headings'] as $heading)
                                        <td class="px-2 py-1.5 whitespace-nowrap">{{ $row[$heading] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($preview['rowCount'] > count($preview['sample']))
                        <p class="mt-2 text-[12px] text-ink-muted">İlk {{ count($preview['sample']) }} satır gösteriliyor.</p>
                    @endif
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <button type="button" wire:click="import" wire:loading.attr="disabled" @disabled($preview['errorCount'] > 0)
                        class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ $preview['rowCount'] }} satırı içe aktar
                    </button>
                    <button type="button" wire:click="resetUpload" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
                </div>
            </li>
        @endif
    </ol>
</div>
```

Add `use InvalidArgumentException;` is unnecessary in a closure-less class body — PHP needs the import: add `use InvalidArgumentException;` to the `use` block at the top of the file.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ImportScreenTest`
Expected: PASS (6 tests). If `assertFileDownloaded` is unavailable in this Livewire version, replace it with `->assertStatus(200)` and assert the response via `Excel::fake()` + `Excel::assertDownloaded('urun-sablonu.xlsx')`.

- [ ] **Step 6: Visual check**

Run the screenshot helper (`.superpowers/tools/finance-screenshot.php`, git-ignored) for `/veri-aktarimi` with an uploaded erroneous file, or open the page in the browser; check layout at desktop and phone width. Follow the frontend-design memory: page must look like the other admin pages (same cards, spacing, colors).

- [ ] **Step 7: Commit**

```bash
git add app/Providers/AuthServiceProvider.php routes/web.php resources/views/layouts/authenticated.blade.php "resources/views/pages/import" tests/Feature/Import/ImportScreenTest.php
git commit -m "feat: Veri Aktarımı ekranı — şablon, önizleme ve içe aktarma — Aşama 38 (5/7)"
```

---

### Task 6: Kabul testi

**Files:**
- Test: `tests/Feature/Acceptance/BulkImportAcceptanceTest.php`

**Interfaces:**
- Consumes: everything above; `pages::stock.status` for the final check (look at `tests/Feature/Stock/StockStatusScreenTest.php` for how it asserts quantities).

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Import\BuildsSpreadsheets;
use Tests\TestCase;

/**
 * Aşama 38 kabul senaryosu: yeni klinik şablonları doldurur, önce ürünleri
 * sonra açılış stoğunu içe aktarır; hatalı dosya hiçbir şey yazmaz; stok
 * durumu ve diğer organizasyon etkilenmez.
 */
class BulkImportAcceptanceTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    public function test_new_clinic_moves_catalog_and_stock_in_with_templates(): void
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $other = Organization::create(['name' => 'Başka', 'status' => 'active', 'plan' => 'starter']);
        Product::create(['organization_id' => $other->id, 'name' => 'Onların', 'code' => 'ENJ-01', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($admin);
        $products = app(ProductImporter::class)->headings();
        $stock = app(OpeningStockImporter::class)->headings();
        $productRow = fn (array $values) => array_values([...array_fill_keys($products, ''), ...$values]);
        $stockRow = fn (array $values) => array_values([...array_fill_keys($stock, ''), ...$values]);
        $upload = fn (array $rows) => UploadedFile::fake()->createWithContent('dosya.xlsx', file_get_contents($this->spreadsheet($rows)));

        // 1. Şablonlar indirilebilir.
        Excel::fake();
        Livewire::test('pages::import.index')->call('downloadTemplate');
        Excel::assertDownloaded('urun-sablonu.xlsx');

        // 2. Hatalı ürün dosyası: önizleme hatayı gösterir, hiçbir şey yazılmaz.
        Livewire::test('pages::import.index')
            ->set('file', $upload([$products,
                $productRow(['Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', 'Kategori' => 'Sarf']),
                $productRow(['Ürün Adı' => 'Aşı', 'Ürün Kodu' => 'ASI', 'Ana Birim' => 'Adet', 'Soğuk Zincir' => 'E']),
            ]))
            ->assertSet('preview.errorCount', 1)
            ->call('import');
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());

        // 3. Düzeltilmiş dosya içe aktarılır.
        Livewire::test('pages::import.index')
            ->set('file', $upload([$products,
                $productRow(['Ürün Adı' => 'Enjektör', 'Ürün Kodu' => 'ENJ-01', 'Ana Birim' => 'Adet', 'Kategori' => 'Sarf', 'Tedarikçi' => 'Medikal']),
                $productRow(['Ürün Adı' => 'Aşı', 'Ürün Kodu' => 'ASI', 'Ana Birim' => 'Adet', 'Soğuk Zincir' => 'E', 'Min Sıcaklık' => '2', 'Max Sıcaklık' => '8', 'Ürün Türü' => 'İlaç']),
                $productRow(['Ürün Adı' => 'İmplant', 'Ürün Kodu' => 'IMP', 'Ana Birim' => 'Adet', 'Seri Takipli' => 'E', 'Ürün Türü' => 'Ekipman']),
            ]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertSee('3 ürün içe aktarıldı.');
        $this->assertSame(3, Product::count());
        $this->assertSame(1, Supplier::count());

        // 4. Açılış stoğu.
        Livewire::test('pages::import.index')
            ->call('selectTab', 'opening-stock')
            ->set('file', $upload([$stock,
                $stockRow(['Ürün Kodu' => 'ENJ-01', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '40', 'Lot No' => 'L1', 'SKT' => '31.03.2027', 'Birim Maliyet' => '12,50']),
                $stockRow(['Ürün Kodu' => 'ASI', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '10', 'Sıcaklık' => '4']),
                $stockRow(['Ürün Kodu' => 'IMP', 'Şube' => 'Merkez', 'Depo' => 'Ana Depo', 'Miktar' => '2', 'Seri Numaraları' => 'S-1; S-2']),
            ]))
            ->assertSet('preview.errorCount', 0)
            ->call('import')
            ->assertSee('3 satır açılış stoğu içe aktarıldı.');

        $this->assertSame(3, StockMovement::where('reason', OpeningStockImporter::REASON)->count());
        $this->assertEquals(40, StockLot::whereHas('product', fn ($q) => $q->where('code', 'ENJ-01'))->sole()->quantity);
        $this->assertSame(2, StockSerial::count());

        // 5. Diğer organizasyonun ürünü değişmedi.
        $this->assertSame(1, Product::withoutGlobalScopes()->where('organization_id', $other->id)->count());
    }
}
```

Not: `Excel::fake()` ve gerçek dosya okuma birlikte kullanılamayabilir (fake okuyucuyu da değiştirebilir). Okuyucu PhpSpreadsheet'i doğrudan kullandığı için etkilenmez; yine de sorun çıkarsa adım 1'i ayrı bir test metoduna taşı.

- [ ] **Step 2: Run**

Run: `php artisan test --compact --filter=BulkImportAcceptanceTest`
Expected: PASS.

- [ ] **Step 3: Full suite**

Run: `php artisan test --compact`
Expected: all PASS (893 + yeni testler).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Acceptance/BulkImportAcceptanceTest.php
git commit -m "test: toplu veri aktarımı kabul testi — Aşama 38 (6/7)"
```

---

### Task 7: Belgeler ve rapor

**Files:**
- Modify: `README.md` (modül listesine "Veri Aktarımı" maddesi)
- Modify: tanıtım sitesi yalnızca ekran listesi metinde modül sayıyorsa (bak: `grep -rn "Finans" resources/views/components/marketing | head`); örnek ekran HTML kopyası eklenmez.

- [ ] **Step 1: README**

README'de modüllerin listelendiği bölüme ekle:

```markdown
- **Veri Aktarımı (Aşama 38):** Ürün kartları ve açılış stoğu Excel/CSV şablonlarıyla içeri alınır. Dosya önce doğrulanır, satır satır hatalar gösterilir; tek hata varsa hiçbir satır yazılmaz. Ürün kodu zorunlu ve tekil; olmayan kategori/tedarikçi oluşturulur; açılış stoğu soğuk zincir ve seri kurallarıyla "Açılış stoğu" gerekçeli stok girişi olarak yazılır.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: Veri Aktarımı — README — Aşama 38 (7/7)"
```

- [ ] **Step 3: NotebookLM raporu** — `asama-38-rapor.md — Toplu veri aktarımı`: ne yapıldı, kararlar ve gerekçeleri, testler, bulunan hatalar, bilinen boşluklar (güncelleme yok, alternatif birim yok, aralık dışı sıcaklık içe aktarılamaz), sonraki adım.
