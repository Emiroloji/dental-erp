# Finans Aşama 34 — Defter Çekirdeği Uygulama Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kliniğin kasa/banka/POS hesaplarını, gelir/gider kategorilerini ve tek, silinmez bir finans defterini (gelir, gider, transfer, iptal) kurmak; Hesaplar, Kategoriler ve Hareketler ekranlarını açmak.

**Architecture:** Yeni `app/Domain/Finance` alanı. Deftere (`finance_entries`) yalnızca `LedgerService` yazar; model güncelleme ve silmeyi reddeder. Bakiyeler saklanmaz, `FinanceBalanceService` defterden türetir. Tutarlar kuruş (int); `Money` ayrıştırır ve biçimlendirir. Yetki yeni `Module::Finance` ve mevcut `AuthorizesModule` deseniyle.

**Tech Stack:** Laravel 12, Livewire 4 tek dosyalı sayfalar (`resources/views/pages/**/⚡*.blade.php`), Tailwind v4 (`resources/css/app.css` @theme token'ları), PHPUnit (SQLite bellek içi), Pint.

**Spec:** `docs/superpowers/specs/2026-10-14-finans-modulu-design.md`

## Global Constraints

- Ekran metinleri, kod yorumları, commit mesajları Türkçe (karar 4.1). Commit biçimi: `feat: ... — Aşama 34 (n/9)`, son commit `— Aşama 34 tamamlandı`; her commit sonuna `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Tutarlar kuruş cinsinden `int`/`bigInteger`; hiçbir yerde float para yok.
- `finance_entries` satırı güncellenmez ve silinmez; düzeltme ters kayıtla (`reversal`).
- Bu aşamada `party_id`, `obligation_id`, `payment`, `adjustment` YOK (Aşama 35/36 ekler).
- Pasif kayıt `status = 'passive'` (mevcut Category deseni), aktif `'active'`.
- "Bugün" her zaman `FinanceCalendar::today()` (Europe/Istanbul); `today()`/`now()` doğrudan kullanılmaz.
- Yeni JS bağımlılığı eklenmez. Renkler yalnızca `app.css` token'ları; finans tutar renkleri yeni `finance-in` / `finance-out` token'ları, `status-*` tutar için kullanılmaz (pasif/aktif rozetleri mevcut ekranlardaki gibi kalabilir).
- Arayüz görevlerinde `frontend-design` skill'i yüklenir; `npm run build` çalıştırılır ve sayfa tarayıcıda gözle kontrol edilir (karar 5.5).
- Test komutu: `php artisan test --filter=<Ad>`; tümü: `php artisan test`; biçim: `vendor/bin/pint --dirty`.

---

## Dosya Haritası

| Dosya | Sorumluluk |
|---|---|
| `app/Domain/Finance/Support/Money.php` | Kuruş ↔ Türkçe metin |
| `app/Domain/Finance/Support/FinanceCalendar.php` | İstanbul saatine göre bugün |
| `app/Domain/Finance/Support/AccountType.php` | cash/bank/pos enum + etiket |
| `app/Domain/Finance/Support/CategoryDirection.php` | income/expense enum + etiket |
| `app/Domain/Finance/Support/FinanceEntryType.php` | Defter türleri + yön + etiket |
| `app/Domain/Finance/Exceptions/FinanceRuleException.php` | Kullanıcıya gösterilen iş kuralı hatası |
| `app/Domain/Finance/Models/FinanceAccount.php` | Hesap |
| `app/Domain/Finance/Models/FinanceCategory.php` | Kategori |
| `app/Domain/Finance/Models/FinanceEntry.php` | Defter satırı (değişmez) |
| `app/Domain/Finance/Services/FinanceAccountService.php` | Hesap aç / pasife al |
| `app/Domain/Finance/Services/FinanceCategoryService.php` | Kategori ekle / pasife al / önerilenleri ekle |
| `app/Domain/Finance/Services/LedgerService.php` | Defterin tek kapısı |
| `app/Domain/Finance/Services/FinanceBalanceService.php` | Bakiye türetme |
| `app/Domain/Finance/Policies/FinancePolicy.php` | Modül yetkisi |
| `database/migrations/2026_10_14_100000_create_finance_core_tables.php` | Üç tablo |
| `resources/views/components/finance-tabs.blade.php` | Finans sekmeleri |
| `resources/views/pages/finance/⚡accounts.blade.php` | Hesaplar |
| `resources/views/pages/finance/⚡categories.blade.php` | Kategoriler |
| `resources/views/pages/finance/⚡entries.blade.php` | Hareketler |
| Değişen: `app/Domain/Access/Support/Module.php`, `app/Providers/AuthServiceProvider.php`, `app/Domain/Access/Services/StaffService.php`, `resources/views/pages/access/⚡staff.blade.php`, `routes/web.php`, `resources/views/layouts/authenticated.blade.php`, `resources/css/app.css`, `README.md` |

---

### Task 1: Money ve FinanceCalendar

**Files:**
- Create: `app/Domain/Finance/Support/Money.php`
- Create: `app/Domain/Finance/Support/FinanceCalendar.php`
- Test: `tests/Unit/Finance/MoneyTest.php`

**Interfaces:**
- Produces: `Money::parse(string): int` (geçersizde `InvalidArgumentException`), `Money::format(int): string` (`'1.250,50 ₺'`, negatif `'−1.250,50 ₺'`), `Money::input(int): string` (`'1250,50'`), `FinanceCalendar::today(): CarbonImmutable` (Europe/Istanbul, gün başı).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public static function parsable(): array
    {
        return [
            'Türkçe binlik ve kuruş' => ['1.250,50', 125050],
            'yalnızca virgül' => ['1250,5', 125050],
            'tam sayı' => ['1250', 125000],
            'binlik nokta' => ['12.500', 1250000],
            'ondalık nokta' => ['12.5', 1250],
            'iki haneli ondalık nokta' => ['99.99', 9999],
            'para simgesi ve boşluk' => [' 1.000,00 ₺ ', 100000],
            'TL eki' => ['300 TL', 30000],
            'sıfır' => ['0', 0],
            'eksi' => ['-150,25', -15025],
            'tipografik eksi' => ['−150', -15000],
        ];
    }

    #[DataProvider('parsable')]
    public function test_parses_turkish_amounts_into_kurus(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public static function invalid(): array
    {
        return [[''], ['abc'], ['1,234,5'], ['12,345'], ['1.2.3'], ['99999999999999']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($input);
    }

    public function test_formats_kurus_in_turkish(): void
    {
        $this->assertSame('1.250,50 ₺', Money::format(125050));
        $this->assertSame('0,05 ₺', Money::format(5));
        $this->assertSame('−1.250,00 ₺', Money::format(-125000));
        $this->assertSame('1.234.567,89 ₺', Money::format(123456789));
    }

    public function test_input_value_round_trips(): void
    {
        $this->assertSame('1250,50', Money::input(125050));
        $this->assertSame(125050, Money::parse(Money::input(125050)));
    }

    public function test_today_is_istanbul_date_even_when_utc_is_yesterday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'));

        $this->assertSame('2026-10-15', FinanceCalendar::today()->toDateString());

        CarbonImmutable::setTestNow();
    }
}
```

- [ ] **Step 2: Çalıştır, kırmızı olduğunu gör**

Run: `php artisan test --filter=MoneyTest`
Expected: FAIL — `Class "App\Domain\Finance\Support\Money" not found`

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Support/Money.php`:

```php
<?php

namespace App\Domain\Finance\Support;

use InvalidArgumentException;

/**
 * Finans tutarları kuruş cinsinden tam sayı tutulur (karar 1.7): float yuvarlama
 * hatası üretir. Bu sınıf yalnızca ekran ile servis arasındaki çeviriyi yapar;
 * servisler her zaman kuruş (int) alır.
 */
final class Money
{
    private const MAX_LIRA_DIGITS = 13;

    /**
     * "1.250,50", "1250,5", "12.500", "12.5", "300 TL" → kuruş. Virgül varsa
     * ondalık ayırıcıdır ve noktalar binliktir; virgül yoksa yalnızca üçerli
     * gruplanmış noktalar binlik sayılır, tek nokta ondalıktır.
     */
    public static function parse(string $input): int
    {
        $value = str_replace([' ', '₺', 'TL', 'tl'], '', trim($input));
        $value = str_replace('−', '-', $value);

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        if (str_contains($value, ',')) {
            if (! preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $value)) {
                throw new InvalidArgumentException('Geçersiz tutar. Örnek: 1.250,50');
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) || strlen($matches[1]) > self::MAX_LIRA_DIGITS) {
            throw new InvalidArgumentException('Geçersiz tutar. Örnek: 1.250,50');
        }

        $kurus = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');

        return $negative ? -$kurus : $kurus;
    }

    public static function format(int $kurus): string
    {
        return ($kurus < 0 ? '−' : '').self::digits(abs($kurus), '.').' ₺';
    }

    /**
     * Form alanına geri yazılacak sade biçim (binlik ayırıcısız).
     */
    public static function input(int $kurus): string
    {
        return ($kurus < 0 ? '-' : '').self::digits(abs($kurus), '');
    }

    private static function digits(int $kurus, string $thousands): string
    {
        return number_format(intdiv($kurus, 100), 0, ',', $thousands).','.str_pad((string) ($kurus % 100), 2, '0', STR_PAD_LEFT);
    }
}
```

`app/Domain/Finance/Support/FinanceCalendar.php`:

```php
<?php

namespace App\Domain\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * Uygulama UTC çalışır; finans tarihleri ise kliniğin takvim günüdür. Türkiye'de
 * 00:00–03:00 arasında UTC hâlâ dündür — "bugün" UTC'den alınsaydı bugünün
 * tarihli hareketi "ileri tarihli" diye reddedilirdi.
 */
final class FinanceCalendar
{
    public const TIMEZONE = 'Europe/Istanbul';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
```

- [ ] **Step 4: Çalıştır, yeşil**

Run: `php artisan test --filter=MoneyTest`
Expected: PASS (tüm veri satırları)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Finance tests/Unit/Finance
git commit -m "feat: Finans para yardımcıları — kuruş ayrıştırma/biçimlendirme ve İstanbul takvim günü — Aşama 34 (1/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Tablolar, modeller, enum'lar

**Files:**
- Create: `database/migrations/2026_10_14_100000_create_finance_core_tables.php`
- Create: `app/Domain/Finance/Support/AccountType.php`, `CategoryDirection.php`, `FinanceEntryType.php`
- Create: `app/Domain/Finance/Exceptions/FinanceRuleException.php`
- Create: `app/Domain/Finance/Models/FinanceAccount.php`, `FinanceCategory.php`, `FinanceEntry.php`
- Test: `tests/Feature/Finance/FinanceModelsTest.php`

**Interfaces:**
- Produces: `AccountType::{Cash,Bank,Pos}` (`->label()`), `CategoryDirection::{Income,Expense}` (`->label()`), `FinanceEntryType::{Income,Expense,TransferOut,TransferIn,Reversal}` (`->label()`, `->direction(): ?int` — Reversal için null), `FinanceRuleException extends RuntimeException`.
- `FinanceAccount` alanları: `organization_id, name, type, iban, opening_balance(int), opening_date(date), currency, status`; `isActive(): bool`; `entries(): HasMany`.
- `FinanceCategory`: `organization_id, name, direction, status`; `isActive(): bool`.
- `FinanceEntry`: `organization_id, account_id, type, direction(int), amount(int), occurred_on(date), category_id, branch_id, transfer_group, reverses_entry_id, description, document_no, actor_id, created_at`; `signedAmount(): int`; ilişkiler `account, category, branch, actor, reversal (HasOne reverses_entry_id), reverses (BelongsTo)`; `scopeNotReversed`; kaydedilmiş satır güncellenir/silinirse `LogicException`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class FinanceModelsTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): FinanceEntry
    {
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $account = FinanceAccount::create([
            'organization_id' => $organization->id, 'name' => 'Kasa', 'type' => AccountType::Cash,
            'opening_balance' => 0, 'opening_date' => '2026-01-01', 'status' => 'active',
        ]);

        return FinanceEntry::create([
            'organization_id' => $organization->id, 'account_id' => $account->id,
            'type' => FinanceEntryType::Income, 'direction' => 1, 'amount' => 5000,
            'occurred_on' => '2026-10-01',
        ]);
    }

    public function test_entry_casts_and_signed_amount(): void
    {
        $entry = $this->entry()->fresh();

        $this->assertSame(FinanceEntryType::Income, $entry->type);
        $this->assertSame(5000, $entry->amount);
        $this->assertSame(5000, $entry->signedAmount());
        $this->assertSame('TRY', $entry->account->currency);
    }

    public function test_entries_cannot_be_updated(): void
    {
        $entry = $this->entry();

        $this->expectException(LogicException::class);

        $entry->update(['amount' => 1]);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = $this->entry();

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_entry_type_directions(): void
    {
        $this->assertSame(1, FinanceEntryType::Income->direction());
        $this->assertSame(-1, FinanceEntryType::Expense->direction());
        $this->assertSame(-1, FinanceEntryType::TransferOut->direction());
        $this->assertSame(1, FinanceEntryType::TransferIn->direction());
        $this->assertNull(FinanceEntryType::Reversal->direction());
    }

    public function test_finance_records_are_isolated_per_organization(): void
    {
        $this->entry();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        FinanceCategory::create(['organization_id' => $other->id, 'name' => 'Kira', 'direction' => CategoryDirection::Expense, 'status' => 'active']);

        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, FinanceAccount::count());
        $this->assertSame(0, FinanceEntry::count());
        $this->assertSame(1, FinanceCategory::count());
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=FinanceModelsTest`
Expected: FAIL — sınıf bulunamadı

- [ ] **Step 3: Uygula**

Migration `database/migrations/2026_10_14_100000_create_finance_core_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 Finans çekirdeği (Aşama 34). Tutarlar kuruş cinsinden bigint.
 * finance_entries yalnızca eklenen bir defterdir; bakiye hiçbir tabloda saklanmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('iban', 34)->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_date');
            $table->string('currency', 3)->default('TRY');
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('direction', 16);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'direction', 'name']);
        });

        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->string('type', 24);
            $table->smallInteger('direction');
            $table->bigInteger('amount');
            $table->date('occurred_on');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->uuid('transfer_group')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('finance_entries');
            $table->string('description', 500)->nullable();
            $table->string('document_no', 100)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'account_id', 'occurred_on']);
            $table->index(['organization_id', 'occurred_on']);
            $table->index('transfer_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
```

`app/Domain/Finance/Support/AccountType.php`:

```php
<?php

namespace App\Domain\Finance\Support;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Pos = 'pos';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kasa',
            self::Bank => 'Banka',
            self::Pos => 'POS',
        };
    }
}
```

`app/Domain/Finance/Support/CategoryDirection.php`:

```php
<?php

namespace App\Domain\Finance\Support;

enum CategoryDirection: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Gelir',
            self::Expense => 'Gider',
        };
    }
}
```

`app/Domain/Finance/Support/FinanceEntryType.php`:

```php
<?php

namespace App\Domain\Finance\Support;

enum FinanceEntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Gelir',
            self::Expense => 'Gider',
            self::TransferOut => 'Transfer (Çıkan)',
            self::TransferIn => 'Transfer (Giren)',
            self::Reversal => 'İptal',
        };
    }

    /**
     * Hesaba etkisinin işareti. İptal kaydının yönü sabit değildir: iptal
     * ettiği kaydın tersidir (LedgerService::reverse).
     */
    public function direction(): ?int
    {
        return match ($this) {
            self::Income, self::TransferIn => 1,
            self::Expense, self::TransferOut => -1,
            self::Reversal => null,
        };
    }
}
```

`app/Domain/Finance/Exceptions/FinanceRuleException.php`:

```php
<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Finans iş kuralı ihlali; mesajı kullanıcıya olduğu gibi gösterilir.
 */
class FinanceRuleException extends RuntimeException {}
```

`app/Domain/Finance/Models/FinanceAccount.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kasa, banka veya POS hesabı. Bakiye saklanmaz: açılış bakiyesi + defter
 * (FinanceBalanceService). Hesap silinmez, pasife alınır (karar 1.5).
 */
class FinanceAccount extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'iban',
        'opening_balance',
        'opening_date',
        'currency',
        'status',
    ];

    protected $attributes = [
        'currency' => 'TRY',
        'status' => 'active',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'opening_balance' => 'integer',
        'opening_date' => 'date',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'account_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

`app/Domain/Finance/Models/FinanceCategory.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'direction',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'direction' => CategoryDirection::class,
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

`app/Domain/Finance/Models/FinanceEntry.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Finans defterinin bir satırı (karar 1.7). Yalnızca LedgerService yazar;
 * kaydedilmiş satır değiştirilemez ve silinemez — yanlış kayıt ters kayıtla
 * (Reversal) iptal edilir. Defter kendi denetim izi olduğu için Auditable yok.
 */
class FinanceEntry extends Model
{
    use BelongsToOrganization;

    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'account_id',
        'type',
        'direction',
        'amount',
        'occurred_on',
        'category_id',
        'branch_id',
        'transfer_group',
        'reverses_entry_id',
        'description',
        'document_no',
        'actor_id',
    ];

    protected $casts = [
        'type' => FinanceEntryType::class,
        'direction' => 'integer',
        'amount' => 'integer',
        'occurred_on' => 'date',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finans hareketi değiştirilemez; ters kayıtla iptal edin.'));
        static::deleting(fn () => throw new LogicException('Finans hareketi silinemez; ters kayıtla iptal edin.'));
    }

    public function signedAmount(): int
    {
        return $this->direction * $this->amount;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Bu kaydı iptal eden ters kayıt (varsa).
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * Bu bir ters kayıtsa, iptal ettiği asıl kayıt.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function scopeNotReversed(Builder $query): void
    {
        $query->whereDoesntHave('reversal');
    }
}
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test --filter=FinanceModelsTest`
Expected: PASS (5 test)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_10_14_100000_create_finance_core_tables.php app/Domain/Finance tests/Feature/Finance
git commit -m "feat: Finans tabloları ve modelleri — hesap, kategori, değişmez defter — Aşama 34 (2/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Finans yetki modülü

**Files:**
- Modify: `app/Domain/Access/Support/Module.php` (case + label)
- Create: `app/Domain/Finance/Policies/FinancePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (`MODULE_POLICIES`)
- Modify: `app/Domain/Access/Services/StaffService.php` (`createStaff` başı)
- Modify: `resources/views/pages/access/⚡staff.blade.php:97` (`catch` listesine `StaffRuleException`)
- Test: `tests/Feature/Finance/FinancePermissionTest.php`

**Interfaces:**
- Produces: `Module::Finance` (`'finance'`, etiket `'Finans (yalnızca tüm şubeler kapsamı)'`); yetenekler `finance.viewAny`, `finance.create`, `finance.update`, `finance.delete`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Exceptions\StaffRuleException;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Services\StaffService;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FinancePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
    }

    private function staff(array $abilities = []): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        if ($abilities !== []) {
            Permission::create([
                'user_id' => $staff->id, 'module' => Module::Finance->value,
                'can_read' => $abilities['read'] ?? false, 'can_write' => $abilities['write'] ?? false, 'can_delete' => false,
                'scope' => PermissionScope::All->value,
            ]);
        }

        return $staff;
    }

    public function test_admin_has_finance_abilities(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->assertTrue(Gate::forUser($admin)->allows('finance.viewAny'));
        $this->assertTrue(Gate::forUser($admin)->allows('finance.create'));
    }

    public function test_staff_without_finance_permission_is_denied(): void
    {
        $this->assertFalse(Gate::forUser($this->staff())->allows('finance.viewAny'));
    }

    public function test_staff_with_read_only_finance_cannot_write(): void
    {
        $staff = $this->staff(['read' => true]);

        $this->assertTrue(Gate::forUser($staff)->allows('finance.viewAny'));
        $this->assertFalse(Gate::forUser($staff)->allows('finance.create'));
        $this->assertFalse(Gate::forUser($staff)->allows('finance.update'));
    }

    public function test_finance_cannot_be_granted_with_branch_limited_scope(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $this->expectException(StaffRuleException::class);
        $this->expectExceptionMessage('Finans yetkisi yalnızca "Tüm şubeler" kapsamıyla verilebilir.');

        app(StaffService::class)->createStaff(
            ['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'gizli-sifre', 'branch_id' => $this->branch->id],
            [Module::Finance->value => ['read' => true, 'write' => false, 'delete' => false]],
            PermissionScope::OwnBranch,
        );
    }

    public function test_finance_can_be_granted_with_all_branches_scope(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $staff = app(StaffService::class)->createStaff(
            ['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'gizli-sifre', 'branch_id' => $this->branch->id],
            [Module::Finance->value => ['read' => true, 'write' => true, 'delete' => false]],
            PermissionScope::All,
        );

        $this->assertTrue(Gate::forUser($staff->fresh())->allows('finance.create'));
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=FinancePermissionTest`
Expected: FAIL — `Undefined constant App\Domain\Access\Support\Module::Finance`

- [ ] **Step 3: Uygula**

`Module.php` — `case SystemSettings` satırından sonra `case Finance = 'finance';`, `label()` içine:

```php
            self::Finance => 'Finans (yalnızca tüm şubeler kapsamı)',
```

`app/Domain/Finance/Policies/FinancePolicy.php`:

```php
<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

/**
 * Finans organizasyon genelidir (karar 2.9): şube kapsamı yoktur, bu yüzden
 * personele yalnızca "tüm şubeler" kapsamıyla verilebilir (StaffService).
 * İptal kaydı silme değil düzeltmedir; ekranlar iptal için finance.update ister.
 */
class FinancePolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::Finance;
    }
}
```

`AuthServiceProvider.php` — `use App\Domain\Finance\Policies\FinancePolicy;` ekle, `MODULE_POLICIES` sonuna:

```php
        Module::Finance->value => FinancePolicy::class,
```

`StaffService::createStaff` — `return DB::transaction(` satırından önce:

```php
        // Finans organizasyon genelidir (karar 2.9): şubeyle sınırlı bir kapsamda
        // finans yetkisi, kapsam dışı şubelerin parasını da göstermek demek olurdu.
        $grantsFinance = in_array(true, $modulePermissions[Module::Finance->value] ?? [], true);

        if ($grantsFinance && $scope !== PermissionScope::All) {
            throw new StaffRuleException('Finans yetkisi yalnızca "Tüm şubeler" kapsamıyla verilebilir.');
        }

```

`⚡staff.blade.php` satır 97: `} catch (PlanLimitException $e) {` → `} catch (StaffRuleException|PlanLimitException $e) {` (`StaffRuleException` dosyada zaten `use` ediliyor).

- [ ] **Step 4: Yeşil + regresyon**

Run: `php artisan test --filter=FinancePermissionTest && php artisan test tests/Feature/Access`
Expected: PASS; mevcut personel testleri de geçer (personel formu `Module::cases()` ile yeni satırı kendiliğinden gösterir).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Access app/Domain/Finance/Policies app/Providers/AuthServiceProvider.php "resources/views/pages/access/⚡staff.blade.php" tests/Feature/Finance/FinancePermissionTest.php
git commit -m "feat: Finans yetki modülü — yalnızca tüm şubeler kapsamıyla verilebilir — Aşama 34 (3/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: LedgerService — gelir, gider ve bakiye

**Files:**
- Create: `app/Domain/Finance/Services/LedgerService.php`
- Create: `app/Domain/Finance/Services/FinanceBalanceService.php`
- Test: `tests/Feature/Finance/LedgerServiceTest.php`

**Interfaces:**
- Consumes: Task 1–2 sınıfları.
- Produces:
  - `LedgerService::income(FinanceAccount $account, int $amount, CarbonInterface $date, FinanceCategory $category, ?User $actor = null, ?string $description = null, ?Branch $branch = null, ?string $documentNo = null): FinanceEntry`
  - `LedgerService::expense(...)` aynı imza
  - `FinanceBalanceService::balance(FinanceAccount $account, ?CarbonInterface $asOf = null): int`
  - `FinanceBalanceService::balances(iterable $accounts): array<int, int>` (hesap id → bakiye)
  - `FinanceBalanceService::total(): int` (aktif hesapların toplamı)

- [ ] **Step 1: Failing test** (sonraki görevler bu dosyaya test ekler)

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private FinanceBalanceService $balances;

    private Organization $organization;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private FinanceCategory $revenue;

    private FinanceCategory $rent;

    private FinanceCategory $commission;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));

        $this->ledger = app(LedgerService::class);
        $this->balances = app(FinanceBalanceService::class);

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $this->commission = FinanceCategory::create(['name' => 'Banka/POS Komisyonu', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_income_and_expense_change_the_balance(): void
    {
        $this->ledger->income($this->cash, 250_000, $this->day('2026-10-10'), $this->revenue, $this->actor, 'Günlük hasılat');
        $this->ledger->expense($this->cash, 100_000, $this->day('2026-10-11'), $this->rent, $this->actor);

        $this->assertSame(1_150_000, $this->balances->balance($this->cash));
        $this->assertSame(1_250_000, $this->balances->balance($this->cash, $this->day('2026-10-10')));
    }

    public function test_income_records_all_fields(): void
    {
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);

        $entry = $this->ledger->income($this->cash, 5_000, $this->day('2026-10-14'), $this->revenue, $this->actor, 'Not', $branch, 'F-1');

        $this->assertSame(FinanceEntryType::Income, $entry->type);
        $this->assertSame(1, $entry->direction);
        $this->assertSame($this->organization->id, $entry->organization_id);
        $this->assertSame($branch->id, $entry->branch_id);
        $this->assertSame('F-1', $entry->document_no);
        $this->assertSame($this->actor->id, $entry->actor_id);
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->income($this->cash, 0, $this->day('2026-10-14'), $this->revenue, $this->actor);
    }

    public function test_category_direction_must_match(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gelir kategorisi seçin');

        $this->ledger->income($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_future_date_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('İleri tarihli');

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-15'), $this->rent, $this->actor);
    }

    public function test_date_before_opening_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('açılış tarihinden önce');

        $this->ledger->expense($this->cash, 100, $this->day('2026-09-30'), $this->rent, $this->actor);
    }

    public function test_inactive_account_or_category_is_rejected(): void
    {
        $this->cash->update(['status' => 'passive']);

        $this->expectException(FinanceRuleException::class);

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_inactive_category_is_rejected(): void
    {
        $this->rent->update(['status' => 'passive']);

        $this->expectException(FinanceRuleException::class);

        $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
    }

    public function test_negative_balance_is_allowed(): void
    {
        $this->ledger->expense($this->bank, 50_000, $this->day('2026-10-14'), $this->rent, $this->actor);

        $this->assertSame(-50_000, $this->balances->balance($this->bank));
    }

    public function test_balances_and_total(): void
    {
        $this->ledger->income($this->bank, 30_000, $this->day('2026-10-14'), $this->revenue, $this->actor);
        $passive = FinanceAccount::create(['name' => 'Eski', 'type' => AccountType::Cash, 'opening_balance' => 99_999, 'opening_date' => '2026-01-01', 'status' => 'passive']);

        $this->assertSame([$this->cash->id => 1_000_000, $this->bank->id => 30_000], $this->balances->balances([$this->cash, $this->bank]));
        $this->assertSame(1_030_000, $this->balances->total());
        $this->assertSame(99_999, $this->balances->balance($passive));
    }

    public function test_entries_are_written_only_inside_one_transaction_per_call(): void
    {
        $this->ledger->income($this->cash, 1, $this->day('2026-10-14'), $this->revenue, $this->actor);

        $this->assertSame(1, FinanceEntry::count());
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=LedgerServiceTest`
Expected: FAIL — `LedgerService` bulunamadı

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Services/FinanceBalanceService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bakiye hiçbir tabloda saklanmaz (karar 1.7): hesap bakiyesi = açılış bakiyesi
 * + defterdeki işaretli tutarların toplamı. Tüm ekranlar ve raporlar bakiyeyi
 * buradan alır; ikinci bir hesaplama yolu açılmaz.
 */
class FinanceBalanceService
{
    public function balance(FinanceAccount $account, ?CarbonInterface $asOf = null): int
    {
        $sum = FinanceEntry::where('account_id', $account->id)
            ->when($asOf, fn ($query) => $query->whereDate('occurred_on', '<=', $asOf->toDateString()))
            ->sum(DB::raw('direction * amount'));

        return $account->opening_balance + (int) $sum;
    }

    /**
     * @param  iterable<FinanceAccount>  $accounts
     * @return array<int, int> hesap id → bakiye
     */
    public function balances(iterable $accounts): array
    {
        $accounts = collect($accounts);

        $sums = FinanceEntry::whereIn('account_id', $accounts->pluck('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(direction * amount) as total')
            ->pluck('total', 'account_id');

        return $accounts->mapWithKeys(fn (FinanceAccount $account) => [
            $account->id => $account->opening_balance + (int) ($sums[$account->id] ?? 0),
        ])->all();
    }

    /**
     * Aktif hesaplardaki toplam nakit.
     */
    public function total(): int
    {
        return array_sum($this->balances(FinanceAccount::where('status', 'active')->get()));
    }
}
```

`app/Domain/Finance/Services/LedgerService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finans defterinin tek kapısı (karar 1.7 — stoktaki StockMovementService'in
 * finanstaki karşılığı). finance_entries tablosuna başka hiçbir sınıf yazmaz.
 *
 * Kurallar: tutar > 0, yönü türü belirler; pasif hesap/kategoriye yazılmaz;
 * ileri tarihli ve hesabın açılışından önceki tarihli hareket yazılmaz; eksi
 * bakiyeye izin verilir (ek hesaplı banka gerçekliği), uyarıyı ekran gösterir.
 */
class LedgerService
{
    public function income(
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor = null,
        ?string $description = null,
        ?Branch $branch = null,
        ?string $documentNo = null,
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Income, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo);
    }

    public function expense(
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor = null,
        ?string $description = null,
        ?Branch $branch = null,
        ?string $documentNo = null,
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Expense, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo);
    }

    private function categorized(
        FinanceEntryType $type,
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor,
        ?string $description,
        ?Branch $branch,
        ?string $documentNo,
    ): FinanceEntry {
        $this->ensurePositive($amount);
        $this->ensureWritable($account, $date);
        $this->ensureCategory($category, $type === FinanceEntryType::Income ? CategoryDirection::Income : CategoryDirection::Expense);

        return DB::transaction(fn () => $this->record($account, $type, $type->direction(), $amount, $date, $actor, [
            'category_id' => $category->id,
            'branch_id' => $branch?->id,
            'description' => $this->clean($description),
            'document_no' => $this->clean($documentNo),
        ]));
    }

    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.');
        }
    }

    private function ensureWritable(FinanceAccount $account, CarbonInterface $date): void
    {
        if (! $account->isActive()) {
            throw new FinanceRuleException("{$account->name} pasif bir hesap; hareket yazılamaz.");
        }

        if ($date->toDateString() > FinanceCalendar::today()->toDateString()) {
            throw new FinanceRuleException('İleri tarihli hareket yazılamaz; gerçekleşmemiş ödeme hareket değildir.');
        }

        if ($date->toDateString() < $account->opening_date->toDateString()) {
            throw new FinanceRuleException("{$account->name} hesabının açılış tarihinden önce hareket yazılamaz.");
        }
    }

    private function ensureCategory(FinanceCategory $category, CategoryDirection $direction): void
    {
        if (! $category->isActive()) {
            throw new FinanceRuleException("{$category->name} pasif bir kategori.");
        }

        if ($category->direction !== $direction) {
            throw new FinanceRuleException('Bu hareket için bir '.mb_strtolower($direction->label()).' kategorisi seçin.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(FinanceAccount $account, FinanceEntryType $type, int $direction, int $amount, CarbonInterface $date, ?User $actor, array $attributes = []): FinanceEntry
    {
        return FinanceEntry::create([
            'organization_id' => $account->organization_id,
            'account_id' => $account->id,
            'type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'occurred_on' => $date->toDateString(),
            'actor_id' => $actor?->id,
            ...$attributes,
        ]);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test --filter=LedgerServiceTest`
Expected: PASS (11 test)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Finance/Services tests/Feature/Finance/LedgerServiceTest.php
git commit -m "feat: LedgerService gelir/gider ve türetilen hesap bakiyesi — Aşama 34 (4/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: LedgerService — transfer ve iptal

**Files:**
- Modify: `app/Domain/Finance/Services/LedgerService.php`
- Test: `tests/Feature/Finance/LedgerServiceTest.php` (testleri sınıfın sonuna ekle)

**Interfaces:**
- Produces:
  - `LedgerService::transfer(FinanceAccount $from, FinanceAccount $to, int $amount, CarbonInterface $date, ?User $actor = null, ?string $description = null, int $commission = 0, ?FinanceCategory $commissionCategory = null): Collection<int, FinanceEntry>` — çıkan ayak, giren ayak, varsa hedef hesaptan komisyon gideri; hepsi aynı `transfer_group`.
  - `LedgerService::reverse(FinanceEntry $entry, string $reason, ?User $actor = null): Collection<int, FinanceEntry>` — oluşturulan ters kayıtlar; transfer grubundaki tüm asıl kayıtlar birlikte iptal edilir; iptal kaydı `FinanceCalendar::today()` tarihlidir.

- [ ] **Step 1: Failing tests** (`LedgerServiceTest` içine; `use Illuminate\Support\Collection;` gerekmez)

```php
    public function test_transfer_moves_money_between_accounts_atomically(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 300_000, $this->day('2026-10-14'), $this->actor, 'Bankaya yatırıldı');

        $this->assertCount(2, $entries);
        $this->assertSame(700_000, $this->balances->balance($this->cash));
        $this->assertSame(300_000, $this->balances->balance($this->bank));
        $this->assertSame(1, $entries->pluck('transfer_group')->unique()->count());
        $this->assertNotNull($entries->first()->transfer_group);
    }

    public function test_transfer_with_commission_records_expense_on_target(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, 'POS günsonu', 2_500, $this->commission);

        $this->assertCount(3, $entries);
        $this->assertSame(97_500, $this->balances->balance($this->bank));
        $fee = $entries->firstWhere('type', FinanceEntryType::Expense);
        $this->assertSame($this->bank->id, $fee->account_id);
        $this->assertSame($this->commission->id, $fee->category_id);
    }

    public function test_commission_requires_expense_category(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, null, 2_500);
    }

    public function test_transfer_to_same_account_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->ledger->transfer($this->cash, $this->cash, 100, $this->day('2026-10-14'), $this->actor);
    }

    public function test_reverse_restores_balance_and_is_dated_today(): void
    {
        $expense = $this->ledger->expense($this->cash, 100_000, $this->day('2026-10-02'), $this->rent, $this->actor);

        $reversals = $this->ledger->reverse($expense, 'Yanlış tutar', $this->actor);

        $this->assertSame(1_000_000, $this->balances->balance($this->cash));
        $reversal = $reversals->first();
        $this->assertSame(FinanceEntryType::Reversal, $reversal->type);
        $this->assertSame(1, $reversal->direction);
        $this->assertSame($expense->id, $reversal->reverses_entry_id);
        $this->assertSame($this->rent->id, $reversal->category_id);
        $this->assertSame('2026-10-14', $reversal->occurred_on->toDateString());
        $this->assertStringContainsString('Yanlış tutar', $reversal->description);
        $this->assertSame(900_000, $this->balances->balance($this->cash, $this->day('2026-10-13')));
    }

    public function test_reversing_one_leg_reverses_the_whole_transfer(): void
    {
        $entries = $this->ledger->transfer($this->cash, $this->bank, 100_000, $this->day('2026-10-14'), $this->actor, null, 2_500, $this->commission);

        $reversals = $this->ledger->reverse($entries->last(), 'Yanlış hesap', $this->actor);

        $this->assertCount(3, $reversals);
        $this->assertSame(1_000_000, $this->balances->balance($this->cash));
        $this->assertSame(0, $this->balances->balance($this->bank));
    }

    public function test_entry_cannot_be_reversed_twice(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
        $this->ledger->reverse($expense, 'İlk', $this->actor);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('zaten iptal');

        $this->ledger->reverse($expense->fresh(), 'İkinci', $this->actor);
    }

    public function test_reversal_itself_cannot_be_reversed(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);
        $reversal = $this->ledger->reverse($expense, 'İlk', $this->actor)->first();

        $this->expectException(FinanceRuleException::class);

        $this->ledger->reverse($reversal, 'Geri al', $this->actor);
    }

    public function test_reverse_requires_reason(): void
    {
        $expense = $this->ledger->expense($this->cash, 100, $this->day('2026-10-14'), $this->rent, $this->actor);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gerekçe');

        $this->ledger->reverse($expense, '   ', $this->actor);
    }
```

> Not: `test_reverse_restores_balance_and_is_dated_today` içindeki son satır, kapanmış günün (13 Ekim) bakiyesinin iptalden etkilenmediğini doğrular: 13 Ekim itibarıyla kasa 900.000 kuruş kalır (gider 2 Ekim'de, iptali 14 Ekim'de).

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=LedgerServiceTest`
Expected: FAIL — `Call to undefined method ...LedgerService::transfer()`

- [ ] **Step 3: Uygula** (`LedgerService` içine, `categorized()` metodundan önce; dosya başına `use Illuminate\Support\Collection;` ve `use Illuminate\Support\Str;`)

```php
    /**
     * Hesaplar arası transfer: iki ayak aynı transfer_group ile yazılır. POS'tan
     * bankaya geçişte banka net tutarı yatırır; komisyon verilirse hedef
     * hesaptan ayrıca gider olarak düşülür (aynı gruba bağlı).
     *
     * @return Collection<int, FinanceEntry>
     */
    public function transfer(
        FinanceAccount $from,
        FinanceAccount $to,
        int $amount,
        CarbonInterface $date,
        ?User $actor = null,
        ?string $description = null,
        int $commission = 0,
        ?FinanceCategory $commissionCategory = null,
    ): Collection {
        $this->ensurePositive($amount);

        if ($from->is($to)) {
            throw new FinanceRuleException('Kaynak ve hedef hesap aynı olamaz.');
        }

        $this->ensureWritable($from, $date);
        $this->ensureWritable($to, $date);

        if ($commission < 0) {
            throw new FinanceRuleException('Komisyon eksi olamaz.');
        }

        if ($commission > 0) {
            if ($commissionCategory === null) {
                throw new FinanceRuleException('Komisyon için bir gider kategorisi seçin.');
            }

            $this->ensureCategory($commissionCategory, CategoryDirection::Expense);
        }

        $common = ['transfer_group' => (string) Str::uuid(), 'description' => $this->clean($description)];

        return DB::transaction(function () use ($from, $to, $amount, $date, $actor, $commission, $commissionCategory, $common) {
            $entries = collect([
                $this->record($from, FinanceEntryType::TransferOut, -1, $amount, $date, $actor, $common),
                $this->record($to, FinanceEntryType::TransferIn, 1, $amount, $date, $actor, $common),
            ]);

            if ($commission > 0) {
                $entries->push($this->record($to, FinanceEntryType::Expense, -1, $commission, $date, $actor, [
                    ...$common,
                    'category_id' => $commissionCategory->id,
                ]));
            }

            return $entries;
        });
    }

    /**
     * Yanlış kaydı ters kayıtla iptal eder (karar 1.5 — silme yok). Transferin
     * herhangi bir ayağı iptal edilirse grubun tüm kayıtları birlikte iptal
     * edilir; aksi hâlde para bir hesaptan çıkmış, diğerine girmemiş görünür.
     * İptal, iptal günü tarihlenir: kapanmış dönemin raporu sonradan değişmez.
     *
     * @return Collection<int, FinanceEntry>
     */
    public function reverse(FinanceEntry $entry, string $reason, ?User $actor = null): Collection
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceRuleException('İptal için bir gerekçe yazın.');
        }

        if ($entry->type === FinanceEntryType::Reversal) {
            throw new FinanceRuleException('İptal kaydı iptal edilemez; gerekiyorsa yeni bir hareket girin.');
        }

        $targets = $entry->transfer_group
            ? FinanceEntry::where('transfer_group', $entry->transfer_group)->where('type', '!=', FinanceEntryType::Reversal)->with('account')->get()
            : collect([$entry->loadMissing('account')]);

        if ($targets->contains(fn (FinanceEntry $target) => $target->reversal()->exists())) {
            throw new FinanceRuleException('Bu hareket zaten iptal edilmiş.');
        }

        $today = FinanceCalendar::today();
        $targets->each(fn (FinanceEntry $target) => $this->ensureWritable($target->account, $today));

        // reverses_entry_id tekil olduğu için eşzamanlı ikinci iptal veritabanında reddedilir.
        return DB::transaction(fn () => $targets->map(fn (FinanceEntry $target) => $this->record(
            $target->account,
            FinanceEntryType::Reversal,
            -$target->direction,
            $target->amount,
            $today,
            $actor,
            [
                'category_id' => $target->category_id,
                'branch_id' => $target->branch_id,
                'reverses_entry_id' => $target->id,
                'description' => "İptal: #{$target->id} — {$reason}",
            ],
        )));
    }
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test --filter=LedgerServiceTest`
Expected: PASS (20 test)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Finance/Services/LedgerService.php tests/Feature/Finance/LedgerServiceTest.php
git commit -m "feat: LedgerService transfer (komisyonlu) ve ters kayıtla iptal — Aşama 34 (5/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Hesap ve kategori servisleri

**Files:**
- Create: `app/Domain/Finance/Services/FinanceAccountService.php`
- Create: `app/Domain/Finance/Services/FinanceCategoryService.php`
- Test: `tests/Feature/Finance/FinanceSetupServicesTest.php`

**Interfaces:**
- Produces:
  - `FinanceAccountService::create(array{name: string, type: AccountType, iban?: ?string, opening_balance: int, opening_date: CarbonInterface}): FinanceAccount` — açılış tarihi bugünden sonra olamaz; IBAN boşlukları silinip büyük harfe çevrilir.
  - `FinanceAccountService::deactivate(FinanceAccount): FinanceAccount`
  - `FinanceCategoryService::create(string $name, CategoryDirection $direction): FinanceCategory` — aynı yön ve adda kategori varsa `FinanceRuleException`.
  - `FinanceCategoryService::deactivate(FinanceCategory): FinanceCategory`
  - `FinanceCategoryService::addSuggested(): int` — eksik önerilen kategorileri ekler, eklenen sayıyı döner.
  - `FinanceCategoryService::SUGGESTED` sabiti.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceAccountService;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceSetupServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_account_is_created_with_normalized_iban(): void
    {
        $account = app(FinanceAccountService::class)->create([
            'name' => 'Ziraat', 'type' => AccountType::Bank, 'iban' => 'tr12 0001 0000 0000 0000 0000 01',
            'opening_balance' => -50_000, 'opening_date' => CarbonImmutable::parse('2026-10-01'),
        ]);

        $this->assertSame('TR120001000000000000000001', $account->iban);
        $this->assertSame(-50_000, $account->opening_balance);
        $this->assertTrue($account->isActive());
    }

    public function test_account_opening_date_cannot_be_in_future(): void
    {
        $this->expectException(FinanceRuleException::class);

        app(FinanceAccountService::class)->create([
            'name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-15'),
        ]);
    }

    public function test_account_can_be_deactivated(): void
    {
        $service = app(FinanceAccountService::class);
        $account = $service->create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => CarbonImmutable::parse('2026-10-01')]);

        $this->assertFalse($service->deactivate($account)->isActive());
    }

    public function test_duplicate_category_is_rejected_per_direction(): void
    {
        $service = app(FinanceCategoryService::class);
        $service->create('Diğer', CategoryDirection::Income);
        $service->create('Diğer', CategoryDirection::Expense);

        $this->expectException(FinanceRuleException::class);

        $service->create(' Diğer ', CategoryDirection::Income);
    }

    public function test_suggested_categories_are_added_once(): void
    {
        $service = app(FinanceCategoryService::class);
        $service->create('Kira', CategoryDirection::Expense);

        $added = $service->addSuggested();

        $this->assertSame(count(FinanceCategoryService::SUGGESTED) - 1, $added);
        $this->assertSame(0, $service->addSuggested());
        $this->assertSame(1, FinanceCategory::where('name', 'Kira')->count());
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=FinanceSetupServicesTest`
Expected: FAIL — servis sınıfı yok

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Services/FinanceAccountService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceCalendar;
use Carbon\CarbonInterface;

/**
 * Hesap tanımı. Açılış bakiyesi eksi olabilir (ek hesaplı banka). Açılış
 * bakiyesi ve tarihi sonradan değiştirilmez — değişirse geçmiş tüm bakiyeler
 * değişir; yanlış açılış mutabakat düzeltmesiyle kapatılır (Aşama 36).
 */
class FinanceAccountService
{
    /**
     * @param  array{name: string, type: AccountType, iban?: ?string, opening_balance: int, opening_date: CarbonInterface}  $data
     */
    public function create(array $data): FinanceAccount
    {
        if ($data['opening_date']->toDateString() > FinanceCalendar::today()->toDateString()) {
            throw new FinanceRuleException('Açılış tarihi bugünden sonra olamaz.');
        }

        $iban = strtoupper(str_replace(' ', '', (string) ($data['iban'] ?? '')));

        return FinanceAccount::create([
            'name' => trim($data['name']),
            'type' => $data['type'],
            'iban' => $iban === '' ? null : $iban,
            'opening_balance' => $data['opening_balance'],
            'opening_date' => $data['opening_date']->toDateString(),
        ]);
    }

    public function deactivate(FinanceAccount $account): FinanceAccount
    {
        $account->update(['status' => 'passive']);

        return $account;
    }
}
```

`app/Domain/Finance/Services/FinanceCategoryService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Support\CategoryDirection;

class FinanceCategoryService
{
    /**
     * Önerilen başlangıç kategorileri. Organizasyon açılırken eklenmez (Platform
     * domain'i klinik verisine dokunmaz); kullanıcı Kategoriler ekranından ekler.
     *
     * @var array<int, array{0: string, 1: CategoryDirection}>
     */
    public const SUGGESTED = [
        ['Hasılat', CategoryDirection::Income],
        ['Kurum Tahsilatı', CategoryDirection::Income],
        ['Diğer Gelir', CategoryDirection::Income],
        ['Kira', CategoryDirection::Expense],
        ['Maaş', CategoryDirection::Expense],
        ['Malzeme', CategoryDirection::Expense],
        ['Fatura (elektrik/su/internet)', CategoryDirection::Expense],
        ['Vergi/SGK', CategoryDirection::Expense],
        ['Banka/POS Komisyonu', CategoryDirection::Expense],
        ['Diğer Gider', CategoryDirection::Expense],
    ];

    public function create(string $name, CategoryDirection $direction): FinanceCategory
    {
        $name = trim($name);

        if (FinanceCategory::where('direction', $direction)->where('name', $name)->exists()) {
            throw new FinanceRuleException("\"{$name}\" adında bir ".mb_strtolower($direction->label()).' kategorisi zaten var.');
        }

        return FinanceCategory::create(['name' => $name, 'direction' => $direction]);
    }

    public function deactivate(FinanceCategory $category): FinanceCategory
    {
        $category->update(['status' => 'passive']);

        return $category;
    }

    public function addSuggested(): int
    {
        $added = 0;

        foreach (self::SUGGESTED as [$name, $direction]) {
            if (! FinanceCategory::where('direction', $direction)->where('name', $name)->exists()) {
                FinanceCategory::create(['name' => $name, 'direction' => $direction]);
                $added++;
            }
        }

        return $added;
    }
}
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test --filter=FinanceSetupServicesTest`
Expected: PASS (5 test)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Finance/Services tests/Feature/Finance/FinanceSetupServicesTest.php
git commit -m "feat: Finans hesap ve kategori servisleri, önerilen kategoriler — Aşama 34 (6/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Rotalar, menü, sekmeler, Hesaplar ve Kategoriler ekranları

**Önce:** `frontend-design` skill'ini yükle. Mevcut ekranların dilini koru (Kategoriler sayfası: başlık bloğu, `border-line` tablo kartı, `x-modal` form, `bg-panel-900` birincil düğme). Finans ekranları aynı ailedendir; ikinci bir stil sistemi açma.

**Files:**
- Modify: `routes/web.php` (ÜTS rotalarından sonra)
- Modify: `resources/views/layouts/authenticated.blade.php` (ÜTS bağlantısından sonra)
- Modify: `resources/css/app.css` (`@theme` içine finans token'ları)
- Create: `resources/views/components/finance-tabs.blade.php`
- Create: `resources/views/pages/finance/⚡accounts.blade.php`
- Create: `resources/views/pages/finance/⚡categories.blade.php`
- Test: `tests/Feature/Finance/FinanceSetupScreensTest.php`

**Interfaces:**
- Consumes: Task 3 yetenekleri, Task 4 `FinanceBalanceService::balances`, Task 6 servisleri.
- Produces: rotalar `finance.entries` (`/finans/hareketler`), `finance.accounts` (`/finans/hesaplar`), `finance.categories` (`/finans/kategoriler`); bileşen adları `pages::finance.accounts`, `pages::finance.categories`, `pages::finance.entries` (Task 8). Hesaplar bileşeni: `$name, $type, $iban, $openingBalance (string), $openingDate`, metodlar `openForm, closeForm, save, deactivate(int)`. Kategoriler: `$name, $direction`, metodlar `openForm, closeForm, save, deactivate(int), addSuggested`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceSetupScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_creates_account_with_turkish_amount(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.accounts')
            ->call('openForm')
            ->set('name', 'Merkez Kasa')
            ->set('type', 'cash')
            ->set('openingBalance', '10.000,00')
            ->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertHasNoErrors();

        $account = FinanceAccount::firstOrFail();
        $this->assertSame(1_000_000, $account->opening_balance);
        $this->assertSame(AccountType::Cash, $account->type);
    }

    public function test_invalid_amount_shows_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.accounts')
            ->set('name', 'Kasa')->set('type', 'cash')->set('openingBalance', 'on bin')->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertHasErrors('openingBalance');
    }

    public function test_accounts_page_shows_balances(): void
    {
        FinanceAccount::create(['organization_id' => $this->organization->id, 'name' => 'Merkez Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_250_050, 'opening_date' => '2026-10-01']);

        $this->actingAs($this->admin)->get('/finans/hesaplar')->assertOk()->assertSee('Merkez Kasa')->assertSee('12.500,50 ₺');
    }

    public function test_admin_adds_suggested_categories(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.categories')->call('addSuggested');

        $this->assertSame(count(FinanceCategoryService::SUGGESTED), FinanceCategory::count());
    }

    public function test_duplicate_category_shows_error(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.categories')->set('name', 'Kira')->set('direction', 'expense')->call('save')->assertHasNoErrors();
        Livewire::test('pages::finance.categories')->set('name', 'Kira')->set('direction', 'expense')->call('save')->assertHasErrors('name');
    }

    public function test_staff_without_finance_permission_cannot_open_finance_pages(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff);
        $this->get('/finans/hesaplar')->assertForbidden();
        $this->get('/finans/kategoriler')->assertForbidden();
    }

    public function test_read_only_staff_cannot_create_account(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        $this->actingAs($staff)->get('/finans/hesaplar')->assertOk()->assertDontSee('Yeni Hesap');

        Livewire::test('pages::finance.accounts')
            ->set('name', 'Kasa')->set('type', 'cash')->set('openingBalance', '0')->set('openingDate', '2026-10-01')
            ->call('save')
            ->assertForbidden();
    }

    public function test_menu_shows_finance_link_only_with_permission(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee(route('finance.entries'));

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff)->get('/dashboard')->assertDontSee(route('finance.entries'));
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=FinanceSetupScreensTest`
Expected: FAIL — `Route [finance.entries] not defined` / bileşen bulunamadı

- [ ] **Step 3: Uygula**

`routes/web.php` — ÜTS rotalarının hemen ardından, aynı `tenant` grubunun içinde:

```php
    // Faz 5: Finans (ön muhasebe) — organizasyon geneli, şube kapsamı yok (karar 2.9).
    Route::livewire('/finans/hareketler', 'pages::finance.entries')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.entries');

    Route::livewire('/finans/hesaplar', 'pages::finance.accounts')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.accounts');

    Route::livewire('/finans/kategoriler', 'pages::finance.categories')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.categories');
```

`resources/views/layouts/authenticated.blade.php` — ÜTS `@endcan` satırından sonra (ikon: cüzdan):

```blade
                    @can('finance.viewAny')
                        <x-nav-link :href="route('finance.entries')" :active="request()->routeIs('finance.*')">
                            <x-slot:icon><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2Z"/><path d="M16 14h.01"/></x-slot:icon>
                            Finans
                        </x-nav-link>
                    @endcan
```

> Yazmadan önce dosyadaki mevcut bir `x-nav-link`'in ikonu nasıl verdiğine bak (`$icon` slotu) ve aynı biçimi kullan.

`resources/css/app.css` — `@theme` içinde status bloğundan sonra:

```css
    /* Finance: money in / money out. Separate from status-* (stock semantics only, karar 4.2) */
    --color-finance-in: #1f6f8b;
    --color-finance-out: #8a4b2a;
```

`resources/views/components/finance-tabs.blade.php`:

```blade
@php
    $tabs = [
        'finance.entries' => 'Hareketler',
        'finance.accounts' => 'Hesaplar',
        'finance.categories' => 'Kategoriler',
    ];
@endphp

<nav class="mb-6 flex flex-wrap gap-1 border-b border-line">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" wire:navigate
           class="px-3 py-2 text-[14px] -mb-px border-b-2 {{ request()->routeIs($route) ? 'border-brand-500 text-ink font-medium' : 'border-transparent text-ink-muted hover:text-ink' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
```

`resources/views/pages/finance/⚡accounts.blade.php`:

```blade
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Services\FinanceAccountService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $type = 'cash';

    public string $iban = '';

    public string $openingBalance = '0';

    public string $openingDate = '';

    public function openForm(): void
    {
        Gate::authorize('finance.create');

        $this->openingDate = FinanceCalendar::today()->toDateString();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('name', 'type', 'iban', 'openingBalance', 'openingDate');
        $this->resetValidation();
    }

    public function save(FinanceAccountService $accounts): void
    {
        Gate::authorize('finance.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'iban' => ['nullable', 'string', 'max:40'],
            'openingBalance' => ['required', 'string'],
            'openingDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $openingBalance = Money::parse($validated['openingBalance']);
        } catch (InvalidArgumentException $e) {
            $this->addError('openingBalance', $e->getMessage());

            return;
        }

        try {
            $accounts->create([
                'name' => $validated['name'],
                'type' => AccountType::from($validated['type']),
                'iban' => $validated['iban'],
                'opening_balance' => $openingBalance,
                'opening_date' => CarbonImmutable::parse($validated['openingDate']),
            ]);
        } catch (FinanceRuleException $e) {
            $this->addError('openingDate', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Hesap oluşturuldu.');
    }

    public function deactivate(int $accountId, FinanceAccountService $accounts): void
    {
        Gate::authorize('finance.update');

        $accounts->deactivate(FinanceAccount::findOrFail($accountId));

        session()->flash('status', 'Hesap pasife alındı; geçmiş hareketleri korunur.');
    }

    public function with(FinanceBalanceService $balances): array
    {
        $accounts = FinanceAccount::orderByRaw("status = 'active' desc")->orderBy('name')->get();
        $balanceMap = $balances->balances($accounts);

        return [
            'accounts' => $accounts,
            'balances' => $balanceMap,
            'total' => $accounts->where('status', 'active')->sum(fn ($account) => $balanceMap[$account->id]),
            'types' => AccountType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kasa, banka ve POS hesapların. Bakiye, açılış bakiyesi ve girilen hareketlerden hesaplanır.</p>
        </div>
        @can('finance.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Hesap
            </button>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-6 flex items-baseline gap-3">
        <span class="text-[13px] text-ink-muted">Toplam nakit (aktif hesaplar)</span>
        <span @class(['text-[22px] font-medium tabular-nums', 'text-ink' => $total >= 0, 'text-finance-out' => $total < 0])>{{ \App\Domain\Finance\Support\Money::format($total) }}</span>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Hesap</th>
                    <th class="px-5 py-3 font-medium">Tür</th>
                    <th class="px-5 py-3 font-medium">Açılış</th>
                    <th class="px-5 py-3 font-medium text-right">Bakiye</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($accounts as $account)
                    <tr wire:key="account-{{ $account->id }}" @class(['text-ink-muted' => ! $account->isActive()])>
                        <td class="px-5 py-3">
                            <div class="font-medium">{{ $account->name }}</div>
                            @if ($account->iban)
                                <div class="text-[12px] text-ink-muted font-mono">{{ $account->iban }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            {{ $account->type->label() }}
                            @unless ($account->isActive())
                                <span class="ml-1 inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                            @endunless
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-ink-muted">{{ $account->opening_date->format('d.m.Y') }}</td>
                        <td @class(['px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-out' => $balances[$account->id] < 0])>
                            {{ \App\Domain\Finance\Support\Money::format($balances[$account->id]) }}
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @can('finance.update')
                                @if ($account->isActive())
                                    <button wire:click="deactivate({{ $account->id }})" wire:confirm="Bu hesap pasife alınsın mı? Yeni hareket yazılamaz, geçmişi korunur." class="text-[13px] text-ink-muted hover:text-ink hover:underline">Pasife al</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-ink-muted text-[13px]">Henüz hesap yok. Önce bir kasa veya banka hesabı tanımla.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" title="Yeni Hesap" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" placeholder="Örn. Merkez Kasa, Ziraat TL" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Tür</label>
                <select wire:model.live="type" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($type === 'bank')
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">IBAN (isteğe bağlı)</label>
                    <input type="text" wire:model="iban" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono">
                    @error('iban') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açılış bakiyesi (₺)</label>
                    <input type="text" inputmode="decimal" wire:model="openingBalance" placeholder="10.000,00" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('openingBalance') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    <p class="text-[12px] text-ink-muted mt-1">Ek hesaptaysa eksi yaz (örn. -2.500).</p>
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açılış tarihi</label>
                    <input type="date" wire:model="openingDate" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('openingDate') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-[12px] text-ink-muted">Açılış bakiyesi ve tarihi sonradan değiştirilemez; fark çıkarsa mutabakatla düzeltilir.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
```

`resources/views/pages/finance/⚡categories.blade.php`:

```blade
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\CategoryDirection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $direction = 'expense';

    public function openForm(): void
    {
        Gate::authorize('finance.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('name', 'direction');
        $this->resetValidation();
    }

    public function save(FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::enum(CategoryDirection::class)],
        ]);

        try {
            $categories->create($validated['name'], CategoryDirection::from($validated['direction']));
        } catch (FinanceRuleException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Kategori oluşturuldu.');
    }

    public function addSuggested(FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.create');

        $added = $categories->addSuggested();

        session()->flash('status', $added > 0 ? "{$added} önerilen kategori eklendi." : 'Önerilen kategorilerin hepsi zaten var.');
    }

    public function deactivate(int $categoryId, FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.update');

        $categories->deactivate(FinanceCategory::findOrFail($categoryId));

        session()->flash('status', 'Kategori pasife alındı; geçmiş hareketlerde görünmeye devam eder.');
    }

    public function with(): array
    {
        $all = FinanceCategory::orderByRaw("status = 'active' desc")->orderBy('name')->get();

        return [
            'groups' => [
                CategoryDirection::Income->label() => $all->where('direction', CategoryDirection::Income),
                CategoryDirection::Expense->label() => $all->where('direction', CategoryDirection::Expense),
            ],
            'isEmpty' => $all->isEmpty(),
            'directions' => CategoryDirection::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Gelir ve gider kategorileri. Raporlar bu kategorilere göre toplanır.</p>
        </div>
        @can('finance.create')
            <div class="mt-4 sm:mt-0 flex items-center gap-2">
                <button wire:click="addSuggested" class="inline-flex items-center border border-line bg-surface rounded-md px-4 py-2 text-[14px] text-ink hover:bg-canvas transition-colors">Önerilenleri ekle</button>
                <button wire:click="openForm" class="inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    Yeni Kategori
                </button>
            </div>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if ($isEmpty)
        <div class="border border-dashed border-line rounded-lg bg-surface px-6 py-10 text-center">
            <p class="text-[14px] text-ink">Henüz kategori yok.</p>
            <p class="text-[13px] text-ink-muted mt-1">Kira, Maaş, Malzeme, Hasılat gibi önerilen kategorilerle başlayabilir, sonra kendi kategorilerini ekleyebilirsin.</p>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach ($groups as $label => $categories)
                <section class="border border-line rounded-lg bg-surface">
                    <h2 class="px-5 py-3 border-b border-line text-[13px] font-medium text-ink-muted">{{ $label }} kategorileri</h2>
                    <ul class="divide-y divide-line">
                        @forelse ($categories as $category)
                            <li wire:key="category-{{ $category->id }}" class="px-5 py-3 flex items-center justify-between text-[14px]">
                                <span @class(['text-ink-muted' => ! $category->isActive()])>
                                    {{ $category->name }}
                                    @unless ($category->isActive())
                                        <span class="ml-1 inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                                    @endunless
                                </span>
                                @can('finance.update')
                                    @if ($category->isActive())
                                        <button wire:click="deactivate({{ $category->id }})" wire:confirm="Bu kategori pasife alınsın mı?" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Pasife al</button>
                                    @endif
                                @endcan
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-[13px] text-ink-muted">Bu türde kategori yok.</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    <x-modal :show="$showForm" title="Yeni Kategori" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Tür</label>
                <div class="flex gap-2">
                    @foreach ($directions as $option)
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" wire:model="direction" value="{{ $option->value }}" class="peer sr-only">
                            <span class="block text-center border border-line rounded-md px-3 py-2 text-[14px] peer-checked:border-brand-500 peer-checked:bg-brand-100 peer-checked:text-brand-600">{{ $option->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
```

> Hesaplar sayfasının PHP bloğunda `InvalidArgumentException` global sınıftır; dosyanın `use` listesine `use InvalidArgumentException;` ekle (Pint aksi hâlde uyarabilir).

Hareketler rotası Task 8'e kadar bileşensiz kalacağı için bu görevde `resources/views/pages/finance/⚡entries.blade.php` dosyasını şu geçici içerikle oluştur (Task 8 tamamen değiştirir), menü testi rotayı çözebilsin:

```blade
<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component {};
?>

<div>
    <h1 class="text-[22px] font-medium tracking-tight text-ink mb-6">Finans</h1>
    <x-finance-tabs />
</div>
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test --filter=FinanceSetupScreensTest`
Expected: PASS (8 test)

- [ ] **Step 5: Görsel doğrulama**

```bash
npm run build
```

Yerel sunucu (`public` dizininden PHP yerleşik sunucusu — Aşama 33 dersi) ve başsız Chrome ile `/finans/hesaplar` ve `/finans/kategoriler` sayfalarının masaüstü (1280) ve dar (500) ekran görüntüsünü al; boş durum, dolu tablo, form modalı, eksi bakiye renginin okunaklı olduğunu gözle kontrol et. Sorun varsa düzelt, testleri yeniden çalıştır.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add routes/web.php resources/views/layouts/authenticated.blade.php resources/css/app.css resources/views/components/finance-tabs.blade.php resources/views/pages/finance tests/Feature/Finance/FinanceSetupScreensTest.php
git commit -m "feat: Finans menüsü, Hesaplar ve Kategoriler ekranları — Aşama 34 (7/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Hareketler ekranı

**Önce:** `frontend-design` skill'i (Task 7'de yüklendiyse yeniden gerekmez). Hızlı giriş günlük kullanılan bir iş: üç düğme (Gelir / Gider / Transfer) tek modal, tür seçimine göre alanlar değişir; telefonda da tek elle doldurulabilir olmalı.

**Files:**
- Replace: `resources/views/pages/finance/⚡entries.blade.php`
- Test: `tests/Feature/Finance/FinanceEntriesScreenTest.php`

**Interfaces:**
- Consumes: `LedgerService::income/expense/transfer/reverse`, `FinanceBalanceService::balance`, `Money`.
- Produces: bileşen özellikleri `$formType ('income'|'expense'|'transfer'|null)`, `$accountId`, `$toAccountId`, `$amount`, `$date`, `$categoryId`, `$branchId`, `$description`, `$documentNo`, `$commission`, `$commissionCategoryId`; filtreler `$filterFrom`, `$filterTo`, `$filterAccount`, `$filterType`, `$filterCategory`; iptal `$reversingId`, `$reverseReason`; metodlar `openForm(string $type)`, `closeForm()`, `save()`, `startReverse(int)`, `cancelReverse()`, `confirmReverse()`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceEntriesScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private FinanceCategory $revenue;

    private FinanceCategory $rent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $this->revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_income_is_saved_from_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'income')
            ->assertSet('date', '2026-10-14')
            ->set('accountId', $this->cash->id)
            ->set('amount', '2.500')
            ->set('categoryId', $this->revenue->id)
            ->set('description', 'Günlük hasılat')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1_250_000, app(FinanceBalanceService::class)->balance($this->cash));
    }

    public function test_transfer_is_saved_from_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'transfer')
            ->set('accountId', $this->cash->id)
            ->set('toAccountId', $this->bank->id)
            ->set('amount', '3.000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(300_000, app(FinanceBalanceService::class)->balance($this->bank));
    }

    public function test_service_rule_errors_are_shown_on_the_form(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', $this->cash->id)
            ->set('amount', '100')
            ->set('categoryId', $this->rent->id)
            ->set('date', '2026-10-20')
            ->call('save')
            ->assertHasErrors('date');

        $this->assertSame(0, FinanceEntry::count());
    }

    public function test_negative_balance_warning_is_flashed(): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', $this->bank->id)
            ->set('amount', '500')
            ->set('categoryId', $this->rent->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('eksiye düştü');
    }

    public function test_entry_is_reversed_with_reason(): void
    {
        $entry = app(LedgerService::class)->expense($this->cash, 100_000, CarbonImmutable::parse('2026-10-10'), $this->rent, $this->admin);

        Livewire::test('pages::finance.entries')
            ->call('startReverse', $entry->id)
            ->set('reverseReason', 'Yanlış hesap')
            ->call('confirmReverse')
            ->assertHasNoErrors();

        $this->assertTrue($entry->reversal()->exists());
        $this->assertSame(1_000_000, app(FinanceBalanceService::class)->balance($this->cash));
    }

    public function test_list_filters_by_account_and_type(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->income($this->cash, 11_100, CarbonImmutable::parse('2026-10-10'), $this->revenue, $this->admin, 'Kasa geliri');
        $ledger->income($this->bank, 22_200, CarbonImmutable::parse('2026-10-10'), $this->revenue, $this->admin, 'Banka geliri');

        Livewire::test('pages::finance.entries')
            ->set('filterAccount', (string) $this->bank->id)
            ->assertSee('Banka geliri')
            ->assertDontSee('Kasa geliri')
            ->set('filterAccount', '')
            ->set('filterType', FinanceEntryType::Income->value)
            ->assertSee('Kasa geliri');
    }

    public function test_page_explains_when_no_account_exists(): void
    {
        FinanceEntry::query()->toBase()->delete();
        FinanceAccount::query()->toBase()->delete();

        $this->get('/finans/hareketler')->assertOk()->assertSee('Önce bir kasa veya banka hesabı');
    }
}
```

- [ ] **Step 2: Kırmızı**

Run: `php artisan test --filter=FinanceEntriesScreenTest`
Expected: FAIL — `Unable to call component method [openForm]`

- [ ] **Step 3: Uygula** — `resources/views/pages/finance/⚡entries.blade.php` tamamen:

```blade
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\Money;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public ?string $formType = null;

    public string $accountId = '';

    public string $toAccountId = '';

    public string $amount = '';

    public string $date = '';

    public string $categoryId = '';

    public string $branchId = '';

    public string $description = '';

    public string $documentNo = '';

    public string $commission = '';

    public string $commissionCategoryId = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterAccount = '';

    public string $filterType = '';

    public string $filterCategory = '';

    public ?int $reversingId = null;

    public string $reverseReason = '';

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function openForm(string $type): void
    {
        Gate::authorize('finance.create');

        abort_unless(in_array($type, ['income', 'expense', 'transfer'], true), 404);

        $this->resetForm();
        $this->formType = $type;
        $this->date = FinanceCalendar::today()->toDateString();
    }

    public function closeForm(): void
    {
        $this->formType = null;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('accountId', 'toAccountId', 'amount', 'date', 'categoryId', 'branchId', 'description', 'documentNo', 'commission', 'commissionCategoryId');
        $this->resetValidation();
    }

    public function save(LedgerService $ledger, FinanceBalanceService $balances): void
    {
        Gate::authorize('finance.create');

        $isTransfer = $this->formType === 'transfer';

        $this->validate([
            'formType' => ['required', 'in:income,expense,transfer'],
            'accountId' => ['required', 'integer'],
            'toAccountId' => [$isTransfer ? 'required' : 'nullable', 'integer', 'different:accountId'],
            'amount' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'categoryId' => [$isTransfer ? 'nullable' : 'required', 'integer'],
            'branchId' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
            'documentNo' => ['nullable', 'string', 'max:100'],
            'commission' => ['nullable', 'string'],
            'commissionCategoryId' => ['nullable', 'integer'],
        ], [], [
            'accountId' => 'hesap', 'toAccountId' => 'hedef hesap', 'amount' => 'tutar',
            'date' => 'tarih', 'categoryId' => 'kategori',
        ]);

        try {
            $amount = Money::parse($this->amount);
            $commission = $isTransfer && trim($this->commission) !== '' ? Money::parse($this->commission) : 0;
        } catch (InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $account = FinanceAccount::findOrFail($this->accountId);
        $date = CarbonImmutable::parse($this->date);
        $actor = auth()->user();

        try {
            if ($isTransfer) {
                $target = FinanceAccount::findOrFail($this->toAccountId);
                $ledger->transfer(
                    $account, $target, $amount, $date, $actor, $this->description,
                    $commission, $this->commissionCategoryId !== '' ? FinanceCategory::findOrFail($this->commissionCategoryId) : null,
                );
                $touched = [$account, $target];
            } else {
                $category = FinanceCategory::findOrFail($this->categoryId);
                $branch = $this->branchId !== '' ? Branch::where('organization_id', $actor->organization_id)->findOrFail($this->branchId) : null;
                if ($this->formType === 'income') {
                    $ledger->income($account, $amount, $date, $category, $actor, $this->description, $branch, $this->documentNo);
                } else {
                    $ledger->expense($account, $amount, $date, $category, $actor, $this->description, $branch, $this->documentNo);
                }
                $touched = [$account];
            }
        } catch (FinanceRuleException $e) {
            $this->addError(str_contains($e->getMessage(), 'tarih') ? 'date' : 'amount', $e->getMessage());

            return;
        }

        $this->closeForm();

        $negative = collect($touched)->first(fn (FinanceAccount $touchedAccount) => $balances->balance($touchedAccount) < 0);
        session()->flash('status', 'Hareket kaydedildi.');

        if ($negative) {
            session()->flash('warning', "{$negative->name} hesabının bakiyesi eksiye düştü (".Money::format($balances->balance($negative)).').');
        }
    }

    public function startReverse(int $entryId): void
    {
        Gate::authorize('finance.update');

        $this->reversingId = $entryId;
        $this->reverseReason = '';
        $this->resetValidation();
    }

    public function cancelReverse(): void
    {
        $this->reversingId = null;
        $this->reverseReason = '';
    }

    public function confirmReverse(LedgerService $ledger): void
    {
        Gate::authorize('finance.update');

        $entry = FinanceEntry::findOrFail($this->reversingId);

        try {
            $count = $ledger->reverse($entry, $this->reverseReason, auth()->user())->count();
        } catch (FinanceRuleException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->cancelReverse();
        session()->flash('status', $count > 1 ? "Transfer iptal edildi ({$count} kayıt ters kayıtla kapatıldı)." : 'Hareket iptal edildi.');
    }

    public function with(): array
    {
        $entries = FinanceEntry::with(['account', 'category', 'branch', 'actor', 'reversal'])
            ->when($this->filterFrom !== '', fn ($query) => $query->whereDate('occurred_on', '>=', $this->filterFrom))
            ->when($this->filterTo !== '', fn ($query) => $query->whereDate('occurred_on', '<=', $this->filterTo))
            ->when($this->filterAccount !== '', fn ($query) => $query->where('account_id', $this->filterAccount))
            ->when($this->filterType !== '', fn ($query) => $query->where('type', $this->filterType))
            ->when($this->filterCategory !== '', fn ($query) => $query->where('category_id', $this->filterCategory))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(25);

        $accounts = FinanceAccount::orderBy('name')->get();
        $categories = FinanceCategory::orderBy('name')->get();

        return [
            'entries' => $entries,
            'accounts' => $accounts,
            'activeAccounts' => $accounts->filter->isActive(),
            'categories' => $categories,
            'formCategories' => $categories->filter(fn ($category) => $category->isActive() && $category->direction === ($this->formType === 'income' ? CategoryDirection::Income : CategoryDirection::Expense)),
            'expenseCategories' => $categories->filter(fn ($category) => $category->isActive() && $category->direction === CategoryDirection::Expense),
            'branches' => Branch::where('organization_id', auth()->user()->organization_id)->where('status', 'active')->orderBy('name')->get(),
            'types' => FinanceEntryType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kasa ve bankadan giren çıkan her para. Kayıtlar silinmez; yanlışsa iptal edilir.</p>
        </div>
        @can('finance.create')
            @if ($activeAccounts->isNotEmpty())
                <div class="mt-4 sm:mt-0 grid grid-cols-3 sm:flex gap-2">
                    <button wire:click="openForm('income')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-in hover:opacity-90">+ Gelir</button>
                    <button wire:click="openForm('expense')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-out hover:opacity-90">− Gider</button>
                    <button wire:click="openForm('transfer')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium border border-line bg-surface text-ink hover:bg-canvas">⇄ Transfer</button>
                </div>
            @endif
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-md bg-surface border border-finance-out/40 text-finance-out text-[13px] px-4 py-3">{{ session('warning') }}</div>
    @endif

    @if ($accounts->isEmpty())
        <div class="border border-dashed border-line rounded-lg bg-surface px-6 py-10 text-center">
            <p class="text-[14px] text-ink">Önce bir kasa veya banka hesabı tanımla.</p>
            <a href="{{ route('finance.accounts') }}" wire:navigate class="inline-block mt-3 text-[14px] text-brand-600 hover:underline">Hesaplar'a git →</a>
        </div>
    @else
        <div class="mb-4 grid grid-cols-2 lg:grid-cols-5 gap-3">
            <input type="date" wire:model.live="filterFrom" aria-label="Başlangıç" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
            <input type="date" wire:model.live="filterTo" aria-label="Bitiş" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
            <select wire:model.live="filterAccount" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm hesaplar</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterType" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm türler</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCategory" class="col-span-2 lg:col-span-1 border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm kategoriler</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <section class="border border-line rounded-lg bg-surface overflow-x-auto">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Tarih</th>
                        <th class="px-5 py-3 font-medium">Hesap</th>
                        <th class="px-5 py-3 font-medium">Açıklama</th>
                        <th class="px-5 py-3 font-medium text-right">Tutar</th>
                        <th class="px-5 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($entries as $entry)
                        @php($reversed = $entry->reversal !== null)
                        <tr wire:key="entry-{{ $entry->id }}" @class(['text-ink-muted' => $reversed])>
                            <td class="px-5 py-3 whitespace-nowrap tabular-nums">{{ $entry->occurred_on->format('d.m.Y') }}</td>
                            <td class="px-5 py-3 whitespace-nowrap">{{ $entry->account->name }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap items-center gap-x-2">
                                    <span class="text-[12px] text-ink-muted">{{ $entry->type->label() }}</span>
                                    @if ($entry->category)
                                        <span class="text-[12px] px-1.5 rounded bg-canvas border border-line">{{ $entry->category->name }}</span>
                                    @endif
                                    @if ($entry->branch)
                                        <span class="text-[12px] text-ink-muted">· {{ $entry->branch->name }}</span>
                                    @endif
                                    @if ($reversed)
                                        <span class="text-[12px] px-1.5 rounded bg-line">İptal edildi</span>
                                    @endif
                                </div>
                                @if ($entry->description || $entry->document_no)
                                    <div @class(['text-[13px] mt-0.5', 'line-through' => $reversed])>
                                        {{ $entry->description }}
                                        @if ($entry->document_no) <span class="text-ink-muted">· Belge {{ $entry->document_no }}</span> @endif
                                    </div>
                                @endif
                            </td>
                            <td @class([
                                'px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium',
                                'line-through' => $reversed,
                                'text-finance-in' => ! $reversed && $entry->direction > 0,
                                'text-finance-out' => ! $reversed && $entry->direction < 0,
                            ])>
                                {{ $entry->direction > 0 ? '+' : '−' }}{{ \App\Domain\Finance\Support\Money::format($entry->amount) }}
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                @can('finance.update')
                                    @if (! $reversed && $entry->type !== \App\Domain\Finance\Support\FinanceEntryType::Reversal)
                                        <button wire:click="startReverse({{ $entry->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">İptal et</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-ink-muted text-[13px]">Bu filtrelerle hareket yok.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <div class="mt-4">{{ $entries->links() }}</div>
    @endif

    <x-modal :show="$formType !== null" :title="match ($formType) { 'income' => 'Gelir Ekle', 'expense' => 'Gider Ekle', 'transfer' => 'Hesaplar Arası Transfer', default => '' }" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">{{ $formType === 'transfer' ? 'Çıkan hesap' : 'Hesap' }}</label>
                    <select wire:model="accountId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">Seç</option>
                        @foreach ($activeAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('accountId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                @if ($formType === 'transfer')
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Giren hesap</label>
                        <select wire:model="toAccountId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($activeAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error('toAccountId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                @else
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Kategori</label>
                        <select wire:model="categoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($formCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        @if ($formCategories->isEmpty())
                            <a href="{{ route('finance.categories') }}" wire:navigate class="text-[12px] text-brand-600 hover:underline">Kategori ekle →</a>
                        @endif
                    </div>
                @endif
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tutar (₺)</label>
                    <input type="text" inputmode="decimal" wire:model="amount" placeholder="1.250,50" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('amount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tarih</label>
                    <input type="date" wire:model="date" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('date') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                @if ($formType === 'transfer')
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Komisyon (isteğe bağlı)</label>
                        <input type="text" inputmode="decimal" wire:model="commission" placeholder="0" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                        <p class="text-[12px] text-ink-muted mt-1">POS'tan bankaya geçişte kesilen tutar; giren hesaptan gider olarak düşer.</p>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Komisyon kategorisi</label>
                        <select wire:model="commissionCategoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($expenseCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Şube (isteğe bağlı)</label>
                        <select wire:model="branchId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">—</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Belge no (isteğe bağlı)</label>
                        <input type="text" wire:model="documentNo" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    </div>
                @endif
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                <input type="text" wire:model="description" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$reversingId !== null" title="Hareketi iptal et" on-close="cancelReverse">
        <form wire:submit="confirmReverse" class="space-y-4">
            <p class="text-[13px] text-ink-muted">Kayıt silinmez; bugünün tarihiyle ters kayıt yazılır. Transferse tüm ayakları birlikte iptal edilir.</p>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                <input type="text" wire:model="reverseReason" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('reverseReason') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">İptal et</button>
                <button type="button" wire:click="cancelReverse" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
```

> `use InvalidArgumentException;` satırını PHP bloğunun `use` listesine ekle. `x-modal` `title` prop'u ifade kabul eder (`@props(['show', 'title', 'onClose'])`).

- [ ] **Step 4: Yeşil + tüm finans testleri**

Run: `php artisan test --filter=Finance`
Expected: PASS (tüm Finance testleri)

- [ ] **Step 5: Görsel doğrulama**

`npm run build`; başsız Chrome ile `/finans/hareketler`: boş hesap durumu, dolu liste (gelir mavi-yeşil, gider kahve tonu, iptal edilmiş satır üstü çizili), gelir/gider/transfer modalları, iptal modalı, eksi bakiye uyarısı — 1280 ve 500 piksel. Sorunları düzelt.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add "resources/views/pages/finance/⚡entries.blade.php" tests/Feature/Finance/FinanceEntriesScreenTest.php
git commit -m "feat: Finans Hareketler ekranı — hızlı gelir/gider/transfer girişi, filtreler, gerekçeli iptal — Aşama 34 (8/9)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Aşama 34 kabul testi, salt-okunur kontrolü, belge ve rapor

**Files:**
- Test: `tests/Feature/Acceptance/Stage34FinanceLedgerAcceptanceTest.php`
- Modify: `README.md` (modül listesine bir satır)

**Interfaces:**
- Consumes: tüm önceki görevler.

- [ ] **Step 1: Kabul testi** (spec'teki Doğrulama maddesi birebir)

```php
<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Exceptions\ReadOnlyOrganizationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aşama 34 Doğrulama: açılışı 10.000 TL kasaya 2.500 gelir + 1.000 gider →
 * 11.500; gider iptali → 12.500; 3.000 transfer iki hesapta doğru; ikinci
 * organizasyon hiçbirini göremez; yetkisiz personel giremez.
 */
class Stage34FinanceLedgerAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_34_verification(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Istanbul'));

        $clinic = Organization::create(['name' => 'Gülüş Kliniği', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $ledger = app(LedgerService::class);
        $balances = app(FinanceBalanceService::class);
        $today = CarbonImmutable::parse('2026-10-14');

        $cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
        $bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);
        $rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);

        $ledger->income($cash, 250_000, $today, $revenue, $admin);
        $expense = $ledger->expense($cash, 100_000, $today, $rent, $admin);
        $this->assertSame(1_150_000, $balances->balance($cash));

        $ledger->reverse($expense, 'Yanlış kayıt', $admin);
        $this->assertSame(1_250_000, $balances->balance($cash));

        $ledger->transfer($cash, $bank, 300_000, $today, $admin);
        $this->assertSame(950_000, $balances->balance($cash));
        $this->assertSame(300_000, $balances->balance($bank));
        $this->assertSame(1_250_000, $balances->total());

        // İzolasyon: ikinci organizasyon hiçbir finans kaydını göremez.
        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherAdmin);
        $this->assertSame(0, FinanceAccount::count());
        $this->assertSame(0, FinanceEntry::count());
        $this->assertSame(0, FinanceCategory::count());
        $this->get('/finans/hareketler')->assertOk()->assertDontSee('Kasa');

        // Yetki: finans yetkisi olmayan personel giremez.
        $staff = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff)->get('/finans/hareketler')->assertForbidden();

        CarbonImmutable::setTestNow();
    }

    public function test_read_only_organization_cannot_write_to_the_ledger(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Istanbul'));

        $clinic = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);
        $cash = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);

        $clinic->update(['status' => OrganizationStatus::ReadOnly]);
        $admin->refresh();

        $this->assertFalse($admin->can('finance.create'));
        $this->get('/finans/hareketler')->assertOk()->assertDontSee('+ Gelir');

        $this->expectException(ReadOnlyOrganizationException::class);
        app(LedgerService::class)->income($cash, 100, CarbonImmutable::parse('2026-10-14'), $revenue, $admin);

        CarbonImmutable::setTestNow();
    }
}
```

> `OrganizationStatus::ReadOnly` (`'read_only'`) mevcut. Organizasyonu salt-okunur yapmak için mevcut testlerin yolunu izle (`grep -rn "ReadOnly" tests/Feature/Platform`); `User::inReadOnlyOrganization()` ilişkiyi önbellekliyorsa `$admin->refresh()` yetmeyebilir, gerekirse `actingAs($admin->fresh())` kullan.

- [ ] **Step 2: Çalıştır**

Run: `php artisan test --filter=Stage34FinanceLedgerAcceptanceTest`
Expected: PASS. Kırmızıysa önce nedenini bul (systematic-debugging), testi gevşetme.

- [ ] **Step 3: Tüm test takımı, Pint, derleme**

```bash
php artisan test
vendor/bin/pint --test
npm run build
```

Expected: tüm testler yeşil (573 + yeni finans testleri), Pint temiz, derleme hatasız.

- [ ] **Step 4: README**

`README.md` modül/özellik listesine (mevcut biçimle) ekle:

```markdown
- **Finans (Faz 5):** kasa/banka/POS hesapları, gelir-gider kategorileri, silinmez finans defteri (gelir, gider, transfer, gerekçeli iptal). Tasarım: `docs/superpowers/specs/2026-10-14-finans-modulu-design.md`.
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Acceptance/Stage34FinanceLedgerAcceptanceTest.php README.md
git commit -m "feat: Finans defter çekirdeği kabul testi ve belge — Aşama 34 tamamlandı

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: NotebookLM raporu**

`asama-34-rapor.md — Finans Defter Çekirdeği (Faz 5)` başlığıyla NotebookLM'e (not defteri `0520342d-b8ff-4f44-937f-35bad9721566`) kaynak ekle. İçerik: neden, ne yapıldı (dosya dosya), tasarım kararları ve gerekçeleri (kuruş, değişmez model, İstanbul takvim günü, iptalin iptal gününe tarihlenmesi, transfer grubunun birlikte iptali, eksi bakiye uyarısı, varsayılan kategorilerin neden organizasyon açılışında eklenmediği), testler (sayılar), görsel doğrulama, bulunan hatalar, bilinen eksikler (cari/vadeler Aşama 35, mutabakat/özet/raporlar Aşama 36), sonraki aşama. Push yapma; kullanıcıya sor.
```

---

## Self-Review Notları

- Spec kapsamı (Aşama 34 satırı): göçler ✓ (Task 2), Money ✓ (1), LedgerService gelir/gider/transfer/iptal ✓ (4–5), FinanceBalanceService ✓ (4), yetki modülü ✓ (3), Hesaplar/Kategoriler/Hareketler ✓ (7–8), doğrulama ✓ (9). Hesap ekstresi ve mutabakat spec'te Aşama 36'dadır, bu planda yok.
- Spec'ten bilinçli sapmalar (rapora yazılacak): (1) varsayılan kategoriler organizasyon açılışında değil "Önerilenleri ekle" düğmesiyle (Platform domain'i klinik verisine dokunmaz); (2) `lockForUpdate` kullanılmıyor — bakiye kontrolü olmadığı için kilitlenecek bir değişmez yok, çift iptali `reverses_entry_id` tekil indeksi engelliyor; (3) "bugün" İstanbul takvim günü.
- Tür tutarlılığı: `FinanceEntryType::direction()` Task 2'de tanımlı, Task 4–5'te kullanılıyor; `Money::parse/format/input` Task 1; bileşen adları `pages::finance.*` Task 7–8–9 aynı.
