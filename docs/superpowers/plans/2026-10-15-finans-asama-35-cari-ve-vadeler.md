# Finans Aşama 35 — Cari ve Vadeler Uygulama Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cari hesaplar (kurum/tedarikçi), vadeli borç ve alacaklar (yükümlülük), kısmi ödeme/tahsilat, tekrarlayan kalemler (kira, maaş…), vade bildirimleri ve bunların ekranlarını Aşama 34'ün defter çekirdeği üzerine kurmak.

**Architecture:** Yükümlülük (`finance_obligations`) "beklenen para"dır; ödenince `LedgerService::pay` deftere `payment` türünde bir hareket yazar ve yükümlülüğün durumu (open/partial/paid) defterden yeniden hesaplanır — ödenen tutar saklanmaz. Tekrarlayan kalem tanımları günlük bir işle 35 gün ilerisinin yükümlülüklerini idempotent üretir. Vade bildirimleri mevcut uygulama içi bildirim altyapısını ve stok uyarılarının "okunmamış aynı durum varsa tekrar gönderme" desenini kullanır.

**Tech Stack:** Laravel 12, Livewire 4 tek dosyalı sayfalar, Tailwind v4 token'ları, PHPUnit (SQLite bellek içi), Pint. Canlı: PostgreSQL 16.

**Spec:** `docs/superpowers/specs/2026-10-14-finans-modulu-design.md` (bölüm 1 tablolar, 2 ObligationService / RecurringService / FinanceAlertService, 3 ekranlar, 5 Aşama 35 satırı). Aşama 34 planı: `docs/superpowers/plans/2026-10-14-finans-asama-34-defter-cekirdegi.md`.

## Global Constraints

- Ekran metinleri, kod yorumları, commit mesajları Türkçe. Commit biçimi `feat: ... — Aşama 35 (n/10)`, son commit `— Aşama 35 tamamlandı`; sonuna `Co-Authored-By: <commit'i yazan model> <noreply@anthropic.com>`.
- Tutarlar kuruş cinsinden `int`/`bigInteger`; float para yok. Ekranda `Money::parse/format`.
- `finance_entries` güncellenmez/silinmez; deftere yalnızca `LedgerService` yazar. Ödeme de dahil.
- Ödenen tutar, kalan ve cari bakiye saklanmaz; `FinanceBalanceService` türetir. `finance_obligations.status` defterden türetilen bir önbellektir ve yalnızca `LedgerService` (ödeme/iptal sonrası) ile `ObligationService::cancel` değiştirir.
- "Bugün" her zaman `FinanceCalendar::today()` (Europe/Istanbul).
- Pasif `'passive'`, aktif `'active'` (mevcut desen). Kayıt silinmez.
- Tüm finans modelleri `BelongsToOrganization`; defter dışındakiler `Auditable`. Kuyruk/zamanlanmış iş bağlamında oturum yoktur: organizasyon kimliği açıkça verilir.
- Yetki: ekranlar `finance.viewAny`; ekleme `finance.create`; düzenleme, ödeme iptali, yükümlülük iptali, pasife alma/aktifleştirme `finance.update`. Ödeme yapmak `finance.create` (deftere yeni hareket).
- Livewire tek dosyalı sayfalarda `use InvalidArgumentException;` YAZILMAZ (bu projede derlenmiş sınıfta 500 veriyor); `catch (\InvalidArgumentException $e)` tam adla.
- Yeni JS bağımlılığı yok; renkler `app.css` token'ları; tutar renkleri `finance-in`/`finance-out`. Arayüz görevlerinde `frontend-design` skill'i yüklenir, `npm run build` ve görsel kontrol (1280 ve 500 piksel) zorunlu — yardımcı: `.superpowers/tools/finance-screenshot.php` (git dışı; başlığındaki kullanım notuna bak, gerekirse seed'ini genişlet).
- Test: `php artisan test --filter=<Ad>`; tümü `php artisan test`; biçim `vendor/bin/pint --dirty`, son kontrol `vendor/bin/pint --test`.

---

## Dosya Haritası

| Dosya | Sorumluluk |
|---|---|
| `database/migrations/2026_10_15_100000_create_finance_obligation_tables.php` | parties, recurrings, obligations + entries'e party_id/obligation_id |
| `app/Domain/Finance/Support/PartyKind.php` | customer/supplier/other |
| `app/Domain/Finance/Support/ObligationDirection.php` | payable/receivable; defter yönü, kategori yönü, etiketler |
| `app/Domain/Finance/Support/ObligationStatus.php` | open/partial/paid/cancelled |
| `app/Domain/Finance/Support/RecurringFrequency.php` | weekly/monthly/yearly; sonraki tarih, dönem etiketi |
| `app/Domain/Finance/Support/FinanceEntryType.php` (değişir) | `Payment` türü |
| `app/Domain/Finance/Models/FinanceParty.php` | Cari |
| `app/Domain/Finance/Models/FinanceObligation.php` | Yükümlülük |
| `app/Domain/Finance/Models/FinanceRecurring.php` | Tekrarlayan kalem |
| `app/Domain/Finance/Models/FinanceEntry.php` (değişir) | party/obligation ilişkileri, `label()` |
| `app/Domain/Finance/Services/FinancePartyService.php` | Cari ekle/düzenle/pasife al/aktifleştir |
| `app/Domain/Finance/Services/FinanceBalanceService.php` (değişir) | ödenen, kalan, cari bakiye |
| `app/Domain/Finance/Services/ObligationService.php` | Yükümlülük ekle/düzenle/iptal |
| `app/Domain/Finance/Services/LedgerService.php` (değişir) | `pay`, iptalde durum senkronu, cari parametresi, aynı organizasyon kontrolü |
| `app/Domain/Finance/Services/RecurringService.php` | Tanım ekle/düzenle/pasife al/aktifleştir, `generateDue` |
| `app/Domain/Finance/Services/FinanceDueAlertService.php` | Vade taraması + bildirim |
| `app/Domain/Finance/Notifications/FinanceDueNotification.php` | Uygulama içi bildirim |
| `app/Domain/Finance/Jobs/GenerateRecurringObligationsJob.php`, `ScanFinanceDueJob.php` | Kuyruk katmanı |
| `app/Console/Commands/GenerateRecurringObligationsCommand.php`, `ScanFinanceDueCommand.php` | Zamanlayıcı girişi |
| `routes/console.php` (değişir) | İki zamanlanmış komut |
| `resources/views/pages/finance/⚡parties.blade.php`, `⚡party.blade.php` | Cari listesi ve cari detayı |
| `resources/views/pages/finance/⚡obligations.blade.php` | Ödemeler ve Tahsilatlar |
| `resources/views/pages/finance/⚡recurrings.blade.php` | Tekrarlayan kalemler |
| `resources/views/pages/finance/⚡entries.blade.php` (değişir) | Gelir/gidere cari seçimi, ödeme etiketi |
| `resources/views/components/finance-tabs.blade.php`, `routes/web.php`, `resources/views/pages/notifications/⚡index.blade.php` (değişir) | Sekmeler, rotalar, bildirim türü |

---

### Task 1: Tablolar, enum'lar, modeller

**Files:**
- Create: migration, `PartyKind`, `ObligationDirection`, `ObligationStatus`, `RecurringFrequency`, `FinanceParty`, `FinanceObligation`, `FinanceRecurring`
- Modify: `FinanceEntryType.php`, `FinanceEntry.php`
- Test: `tests/Unit/Finance/RecurringFrequencyTest.php`, `tests/Feature/Finance/FinanceObligationModelsTest.php`

**Interfaces:**
- Produces:
  - `PartyKind::{Customer,Supplier,Other}` + `label()`.
  - `ObligationDirection::{Payable,Receivable}` + `label()` ('Ödenecek'/'Tahsil edilecek'), `entryDirection(): int` (-1/+1), `categoryDirection(): CategoryDirection` (Expense/Income), `paymentLabel(): string` ('Ödeme'/'Tahsilat').
  - `ObligationStatus::{Open,Partial,Paid,Cancelled}` + `label()`, `isOpen(): bool` (Open|Partial).
  - `RecurringFrequency::{Weekly,Monthly,Yearly}` + `label()`, `dayFor(CarbonImmutable $startsOn): int`, `next(CarbonImmutable $from, int $day): CarbonImmutable`, `periodLabel(CarbonImmutable $dueOn): string`.
  - `FinanceEntryType::Payment` ('payment', etiket 'Ödeme/Tahsilat', `direction()` null).
  - Modeller ve alanları aşağıdaki migration'daki gibi; `FinanceEntry::party()`, `obligation()`, `label(): string`.

- [ ] **Step 1: Failing tests**

`tests/Unit/Finance/RecurringFrequencyTest.php`:

```php
<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Support\RecurringFrequency;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RecurringFrequencyTest extends TestCase
{
    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_monthly_keeps_day_and_clamps_short_months(): void
    {
        $day = RecurringFrequency::Monthly->dayFor($this->d('2026-01-31'));

        $this->assertSame(31, $day);
        $this->assertSame('2026-02-28', RecurringFrequency::Monthly->next($this->d('2026-01-31'), $day)->toDateString());
        $this->assertSame('2026-03-31', RecurringFrequency::Monthly->next($this->d('2026-02-28'), $day)->toDateString());
        $this->assertSame('2028-02-29', RecurringFrequency::Monthly->next($this->d('2028-01-31'), $day)->toDateString());
        $this->assertSame('2026-11-05', RecurringFrequency::Monthly->next($this->d('2026-10-05'), 5)->toDateString());
    }

    public function test_weekly_adds_seven_days(): void
    {
        $this->assertSame(3, RecurringFrequency::Weekly->dayFor($this->d('2026-10-14')));
        $this->assertSame('2026-10-21', RecurringFrequency::Weekly->next($this->d('2026-10-14'), 3)->toDateString());
    }

    public function test_yearly_clamps_leap_day_and_returns(): void
    {
        $day = RecurringFrequency::Yearly->dayFor($this->d('2028-02-29'));

        $this->assertSame('2029-02-28', RecurringFrequency::Yearly->next($this->d('2028-02-29'), $day)->toDateString());
        $this->assertSame('2032-02-29', RecurringFrequency::Yearly->next($this->d('2031-02-28'), $day)->toDateString());
    }

    public function test_period_labels_are_turkish(): void
    {
        $this->assertSame('Kasım 2026', RecurringFrequency::Monthly->periodLabel($this->d('2026-11-05')));
        $this->assertSame('14.10.2026', RecurringFrequency::Weekly->periodLabel($this->d('2026-10-14')));
        $this->assertSame('2027', RecurringFrequency::Yearly->periodLabel($this->d('2027-03-01')));
    }
}
```

`tests/Feature/Finance/FinanceObligationModelsTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceObligationModelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
    }

    private function party(): FinanceParty
    {
        return FinanceParty::create(['organization_id' => $this->organization->id, 'name' => 'Dental Depo A.Ş.', 'kind' => PartyKind::Supplier]);
    }

    public function test_obligation_defaults_casts_and_relations(): void
    {
        $party = $this->party();
        $obligation = FinanceObligation::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable,
            'party_id' => $party->id, 'amount' => 1_500_000, 'due_on' => '2026-10-30', 'description' => 'Fatura F-77',
        ])->fresh();

        $this->assertSame(ObligationStatus::Open, $obligation->status);
        $this->assertSame(1_500_000, $obligation->amount);
        $this->assertSame('2026-10-30', $obligation->due_on->toDateString());
        $this->assertTrue($obligation->party->is($party));
        $this->assertTrue($party->obligations->first()->is($obligation));
        $this->assertTrue($obligation->isOpen());
        $this->assertSame('active', $party->fresh()->status);
    }

    public function test_entry_links_party_and_obligation_and_labels_payments(): void
    {
        $party = $this->party();
        $obligation = FinanceObligation::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable,
            'party_id' => $party->id, 'amount' => 100, 'due_on' => '2026-10-30', 'description' => 'x',
        ]);
        $account = FinanceAccount::create(['organization_id' => $this->organization->id, 'name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        $payment = FinanceEntry::create([
            'organization_id' => $this->organization->id, 'account_id' => $account->id, 'type' => FinanceEntryType::Payment,
            'direction' => -1, 'amount' => 100, 'occurred_on' => '2026-10-14', 'party_id' => $party->id, 'obligation_id' => $obligation->id,
        ]);

        $this->assertTrue($payment->party->is($party));
        $this->assertTrue($payment->obligation->is($obligation));
        $this->assertSame('Ödeme', $payment->label());
        $this->assertSame(1, $obligation->payments()->count());
        $this->assertSame('Gelir', (new FinanceEntry(['type' => FinanceEntryType::Income, 'direction' => 1]))->label());
    }

    public function test_recurring_generates_each_due_date_at_most_once(): void
    {
        $recurring = FinanceRecurring::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable, 'name' => 'Kira',
            'amount' => 2_000_000, 'frequency' => RecurringFrequency::Monthly, 'day' => 5,
            'starts_on' => '2026-11-05', 'next_due_on' => '2026-11-05',
        ]);
        $attributes = ['organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable, 'amount' => 1, 'due_on' => '2026-11-05', 'description' => 'Kira', 'recurring_id' => $recurring->id];
        FinanceObligation::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        FinanceObligation::create($attributes);
    }

    public function test_new_records_are_isolated_per_organization(): void
    {
        $this->party();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, FinanceParty::count());
        $this->assertSame(0, FinanceObligation::count());
        $this->assertSame(0, FinanceRecurring::count());
    }
}
```

- [ ] **Step 2: Kırmızı** — `php artisan test --filter='RecurringFrequencyTest|FinanceObligationModelsTest'` → sınıf bulunamadı.

- [ ] **Step 3: Uygula**

Migration `database/migrations/2026_10_15_100000_create_finance_obligation_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 Finans — cari ve vadeler (Aşama 35). Yükümlülük "beklenen para"dır;
 * ödenen tutar saklanmaz, defterdeki payment kayıtlarından türetilir.
 * Yalnızca tablo/kolon ekler; mevcut veriye dokunmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 20)->nullable();
            $table->unsignedSmallInteger('default_term_days')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('finance_recurrings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('name');
            $table->bigInteger('amount');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->foreignId('party_id')->nullable()->constrained('finance_parties');
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('day');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_due_on');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['status', 'next_due_on']);
        });

        Schema::create('finance_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->foreignId('party_id')->nullable()->constrained('finance_parties');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->bigInteger('amount');
            $table->date('due_on');
            $table->string('description', 500);
            $table->string('document_no', 100)->nullable();
            $table->foreignId('recurring_id')->nullable()->constrained('finance_recurrings');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('status', 16)->default('open');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['recurring_id', 'due_on']);
            $table->index(['organization_id', 'status', 'due_on']);
            $table->index('party_id');
        });

        Schema::table('finance_entries', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('category_id')->constrained('finance_parties');
            $table->foreignId('obligation_id')->nullable()->after('party_id')->constrained('finance_obligations');

            $table->index('obligation_id');
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('obligation_id');
            $table->dropConstrainedForeignId('party_id');
        });

        Schema::dropIfExists('finance_obligations');
        Schema::dropIfExists('finance_recurrings');
        Schema::dropIfExists('finance_parties');
    }
};
```

`app/Domain/Finance/Support/PartyKind.php`:

```php
<?php

namespace App\Domain\Finance\Support;

enum PartyKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Kurum / Müşteri',
            self::Supplier => 'Tedarikçi',
            self::Other => 'Diğer',
        };
    }
}
```

`app/Domain/Finance/Support/ObligationDirection.php`:

```php
<?php

namespace App\Domain\Finance\Support;

/**
 * Yükümlülüğün yönü: biz mi ödeyeceğiz (borç), bize mi ödenecek (alacak).
 */
enum ObligationDirection: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';

    public function label(): string
    {
        return match ($this) {
            self::Payable => 'Ödenecek',
            self::Receivable => 'Tahsil edilecek',
        };
    }

    /**
     * Ödemesinin defterdeki yönü: borç ödemesi hesaptan çıkar, tahsilat girer.
     */
    public function entryDirection(): int
    {
        return $this === self::Payable ? -1 : 1;
    }

    public function categoryDirection(): CategoryDirection
    {
        return $this === self::Payable ? CategoryDirection::Expense : CategoryDirection::Income;
    }

    public function paymentLabel(): string
    {
        return $this === self::Payable ? 'Ödeme' : 'Tahsilat';
    }
}
```

`app/Domain/Finance/Support/ObligationStatus.php`:

```php
<?php

namespace App\Domain\Finance\Support;

enum ObligationStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::Partial => 'Kısmen ödendi',
            self::Paid => 'Kapandı',
            self::Cancelled => 'İptal',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Partial;
    }
}
```

`app/Domain/Finance/Support/RecurringFrequency.php`:

```php
<?php

namespace App\Domain\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * Tekrarlayan kalemin sıklığı. "day" tanım anında ilk vade tarihinden alınır
 * ve saklanır: 31'inde ödenen bir kalem Şubat'ta 28'ine kayar, Mart'ta yeniden
 * 31'ine döner (ayın son gününe sabitlenip kalmaz).
 */
enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    private const MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Haftalık',
            self::Monthly => 'Aylık',
            self::Yearly => 'Yıllık',
        };
    }

    /**
     * Haftalıkta ISO hafta günü (1 = Pazartesi), aylık ve yıllıkta ayın günü.
     */
    public function dayFor(CarbonImmutable $startsOn): int
    {
        return $this === self::Weekly ? $startsOn->dayOfWeekIso : $startsOn->day;
    }

    public function next(CarbonImmutable $from, int $day): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $from->addWeek(),
            self::Monthly => $this->onDay($from->startOfMonth()->addMonthNoOverflow(), $day),
            self::Yearly => $this->onDay($from->startOfMonth()->addYearNoOverflow(), $day),
        };
    }

    public function periodLabel(CarbonImmutable $dueOn): string
    {
        return match ($this) {
            self::Weekly => $dueOn->format('d.m.Y'),
            self::Monthly => self::MONTHS[$dueOn->month].' '.$dueOn->year,
            self::Yearly => (string) $dueOn->year,
        };
    }

    private function onDay(CarbonImmutable $monthStart, int $day): CarbonImmutable
    {
        return $monthStart->setDay(min($day, $monthStart->daysInMonth));
    }
}
```

`FinanceEntryType.php` — `case Payment = 'payment';` ekle (Reversal'dan önce); `label()` içine `self::Payment => 'Ödeme/Tahsilat',`; `direction()` içinde `self::Reversal => null` satırını `self::Payment, self::Reversal => null,` yap ve yorumu "Ödeme ve iptal kayıtlarının yönü sabit değildir: ödemede yükümlülüğün yönü, iptalde iptal ettiği kaydın tersi." olarak güncelle.

`app/Domain/Finance/Models/FinanceParty.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cari hesap: kurum veya firma (hasta açılmaz — karar 2.6, 2.9). Bakiye
 * saklanmaz; açık yükümlülüklerin kalanından türetilir (FinanceBalanceService).
 */
class FinanceParty extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'phone',
        'email',
        'tax_number',
        'default_term_days',
        'note',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'kind' => PartyKind::class,
        'default_term_days' => 'integer',
    ];

    public function obligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class, 'party_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'party_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

`app/Domain/Finance/Models/FinanceObligation.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vadeli borç veya alacak ("beklenen para"). Ödenen tutar saklanmaz: bağlı
 * payment kayıtları ve iptallerinden türetilir. status, ödeme/iptal sonrası
 * LedgerService'in defterden yeniden hesapladığı bir özettir.
 */
class FinanceObligation extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'direction',
        'party_id',
        'category_id',
        'amount',
        'due_on',
        'description',
        'document_no',
        'recurring_id',
        'branch_id',
        'status',
        'cancel_reason',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'direction' => ObligationDirection::class,
        'status' => ObligationStatus::class,
        'amount' => 'integer',
        'due_on' => 'date',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(FinanceRecurring::class, 'recurring_id');
    }

    /**
     * Bu yükümlülüğe bağlı defter kayıtları (ödemeler ve onların iptalleri).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'obligation_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
```

`app/Domain/Finance/Models/FinanceRecurring.php`:

```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tekrarlayan kalem tanımı (kira, maaş, abonelik). Kendisi para değildir;
 * günlük iş bundan yükümlülük üretir (RecurringService::generateDue).
 */
class FinanceRecurring extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'direction',
        'name',
        'amount',
        'category_id',
        'party_id',
        'frequency',
        'day',
        'starts_on',
        'ends_on',
        'next_due_on',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'direction' => ObligationDirection::class,
        'frequency' => RecurringFrequency::class,
        'amount' => 'integer',
        'day' => 'integer',
        'starts_on' => 'immutable_date',
        'ends_on' => 'immutable_date',
        'next_due_on' => 'immutable_date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class, 'recurring_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

`FinanceEntry.php` — `$fillable`'a `'party_id', 'obligation_id'` ekle (category_id'den sonra); ilişkiler ve etiket:

```php
    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(FinanceObligation::class, 'obligation_id');
    }

    /**
     * Ekranda gösterilecek tür adı: ödeme kaydı yönüne göre "Ödeme" veya "Tahsilat".
     */
    public function label(): string
    {
        if ($this->type === FinanceEntryType::Payment) {
            return $this->direction < 0 ? 'Ödeme' : 'Tahsilat';
        }

        return $this->type->label();
    }
```

- [ ] **Step 4: Yeşil** — aynı filtre PASS; `php artisan test --filter=Finance` PASS (Aşama 34 testleri bozulmadı).

- [ ] **Step 5: Commit** — `feat: Finans cari, yükümlülük ve tekrarlayan kalem tabloları ve modelleri — Aşama 35 (1/10)`

---

### Task 2: Cari servisi ve türetilen bakiyeler

**Files:**
- Create: `app/Domain/Finance/Services/FinancePartyService.php`
- Modify: `app/Domain/Finance/Services/FinanceBalanceService.php`
- Test: `tests/Feature/Finance/FinancePartyServiceTest.php`

**Interfaces:**
- Produces:
  - `FinancePartyService::create(array{name: string, kind: PartyKind, phone?: ?string, email?: ?string, tax_number?: ?string, default_term_days?: ?int, note?: ?string}): FinanceParty` — ad boşsa veya aynı adda aktif/pasif cari varsa `FinanceRuleException` (pasifse "aktifleştirin" der).
  - `FinancePartyService::update(FinanceParty, array aynı anahtarlar): FinanceParty`, `deactivate(FinanceParty): FinanceParty`, `activate(FinanceParty): FinanceParty`.
  - `FinanceBalanceService::paid(FinanceObligation): int`, `remaining(FinanceObligation): int` (iptalde 0), `paidAmounts(iterable<FinanceObligation>): array<int,int>`, `partyBalances(iterable<FinanceParty>): array<int, array{receivable:int, payable:int, net:int}>` (yalnız açık/kısmi yükümlülüklerin kalanı; net = receivable − payable, pozitif: cari bize borçlu).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancePartyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancePartyService $parties;

    private FinanceBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $this->parties = app(FinancePartyService::class);
        $this->balances = app(FinanceBalanceService::class);
    }

    public function test_party_is_created_trimmed_and_can_be_deactivated_and_activated(): void
    {
        $party = $this->parties->create(['name' => '  ABC Sigorta ', 'kind' => PartyKind::Customer, 'email' => ' info@abc.test ', 'default_term_days' => 30]);

        $this->assertSame('ABC Sigorta', $party->name);
        $this->assertSame('info@abc.test', $party->email);
        $this->assertSame(30, $party->default_term_days);
        $this->assertFalse($this->parties->deactivate($party)->fresh()->isActive());
        $this->assertTrue($this->parties->activate($party)->fresh()->isActive());
    }

    public function test_duplicate_name_is_rejected_and_passive_duplicate_explains_reactivation(): void
    {
        $party = $this->parties->create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $this->parties->deactivate($party);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('aktifleştirin');

        $this->parties->create(['name' => 'abc sigorta', 'kind' => PartyKind::Customer]);
    }

    public function test_blank_name_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);

        $this->parties->create(['name' => '   ', 'kind' => PartyKind::Other]);
    }

    public function test_update_changes_contact_fields(): void
    {
        $party = $this->parties->create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        $this->parties->update($party, ['name' => 'Depo Ltd.', 'kind' => PartyKind::Supplier, 'phone' => '0212 000 00 00', 'default_term_days' => null]);

        $this->assertSame('Depo Ltd.', $party->fresh()->name);
        $this->assertSame('0212 000 00 00', $party->fresh()->phone);
    }

    public function test_paid_remaining_and_party_balances_are_derived_from_the_ledger(): void
    {
        $customer = $this->parties->create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $supplier = $this->parties->create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);

        $receivable = FinanceObligation::create(['direction' => ObligationDirection::Receivable, 'party_id' => $customer->id, 'amount' => 500_000, 'due_on' => '2026-10-20', 'description' => 'Ekim faturası']);
        $payable = FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $supplier->id, 'amount' => 300_000, 'due_on' => '2026-10-20', 'description' => 'F-1']);
        FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $supplier->id, 'amount' => 999, 'due_on' => '2026-10-20', 'description' => 'iptal', 'status' => ObligationStatus::Cancelled]);

        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => 1, 'amount' => 200_000, 'occurred_on' => '2026-10-14', 'obligation_id' => $receivable->id, 'party_id' => $customer->id]);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 100_000, 'occurred_on' => '2026-10-14', 'obligation_id' => $payable->id, 'party_id' => $supplier->id]);

        $this->assertSame(200_000, $this->balances->paid($receivable));
        $this->assertSame(300_000, $this->balances->remaining($receivable));
        $this->assertSame(100_000, $this->balances->paid($payable));
        $this->assertSame([$receivable->id => 200_000, $payable->id => 100_000], $this->balances->paidAmounts([$receivable, $payable]));

        $result = $this->balances->partyBalances([$customer, $supplier]);
        $this->assertSame(['receivable' => 300_000, 'payable' => 0, 'net' => 300_000], $result[$customer->id]);
        $this->assertSame(['receivable' => 0, 'payable' => 200_000, 'net' => -200_000], $result[$supplier->id]);
    }

    public function test_cancelled_obligation_has_nothing_remaining(): void
    {
        $obligation = FinanceObligation::create(['direction' => ObligationDirection::Payable, 'amount' => 999, 'due_on' => '2026-10-20', 'description' => 'x', 'status' => ObligationStatus::Cancelled]);

        $this->assertSame(0, $this->balances->remaining($obligation));
    }
}
```

> Not: Bu testte `FinanceEntry::create` doğrudan çağrılıyor — yalnızca türetme hesabını LedgerService'ten bağımsız sınamak için test kurulumu. Uygulama kodunda deftere yalnızca LedgerService yazar.

- [ ] **Step 2: Kırmızı** — `php artisan test --filter=FinancePartyServiceTest`.

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Services/FinancePartyService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\PartyKind;

/**
 * Cari kartı. Hasta açılmaz (karar 2.6/2.9); ekran "kurum veya firma" diye yönlendirir.
 */
class FinancePartyService
{
    /**
     * @param  array{name: string, kind: PartyKind, phone?: ?string, email?: ?string, tax_number?: ?string, default_term_days?: ?int, note?: ?string}  $data
     */
    public function create(array $data): FinanceParty
    {
        $attributes = $this->attributes($data);
        $this->ensureUniqueName($attributes['name']);

        return FinanceParty::create($attributes);
    }

    /**
     * @param  array{name: string, kind: PartyKind, phone?: ?string, email?: ?string, tax_number?: ?string, default_term_days?: ?int, note?: ?string}  $data
     */
    public function update(FinanceParty $party, array $data): FinanceParty
    {
        $attributes = $this->attributes($data);
        $this->ensureUniqueName($attributes['name'], $party);

        $party->update($attributes);

        return $party;
    }

    public function deactivate(FinanceParty $party): FinanceParty
    {
        $party->update(['status' => 'passive']);

        return $party;
    }

    public function activate(FinanceParty $party): FinanceParty
    {
        $party->update(['status' => 'active']);

        return $party;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $name = trim($data['name']);

        if ($name === '') {
            throw new FinanceRuleException('Cari adı boş olamaz.');
        }

        return [
            'name' => $name,
            'kind' => $data['kind'],
            'phone' => $this->clean($data['phone'] ?? null),
            'email' => $this->clean($data['email'] ?? null),
            'tax_number' => $this->clean($data['tax_number'] ?? null),
            'default_term_days' => $data['default_term_days'] ?? null,
            'note' => $this->clean($data['note'] ?? null),
        ];
    }

    private function ensureUniqueName(string $name, ?FinanceParty $except = null): void
    {
        $existing = FinanceParty::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->first();

        if ($existing === null) {
            return;
        }

        throw new FinanceRuleException($existing->isActive()
            ? "\"{$existing->name}\" adında bir cari zaten var."
            : "\"{$existing->name}\" adında pasif bir cari var; Cariler listesinden aktifleştirin.");
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
```

> `LOWER(name)` ASCII dışı harflerde (İ/ı) PostgreSQL ve SQLite'ta farklı davranabilir; Türkçe büyük-küçük harf eşleşmesi kusursuz değildir. Bu kabul edilebilir: amaç yanlışlıkla çift kayıt açmayı önlemek. Raporda bilinen eksik olarak yaz.

`FinanceBalanceService.php` — sınıfın sonuna ekle (gerekli `use`: `FinanceObligation`, `FinanceParty`, `ObligationStatus`):

```php
    /**
     * Yükümlülüğe yapılmış net ödeme: bağlı payment kayıtları ve onların
     * iptalleri toplamı, yükümlülüğün yönüyle pozitife çevrilir.
     */
    public function paid(FinanceObligation $obligation): int
    {
        return $this->paidAmounts([$obligation])[$obligation->id];
    }

    public function remaining(FinanceObligation $obligation): int
    {
        if ($obligation->status === ObligationStatus::Cancelled) {
            return 0;
        }

        return $obligation->amount - $this->paid($obligation);
    }

    /**
     * @param  iterable<FinanceObligation>  $obligations
     * @return array<int, int> yükümlülük id → ödenen
     */
    public function paidAmounts(iterable $obligations): array
    {
        $obligations = collect($obligations);

        $sums = FinanceEntry::whereIn('obligation_id', $obligations->pluck('id'))
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, SUM(direction * amount) as total')
            ->pluck('total', 'obligation_id');

        return $obligations->mapWithKeys(fn (FinanceObligation $obligation) => [
            $obligation->id => (int) ($sums[$obligation->id] ?? 0) * $obligation->direction->entryDirection(),
        ])->all();
    }

    /**
     * Cari bakiye: açık ve kısmi yükümlülüklerin kalanı. net > 0: cari bize
     * borçlu; net < 0: biz cariye borçluyuz. Yükümlülüğe bağlanmamış cari
     * hareketleri ekstrede görünür ama bakiyeyi değiştirmez (spec bölüm 1).
     *
     * @param  iterable<FinanceParty>  $parties
     * @return array<int, array{receivable: int, payable: int, net: int}>
     */
    public function partyBalances(iterable $parties): array
    {
        $parties = collect($parties);
        $result = $parties->mapWithKeys(fn (FinanceParty $party) => [$party->id => ['receivable' => 0, 'payable' => 0, 'net' => 0]])->all();

        $open = FinanceObligation::whereIn('party_id', $parties->pluck('id'))
            ->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial])
            ->get();
        $paid = $this->paidAmounts($open);

        foreach ($open as $obligation) {
            $key = $obligation->direction === ObligationDirection::Receivable ? 'receivable' : 'payable';
            $result[$obligation->party_id][$key] += $obligation->amount - $paid[$obligation->id];
        }

        foreach ($result as $id => $row) {
            $result[$id]['net'] = $row['receivable'] - $row['payable'];
        }

        return $result;
    }
```

(`use App\Domain\Finance\Support\ObligationDirection;` de ekle.)

- [ ] **Step 4: Yeşil** — filtre PASS.
- [ ] **Step 5: Commit** — `feat: Finans cari servisi ve defterden türetilen ödenen/kalan/cari bakiye — Aşama 35 (2/10)`

---

### Task 3: ObligationService

**Files:**
- Create: `app/Domain/Finance/Services/ObligationService.php`
- Test: `tests/Feature/Finance/ObligationServiceTest.php`

**Interfaces:**
- Consumes: `FinanceBalanceService::paid`, enum'lar, `FinanceCalendar::today()`.
- Produces:
  - `ObligationService::create(array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}): FinanceObligation`
  - `ObligationService::update(FinanceObligation, array aynı anahtarlar): FinanceObligation` — yalnız açık ve hiç ödeme kaydı yokken.
  - `ObligationService::cancel(FinanceObligation, string $reason): FinanceObligation` — yalnız net ödenen 0 iken.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObligationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ObligationService $obligations;

    private FinanceParty $supplier;

    private FinanceCategory $rent;

    private FinanceCategory $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->obligations = app(ObligationService::class);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier, 'default_term_days' => 30]);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
        $this->revenue = FinanceCategory::create(['name' => 'Kurum Tahsilatı', 'direction' => CategoryDirection::Income]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function payable(array $overrides = []): array
    {
        return [
            'direction' => ObligationDirection::Payable, 'amount' => 1_500_000,
            'due_on' => CarbonImmutable::parse('2026-10-30'), 'party' => $this->supplier,
            'category' => $this->rent, 'description' => ' Fatura F-77 ', 'document_no' => 'F-77', ...$overrides,
        ];
    }

    public function test_create_records_an_open_obligation(): void
    {
        $obligation = $this->obligations->create($this->payable());

        $this->assertSame(ObligationStatus::Open, $obligation->status);
        $this->assertSame('Fatura F-77', $obligation->description);
        $this->assertSame($this->supplier->id, $obligation->party_id);
        $this->assertSame('2026-10-30', $obligation->due_on->toDateString());
    }

    public function test_due_date_defaults_to_party_term(): void
    {
        $obligation = $this->obligations->create($this->payable(['due_on' => null]));

        $this->assertSame('2026-11-13', $obligation->due_on->toDateString());
    }

    public function test_due_date_is_required_without_party_term(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('Vade tarihini');

        $this->obligations->create($this->payable(['due_on' => null, 'party' => null]));
    }

    public function test_past_due_date_is_allowed_for_old_invoices(): void
    {
        $this->assertSame('2026-09-01', $this->obligations->create($this->payable(['due_on' => CarbonImmutable::parse('2026-09-01')]))->due_on->toDateString());
    }

    public function test_category_direction_must_match(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('gider kategorisi');

        $this->obligations->create($this->payable(['category' => $this->revenue]));
    }

    public function test_amount_description_and_active_party_are_required(): void
    {
        foreach ([['amount' => 0], ['description' => '  ']] as $overrides) {
            try {
                $this->obligations->create($this->payable($overrides));
                $this->fail('FinanceRuleException bekleniyordu.');
            } catch (FinanceRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->supplier->update(['status' => 'passive']);
        $this->expectException(FinanceRuleException::class);
        $this->obligations->create($this->payable());
    }

    public function test_update_only_without_payments(): void
    {
        $obligation = $this->obligations->create($this->payable());
        $this->obligations->update($obligation, $this->payable(['amount' => 1_200_000]));
        $this->assertSame(1_200_000, $obligation->fresh()->amount);

        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 1, 'occurred_on' => '2026-10-14', 'obligation_id' => $obligation->id]);

        $this->expectException(FinanceRuleException::class);
        $this->obligations->update($obligation->fresh(), $this->payable(['amount' => 5]));
    }

    public function test_cancel_requires_reason_and_no_net_payment(): void
    {
        $obligation = $this->obligations->create($this->payable());

        try {
            $this->obligations->cancel($obligation, ' ');
            $this->fail('Gerekçesiz iptal reddedilmeliydi.');
        } catch (FinanceRuleException) {
        }

        $cancelled = $this->obligations->cancel($obligation, 'Yanlış girildi');
        $this->assertSame(ObligationStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Yanlış girildi', $cancelled->fresh()->cancel_reason);

        $paidOne = $this->obligations->create($this->payable());
        $account = FinanceAccount::create(['name' => 'Kasa', 'type' => AccountType::Cash, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        FinanceEntry::create(['account_id' => $account->id, 'type' => FinanceEntryType::Payment, 'direction' => -1, 'amount' => 100, 'occurred_on' => '2026-10-14', 'obligation_id' => $paidOne->id]);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('ödemeleri iptal');
        $this->obligations->cancel($paidOne, 'x');
    }
}
```

- [ ] **Step 2: Kırmızı** — `php artisan test --filter=ObligationServiceTest`.

- [ ] **Step 3: Uygula** — `app/Domain/Finance/Services/ObligationService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonInterface;

/**
 * Vadeli borç ve alacak. Geçmiş tarihli vade girilebilir (eski ödenmemiş
 * faturayı sisteme almak için). Ödeme LedgerService::pay ile yapılır; ödenen
 * tutar burada değil defterde tutulur.
 */
class ObligationService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    /**
     * @param  array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}  $data
     */
    public function create(array $data): FinanceObligation
    {
        return FinanceObligation::create($this->attributes($data));
    }

    /**
     * @param  array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}  $data
     */
    public function update(FinanceObligation $obligation, array $data): FinanceObligation
    {
        if (! $obligation->isOpen() || FinanceEntry::where('obligation_id', $obligation->id)->exists()) {
            throw new FinanceRuleException('Ödemesi olan veya kapanmış kayıt düzenlenemez; gerekirse iptal edip yeniden girin.');
        }

        $obligation->update($this->attributes($data));

        return $obligation;
    }

    public function cancel(FinanceObligation $obligation, string $reason): FinanceObligation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceRuleException('İptal için bir gerekçe yazın.');
        }

        if ($obligation->status === ObligationStatus::Cancelled) {
            throw new FinanceRuleException('Bu kayıt zaten iptal edilmiş.');
        }

        if ($this->balances->paid($obligation) !== 0) {
            throw new FinanceRuleException('Önce bu kayda bağlı ödemeleri iptal edin.');
        }

        $obligation->update(['status' => ObligationStatus::Cancelled, 'cancel_reason' => $reason]);

        return $obligation;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        /** @var ObligationDirection $direction */
        $direction = $data['direction'];
        $party = $data['party'] ?? null;
        $category = $data['category'] ?? null;
        $description = trim($data['description']);

        if ($data['amount'] <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.');
        }

        if ($description === '') {
            throw new FinanceRuleException('Açıklama yazın (örn. fatura no veya ne için olduğu).');
        }

        if ($party !== null && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.");
        }

        if ($category !== null) {
            if (! $category->isActive()) {
                throw new FinanceRuleException("{$category->name} pasif bir kategori.");
            }

            if ($category->direction !== $direction->categoryDirection()) {
                throw new FinanceRuleException('Bu kayıt için bir '.mb_strtolower($direction->categoryDirection()->label()).' kategorisi seçin.');
            }
        }

        $dueOn = $data['due_on'] ?? null;

        if ($dueOn === null && $party?->default_term_days !== null) {
            $dueOn = FinanceCalendar::today()->addDays($party->default_term_days);
        }

        if ($dueOn === null) {
            throw new FinanceRuleException('Vade tarihini girin.');
        }

        $documentNo = trim((string) ($data['document_no'] ?? ''));

        return [
            'direction' => $direction,
            'amount' => $data['amount'],
            'due_on' => $dueOn->toDateString(),
            'party_id' => $party?->id,
            'category_id' => $category?->id,
            'description' => $description,
            'document_no' => $documentNo === '' ? null : $documentNo,
            'branch_id' => ($data['branch'] ?? null)?->id,
        ];
    }
}
```

- [ ] **Step 4: Yeşil** · **Step 5: Commit** — `feat: Finans yükümlülük servisi — vade, cari vadesi, düzenleme ve gerekçeli iptal — Aşama 35 (3/10)`

---

### Task 4: LedgerService — ödeme, iptalde durum senkronu, cari, aynı organizasyon

**Files:**
- Modify: `app/Domain/Finance/Services/LedgerService.php`
- Test: `tests/Feature/Finance/LedgerPaymentTest.php`

**Interfaces:**
- Consumes: `FinanceBalanceService::paid/remaining`, `ObligationDirection::entryDirection/paymentLabel`, `FinanceEntryType::Payment`.
- Produces:
  - `LedgerService::pay(FinanceObligation $obligation, FinanceAccount $account, int $amount, CarbonInterface $date, ?User $actor = null, ?string $description = null): FinanceEntry`
  - `income(...)`/`expense(...)` imzasının sonuna `?FinanceParty $party = null` eklenir (mevcut çağıranlar bozulmaz).
  - `reverse()` iptal kaydına `party_id` ve `obligation_id`'yi kopyalar ve etkilenen yükümlülüklerin durumunu yeniden hesaplar.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private FinanceBalanceService $balances;

    private FinanceAccount $bank;

    private FinanceParty $supplier;

    private FinanceCategory $material;

    private FinanceObligation $invoice;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->actor);

        $this->ledger = app(LedgerService::class);
        $this->balances = app(FinanceBalanceService::class);
        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 5_000_000, 'opening_date' => '2026-10-01']);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $this->material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
        $this->invoice = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Payable, 'amount' => 1_500_000, 'due_on' => CarbonImmutable::parse('2026-10-30'),
            'party' => $this->supplier, 'category' => $this->material, 'description' => 'Fatura F-77',
        ]);
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

    public function test_partial_then_full_payment_moves_status_and_balance(): void
    {
        $first = $this->ledger->pay($this->invoice, $this->bank, 500_000, $this->day('2026-10-14'), $this->actor);

        $this->assertSame(FinanceEntryType::Payment, $first->type);
        $this->assertSame(-1, $first->direction);
        $this->assertSame($this->supplier->id, $first->party_id);
        $this->assertSame($this->material->id, $first->category_id);
        $this->assertSame('Fatura F-77', $first->description);
        $this->assertSame('Ödeme', $first->label());
        $this->assertSame(ObligationStatus::Partial, $this->invoice->fresh()->status);
        $this->assertSame(4_500_000, $this->balances->balance($this->bank));

        $this->ledger->pay($this->invoice->fresh(), $this->bank, 1_000_000, $this->day('2026-10-14'), $this->actor, 'Kalan');

        $this->assertSame(ObligationStatus::Paid, $this->invoice->fresh()->status);
        $this->assertSame(0, $this->balances->remaining($this->invoice->fresh()));
        $this->assertSame(3_500_000, $this->balances->balance($this->bank));
    }

    public function test_receivable_payment_increases_balance(): void
    {
        $customer = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $claim = app(ObligationService::class)->create([
            'direction' => ObligationDirection::Receivable, 'amount' => 200_000, 'due_on' => $this->day('2026-10-20'),
            'party' => $customer, 'description' => 'Ekim',
        ]);

        $entry = $this->ledger->pay($claim, $this->bank, 200_000, $this->day('2026-10-14'), $this->actor);

        $this->assertSame(1, $entry->direction);
        $this->assertSame('Tahsilat', $entry->label());
        $this->assertSame(5_200_000, $this->balances->balance($this->bank));
        $this->assertSame(ObligationStatus::Paid, $claim->fresh()->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('Kalan tutardan');

        $this->ledger->pay($this->invoice, $this->bank, 1_500_001, $this->day('2026-10-14'), $this->actor);
    }

    public function test_paid_or_cancelled_obligation_cannot_be_paid(): void
    {
        $this->ledger->pay($this->invoice, $this->bank, 1_500_000, $this->day('2026-10-14'), $this->actor);

        try {
            $this->ledger->pay($this->invoice->fresh(), $this->bank, 1, $this->day('2026-10-14'), $this->actor);
            $this->fail('Kapanmış kayda ödeme reddedilmeliydi.');
        } catch (FinanceRuleException) {
        }

        $other = app(ObligationService::class)->create(['direction' => ObligationDirection::Payable, 'amount' => 10, 'due_on' => $this->day('2026-10-30'), 'description' => 'x']);
        app(ObligationService::class)->cancel($other, 'yanlış');

        $this->expectException(FinanceRuleException::class);
        $this->ledger->pay($other->fresh(), $this->bank, 1, $this->day('2026-10-14'), $this->actor);
    }

    public function test_payment_follows_ledger_rules(): void
    {
        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('İleri tarihli');

        $this->ledger->pay($this->invoice, $this->bank, 100, $this->day('2026-10-15'), $this->actor);
    }

    public function test_reversing_a_payment_reopens_the_obligation(): void
    {
        $this->ledger->pay($this->invoice, $this->bank, 500_000, $this->day('2026-10-14'), $this->actor);
        $second = $this->ledger->pay($this->invoice->fresh(), $this->bank, 1_000_000, $this->day('2026-10-14'), $this->actor);

        $reversal = $this->ledger->reverse($second, 'Yanlış hesap', $this->actor)->first();

        $this->assertSame($this->invoice->id, $reversal->obligation_id);
        $this->assertSame($this->supplier->id, $reversal->party_id);
        $this->assertSame(ObligationStatus::Partial, $this->invoice->fresh()->status);
        $this->assertSame(1_000_000, $this->balances->remaining($this->invoice->fresh()));
        $this->assertSame(4_500_000, $this->balances->balance($this->bank));
    }

    public function test_income_and_expense_can_carry_a_party(): void
    {
        $entry = $this->ledger->expense($this->bank, 100, $this->day('2026-10-14'), $this->material, $this->actor, 'Peşin alım', null, null, $this->supplier);

        $this->assertSame($this->supplier->id, $entry->party_id);
    }

    public function test_records_from_another_organization_are_rejected(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreignCategory = FinanceCategory::create(['organization_id' => $otherOrganization->id, 'name' => 'Yabancı', 'direction' => CategoryDirection::Expense]);

        $this->expectException(FinanceRuleException::class);
        $this->expectExceptionMessage('aynı organizasyon');

        $this->ledger->expense($this->bank, 100, $this->day('2026-10-14'), $foreignCategory, $this->actor);
    }
}
```

- [ ] **Step 2: Kırmızı** — `php artisan test --filter=LedgerPaymentTest`.

- [ ] **Step 3: Uygula** (`LedgerService.php`)

1. Kurucu ekle: `public function __construct(private readonly FinanceBalanceService $balances) {}` ve `use` satırları: `FinanceObligation`, `FinanceParty`, `ObligationStatus`, `Illuminate\Database\Eloquent\Model`.
2. `income` ve `expense` imzalarının sonuna `?FinanceParty $party = null,` ekle ve `categorized(...)` çağrısına `$party` geçir; `categorized` imzasına `?FinanceParty $party` ekle; içinde:

```php
        $this->ensureSameOrganization($account, $category, $branch, $party);

        if ($party !== null && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.");
        }
```

ve `record(...)` öznitelik dizisine `'party_id' => $party?->id,`.
3. `transfer()` içinde `ensureWritable` çağrılarından sonra: `$this->ensureSameOrganization($from, $to, $commissionCategory);`.
4. Yeni metod (transfer'dan sonra):

```php
    /**
     * Yükümlülüğe ödeme/tahsilat (kısmi olabilir). Yükümlülük satırı kilitlenir:
     * kalan kontrolü ile yazma arasında ikinci bir ödeme sığmasın. Ödeme
     * kaydı yükümlülüğün kategorisini, carisini ve şubesini taşır — raporlar
     * ödemeyi o kategoride gelir/gider sayar.
     */
    public function pay(
        FinanceObligation $obligation,
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        ?User $actor = null,
        ?string $description = null,
    ): FinanceEntry {
        $this->ensurePositive($amount);
        $this->ensureWritable($account, $date);
        $this->ensureSameOrganization($account, $obligation);

        return DB::transaction(function () use ($obligation, $account, $amount, $date, $actor, $description) {
            $locked = FinanceObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new FinanceRuleException('Bu kayıt kapanmış veya iptal edilmiş; ödeme yapılamaz.');
            }

            $remaining = $this->balances->remaining($locked);

            if ($amount > $remaining) {
                throw new FinanceRuleException('Kalan tutardan ('.Money::format($remaining).') fazla ödeme yapılamaz.');
            }

            $entry = $this->record($account, FinanceEntryType::Payment, $locked->direction->entryDirection(), $amount, $date, $actor, [
                'category_id' => $locked->category_id,
                'party_id' => $locked->party_id,
                'obligation_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'description' => $this->clean($description) ?? $locked->description,
                'document_no' => $locked->document_no,
            ]);

            $this->syncObligationStatus($locked);

            return $entry;
        });
    }
```

(`use App\Domain\Finance\Support\Money;` ekle.)
5. `reverse()` içinde `record` öznitelik dizisine `'party_id' => $target->party_id, 'obligation_id' => $target->obligation_id,` ekle; `DB::transaction` kapanışını şu biçime getir ki iptaller yazıldıktan sonra yükümlülükler senkronlansın:

```php
            return DB::transaction(function () use ($targets, $today, $actor, $reason) {
                $reversals = $targets->map(fn (FinanceEntry $target) => $this->record(/* mevcut argümanlar + party_id, obligation_id */));

                $targets->pluck('obligation_id')->filter()->unique()
                    ->each(fn (int $obligationId) => $this->syncObligationStatus(FinanceObligation::whereKey($obligationId)->lockForUpdate()->firstOrFail()));

                return $reversals;
            });
```

6. Özel yardımcılar (sınıfın sonuna, `clean`'den önce):

```php
    /**
     * Durum defterden türetilir; iptal edilmiş kayda dokunulmaz.
     */
    private function syncObligationStatus(FinanceObligation $obligation): void
    {
        if ($obligation->status === ObligationStatus::Cancelled) {
            return;
        }

        $paid = $this->balances->paid($obligation);
        $status = match (true) {
            $paid <= 0 => ObligationStatus::Open,
            $paid >= $obligation->amount => ObligationStatus::Paid,
            default => ObligationStatus::Partial,
        };

        if ($obligation->status !== $status) {
            $obligation->update(['status' => $status]);
        }
    }

    /**
     * Savunma: ekranlar kapsamlı modeller geçiriyor ama defter, başka bir
     * organizasyonun kategorisini/carisini/şubesini asla yazmamalı.
     */
    private function ensureSameOrganization(?Model ...$models): void
    {
        $organizationIds = collect($models)->filter()->map(fn (Model $model) => (int) $model->organization_id)->unique();

        if ($organizationIds->count() > 1) {
            throw new FinanceRuleException('Kayıtlar aynı organizasyona ait değil.');
        }
    }
```

7. Sınıf docblock'una bir cümle ekle: "Yükümlülük ödemeleri de buradan geçer (pay); yükümlülüğün durumu her ödeme ve iptalden sonra defterden yeniden hesaplanır."

- [ ] **Step 4: Yeşil** — `php artisan test --filter='LedgerPaymentTest|LedgerServiceTest|FinanceEntriesScreenTest'` PASS (Aşama 34 testleri bozulmamalı).
- [ ] **Step 5: Commit** — `feat: LedgerService yükümlülük ödemesi, iptalde durum senkronu, cari ve organizasyon kontrolü — Aşama 35 (4/10)`

---

### Task 5: Tekrarlayan kalemler ve günlük üretim

**Files:**
- Create: `app/Domain/Finance/Services/RecurringService.php`, `app/Domain/Finance/Jobs/GenerateRecurringObligationsJob.php`, `app/Console/Commands/GenerateRecurringObligationsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Finance/RecurringServiceTest.php`

**Interfaces:**
- Produces:
  - `RecurringService::create(array{direction: ObligationDirection, name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, frequency: RecurringFrequency, starts_on: CarbonInterface, ends_on?: ?CarbonInterface}): FinanceRecurring`
  - `RecurringService::update(FinanceRecurring, array{name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, ends_on?: ?CarbonInterface}): FinanceRecurring` (sıklık ve başlangıç değişmez; yalnız henüz üretilmemiş dönemleri etkiler)
  - `deactivate(FinanceRecurring)`, `activate(FinanceRecurring)` (aktifleştirmede geçmişte kalan `next_due_on` bugüne veya sonrasına ilerletilir — geçmiş dönemler toplu üretilmez)
  - `RecurringService::HORIZON_DAYS = 35`, `generateDue(?CarbonImmutable $today = null): int` (üretilen yükümlülük sayısı)
  - Komut `finance:generate-recurring`; zamanlama `dailyAt('03:15')` (UTC; Türkiye 06:15).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\RecurringService;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecurringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecurringService $recurrings;

    private Organization $organization;

    private FinanceCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->recurrings = app(RecurringService::class);
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function monthlyRent(array $overrides = []): array
    {
        return [
            'direction' => ObligationDirection::Payable, 'name' => 'Kira', 'amount' => 2_000_000,
            'category' => $this->rent, 'frequency' => RecurringFrequency::Monthly,
            'starts_on' => CarbonImmutable::parse('2026-10-05'), ...$overrides,
        ];
    }

    public function test_create_derives_day_and_first_due_date(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());

        $this->assertSame(5, $recurring->day);
        $this->assertSame('2026-10-05', $recurring->next_due_on->toDateString());
    }

    public function test_generation_covers_the_horizon_once_and_is_idempotent(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());

        // Bugün 14.10, ufuk 18.11: 05.10 (geçmiş ama tanımdan sonra ilk) ve 05.11 üretilir.
        $this->assertSame(2, $this->recurrings->generateDue());
        $this->assertSame(0, $this->recurrings->generateDue());

        $dates = FinanceObligation::orderBy('due_on')->pluck('due_on')->map->toDateString()->all();
        $this->assertSame(['2026-10-05', '2026-11-05'], $dates);
        $this->assertSame('2026-12-05', $recurring->fresh()->next_due_on->toDateString());

        $first = FinanceObligation::orderBy('due_on')->first();
        $this->assertSame('Kira — Ekim 2026', $first->description);
        $this->assertSame(2_000_000, $first->amount);
        $this->assertSame($this->rent->id, $first->category_id);
        $this->assertSame(ObligationStatus::Open, $first->status);
        $this->assertSame($this->organization->id, $first->organization_id);
    }

    public function test_generation_runs_without_a_logged_in_user(): void
    {
        $this->recurrings->create($this->monthlyRent());
        auth()->logout();

        Artisan::call('finance:generate-recurring');

        $this->assertSame(2, FinanceObligation::withoutGlobalScopes()->count());
    }

    public function test_ends_on_stops_generation_and_marks_definition_passive(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent(['ends_on' => CarbonImmutable::parse('2026-10-31')]));

        $this->assertSame(1, $this->recurrings->generateDue());
        $this->assertSame('passive', $recurring->fresh()->status);
    }

    public function test_passive_definition_and_inactive_organization_are_skipped(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $this->recurrings->deactivate($recurring);
        $this->assertSame(0, $this->recurrings->generateDue());

        $this->recurrings->activate($recurring->fresh());
        $this->organization->update(['status' => OrganizationStatus::Passive]);
        $this->assertSame(0, $this->recurrings->generateDue());
    }

    public function test_activation_skips_missed_periods_instead_of_backfilling(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent(['starts_on' => CarbonImmutable::parse('2026-06-05')]));
        $this->recurrings->deactivate($recurring);

        $this->recurrings->activate($recurring->fresh());

        $this->assertSame('2026-11-05', $recurring->fresh()->next_due_on->toDateString());
    }

    public function test_update_changes_future_amount_only(): void
    {
        $recurring = $this->recurrings->create($this->monthlyRent());
        $this->recurrings->generateDue();

        $this->recurrings->update($recurring->fresh(), ['name' => 'Kira', 'amount' => 2_200_000, 'category' => $this->rent]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 09:00:00', 'Europe/Istanbul'));
        $this->recurrings->generateDue();

        $this->assertSame([2_000_000, 2_000_000, 2_200_000], FinanceObligation::orderBy('due_on')->pluck('amount')->all());
    }

    public function test_validation(): void
    {
        foreach ([
            ['amount' => 0],
            ['name' => ' '],
            ['category' => FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income])],
            ['ends_on' => CarbonImmutable::parse('2026-10-01')],
            ['party' => FinanceParty::create(['name' => 'Ev Sahibi', 'kind' => PartyKind::Other, 'status' => 'passive'])],
        ] as $overrides) {
            try {
                $this->recurrings->create($this->monthlyRent($overrides));
                $this->fail('FinanceRuleException bekleniyordu: '.json_encode(array_keys($overrides)));
            } catch (FinanceRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Step 2: Kırmızı** — `php artisan test --filter=RecurringServiceTest`.

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Services/RecurringService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tekrarlayan kalemler (kira, maaş, abonelik). Günlük iş, aktif tanımlardan
 * önümüzdeki HORIZON_DAYS gün içinde vadesi gelecek dönemlerin yükümlülüğünü
 * üretir. (recurring_id, due_on) tekil olduğu için iş iki kez çalışsa da çift
 * kayıt oluşmaz. Oturum olmadan (zamanlayıcı) çalışır: organizasyon açıkça verilir.
 */
class RecurringService
{
    public const HORIZON_DAYS = 35;

    /**
     * @param  array{direction: ObligationDirection, name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, frequency: RecurringFrequency, starts_on: CarbonInterface, ends_on?: ?CarbonInterface}  $data
     */
    public function create(array $data): FinanceRecurring
    {
        $startsOn = CarbonImmutable::parse($data['starts_on']->toDateString());

        return FinanceRecurring::create([
            ...$this->attributes($data, $data['direction'], $startsOn),
            'direction' => $data['direction'],
            'frequency' => $data['frequency'],
            'day' => $data['frequency']->dayFor($startsOn),
            'starts_on' => $startsOn->toDateString(),
            'next_due_on' => $startsOn->toDateString(),
        ]);
    }

    /**
     * @param  array{name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, ends_on?: ?CarbonInterface}  $data
     */
    public function update(FinanceRecurring $recurring, array $data): FinanceRecurring
    {
        $recurring->update($this->attributes($data, $recurring->direction, $recurring->starts_on));

        return $recurring;
    }

    public function deactivate(FinanceRecurring $recurring): FinanceRecurring
    {
        $recurring->update(['status' => 'passive']);

        return $recurring;
    }

    /**
     * Pasifken kaçırılan dönemler toplu üretilmez: sonraki vade bugüne veya
     * sonrasına ilerletilir (geçmiş kira yükümlülükleri sessizce birikmesin).
     */
    public function activate(FinanceRecurring $recurring): FinanceRecurring
    {
        $today = FinanceCalendar::today();
        $next = $recurring->next_due_on;

        while ($next->lt($today)) {
            $next = $recurring->frequency->next($next, $recurring->day);
        }

        $recurring->update(['status' => 'active', 'next_due_on' => $next->toDateString()]);

        return $recurring;
    }

    public function generateDue(?CarbonImmutable $today = null): int
    {
        $horizon = ($today ?? FinanceCalendar::today())->addDays(self::HORIZON_DAYS);
        $activeOrganizations = Organization::where('status', OrganizationStatus::Active)->pluck('id');
        $created = 0;

        FinanceRecurring::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereIn('organization_id', $activeOrganizations)
            ->whereDate('next_due_on', '<=', $horizon->toDateString())
            ->chunkById(100, function (Collection $recurrings) use ($horizon, &$created) {
                foreach ($recurrings as $recurring) {
                    $created += $this->generateFor($recurring, $horizon);
                }
            });

        return $created;
    }

    private function generateFor(FinanceRecurring $recurring, CarbonImmutable $horizon): int
    {
        return DB::transaction(function () use ($recurring, $horizon) {
            $created = 0;
            $next = $recurring->next_due_on;

            while ($next->lte($horizon) && ($recurring->ends_on === null || $next->lte($recurring->ends_on))) {
                $obligation = FinanceObligation::withoutGlobalScopes()->firstOrCreate(
                    ['recurring_id' => $recurring->id, 'due_on' => $next->toDateString()],
                    [
                        'organization_id' => $recurring->organization_id,
                        'direction' => $recurring->direction,
                        'party_id' => $recurring->party_id,
                        'category_id' => $recurring->category_id,
                        'amount' => $recurring->amount,
                        'description' => $recurring->name.' — '.$recurring->frequency->periodLabel($next),
                    ],
                );

                $created += $obligation->wasRecentlyCreated ? 1 : 0;
                $next = $recurring->frequency->next($next, $recurring->day);
            }

            $ended = $recurring->ends_on !== null && $next->gt($recurring->ends_on);
            $recurring->update(['next_due_on' => $next->toDateString(), 'status' => $ended ? 'passive' : $recurring->status]);

            return $created;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data, ObligationDirection $direction, CarbonImmutable $startsOn): array
    {
        $name = trim($data['name']);
        $party = $data['party'] ?? null;
        $endsOn = $data['ends_on'] ?? null;

        if ($name === '') {
            throw new FinanceRuleException('Ad yazın (örn. Kira, Maaş).');
        }

        if ($data['amount'] <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.');
        }

        if (! $data['category']->isActive() || $data['category']->direction !== $direction->categoryDirection()) {
            throw new FinanceRuleException('Aktif bir '.mb_strtolower($direction->categoryDirection()->label()).' kategorisi seçin.');
        }

        if ($party !== null && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.");
        }

        if ($endsOn !== null && $endsOn->toDateString() < $startsOn->toDateString()) {
            throw new FinanceRuleException('Bitiş tarihi başlangıçtan önce olamaz.');
        }

        return [
            'name' => $name,
            'amount' => $data['amount'],
            'category_id' => $data['category']->id,
            'party_id' => $party?->id,
            'ends_on' => $endsOn?->toDateString(),
        ];
    }
}
```

`app/Domain/Finance/Jobs/GenerateRecurringObligationsJob.php`:

```php
<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\RecurringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Zamanlayıcı tetikler, iş kuyrukta çalışır (mimari.md Bölüm 8; bkz. ScanStockLevelsJob).
 */
class GenerateRecurringObligationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RecurringService $recurrings): void
    {
        $recurrings->generateDue();
    }
}
```

`app/Console/Commands/GenerateRecurringObligationsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Finance\Jobs\GenerateRecurringObligationsJob;
use Illuminate\Console\Command;

class GenerateRecurringObligationsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'finance:generate-recurring';

    /**
     * @var string
     */
    protected $description = 'Tekrarlayan finans kalemlerinden (kira, maaş...) önümüzdeki 35 günün ödeme/tahsilat kayıtlarını üretir.';

    public function handle(): int
    {
        GenerateRecurringObligationsJob::dispatch();

        $this->info('Tekrarlayan kalem üretimi kuyruğa alındı.');

        return self::SUCCESS;
    }
}
```

`routes/console.php` sonuna:

```php
// Faz 5 (Aşama 35): tekrarlayan finans kalemlerinden 35 gün ilerisinin
// yükümlülükleri. Tekil (recurring_id, due_on) sayesinde tekrar çalışması zararsız.
Schedule::command('finance:generate-recurring')->dailyAt('03:15');
```

> Testlerde `QUEUE_CONNECTION=sync` olduğu için `Artisan::call` işi hemen çalıştırır.

- [ ] **Step 4: Yeşil** · **Step 5: Commit** — `feat: Finans tekrarlayan kalemler ve günlük yükümlülük üretimi — Aşama 35 (5/10)`

---

### Task 6: Vade bildirimleri

**Files:**
- Create: `app/Domain/Finance/Services/FinanceDueAlertService.php`, `app/Domain/Finance/Notifications/FinanceDueNotification.php`, `app/Domain/Finance/Jobs/ScanFinanceDueJob.php`, `app/Console/Commands/ScanFinanceDueCommand.php`
- Modify: `routes/console.php`, `resources/views/pages/notifications/⚡index.blade.php` (`KINDS`'e `'finance' => 'Finans'`)
- Test: `tests/Feature/Finance/FinanceDueAlertTest.php`

**Interfaces:**
- Produces: `FinanceDueAlertService::NOTICE_DAYS = 3`, `scan(?CarbonImmutable $today = null): int` (gönderilen bildirim sayısı); `FinanceDueNotification(int $obligationId, string $state /* 'upcoming'|'overdue' */, string $title, string $message, string $url)` veritabanı verisi: `kind='finance'`, `level` (upcoming → `'low'`, overdue → `'critical'`), `title`, `message`, `url`, `obligation_id`, `state`. Komut `finance:scan-due`, zamanlama `dailyAt('05:00')` (UTC; Türkiye 08:00). Rota `finance.obligations` Task 8'de tanımlanır; bildirim URL'si `url('/finans/odemeler')` ile üretilir ki bu görev rotaya bağımlı olmasın.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Notifications\FinanceDueNotification;
use App\Domain\Finance\Services\FinanceDueAlertService;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FinanceDueAlertTest extends TestCase
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

    private function obligation(string $dueOn, array $overrides = []): FinanceObligation
    {
        return FinanceObligation::create([
            'organization_id' => $this->organization->id, 'direction' => ObligationDirection::Payable,
            'amount' => 1_500_000, 'due_on' => $dueOn, 'description' => 'Fatura F-77', ...$overrides,
        ]);
    }

    private function financeStaff(bool $read): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => $read ? Module::Finance->value : Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    public function test_upcoming_and_overdue_obligations_notify_admin_and_finance_staff(): void
    {
        $upcoming = $this->obligation('2026-10-16');
        $overdue = $this->obligation('2026-10-10', ['direction' => ObligationDirection::Receivable, 'description' => 'ABC Sigorta Eylül']);
        $this->obligation('2026-10-30');
        $this->obligation('2026-10-15', ['status' => ObligationStatus::Paid]);
        $this->obligation('2026-10-15', ['status' => ObligationStatus::Cancelled]);
        $financeStaff = $this->financeStaff(true);
        $otherStaff = $this->financeStaff(false);

        $sent = app(FinanceDueAlertService::class)->scan();

        $this->assertSame(4, $sent);
        $this->assertSame(2, $this->admin->notifications()->count());
        $this->assertSame(2, $financeStaff->notifications()->count());
        $this->assertSame(0, $otherStaff->notifications()->count());

        $overdueNotice = $this->admin->notifications()->get()->firstWhere('data.obligation_id', $overdue->id);
        $this->assertSame('finance', $overdueNotice->data['kind']);
        $this->assertSame('overdue', $overdueNotice->data['state']);
        $this->assertSame('critical', $overdueNotice->data['level']);
        $this->assertStringContainsString('ABC Sigorta Eylül', $overdueNotice->data['message']);
        $this->assertStringEndsWith('/finans/odemeler', $overdueNotice->data['url']);
        $this->assertSame('low', $this->admin->notifications()->get()->firstWhere('data.obligation_id', $upcoming->id)->data['level']);
    }

    public function test_same_state_is_not_repeated_while_unread_but_state_change_notifies_again(): void
    {
        $this->obligation('2026-10-16');
        $service = app(FinanceDueAlertService::class);

        $this->assertSame(1, $service->scan());
        $this->assertSame(0, $service->scan());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 09:00:00', 'Europe/Istanbul'));
        $this->assertSame(1, $service->scan());
        $this->assertSame(['upcoming', 'overdue'], $this->admin->notifications()->oldest()->get()->pluck('data.state')->all());
    }

    public function test_command_runs_without_session_and_skips_inactive_organizations(): void
    {
        $this->obligation('2026-10-16');
        $passive = Organization::create(['name' => 'Kapalı', 'status' => 'passive', 'plan' => 'starter']);
        User::factory()->create(['organization_id' => $passive->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        FinanceObligation::create(['organization_id' => $passive->id, 'direction' => ObligationDirection::Payable, 'amount' => 1, 'due_on' => '2026-10-15', 'description' => 'x']);

        Artisan::call('finance:scan-due');

        $this->assertSame(FinanceDueNotification::class, $this->admin->notifications()->first()->type);
        $this->assertSame(1, DatabaseNotification::count());
    }
}
```

- [ ] **Step 2: Kırmızı** — `php artisan test --filter=FinanceDueAlertTest`.

- [ ] **Step 3: Uygula**

`app/Domain/Finance/Notifications/FinanceDueNotification.php`:

```php
<?php

namespace App\Domain\Finance\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Vadesi yaklaşan veya geçmiş ödeme/tahsilat. obligation_id ve state, aynı
 * durumun okunmadan tekrar gönderilmemesi için tutulur (stok uyarılarıyla aynı desen).
 */
class FinanceDueNotification extends Notification
{
    public function __construct(
        public readonly int $obligationId,
        public readonly string $state,
        public readonly string $title,
        public readonly string $message,
        public readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'finance',
            'level' => $this->state === 'overdue' ? 'critical' : 'low',
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'obligation_id' => $this->obligationId,
            'state' => $this->state,
        ];
    }
}
```

`app/Domain/Finance/Services/FinanceDueAlertService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Notifications\FinanceDueNotification;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Vade taraması: NOTICE_DAYS gün içinde vadesi dolacak (upcoming) veya geçmiş
 * (overdue) açık yükümlülükler için Admin'e ve finans okuma yetkili personele
 * uygulama içi bildirim. Aynı yükümlülük için aynı durumda okunmamış bildirim
 * varsa tekrar gönderilmez; durum kötüleşirse (yaklaşan → geçmiş) yenisi gider.
 */
class FinanceDueAlertService
{
    public const NOTICE_DAYS = 3;

    public function __construct(private readonly FinanceBalanceService $balances) {}

    public function scan(?CarbonImmutable $today = null): int
    {
        $today ??= FinanceCalendar::today();
        $activeOrganizations = Organization::where('status', OrganizationStatus::Active)->pluck('id');
        $sent = 0;

        FinanceObligation::withoutGlobalScopes()
            ->whereIn('organization_id', $activeOrganizations)
            ->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial])
            ->whereDate('due_on', '<=', $today->addDays(self::NOTICE_DAYS)->toDateString())
            ->chunkById(200, function (Collection $obligations) use ($today, &$sent) {
                foreach ($obligations->groupBy('organization_id') as $organizationId => $group) {
                    $recipients = $this->recipients((int) $organizationId);

                    foreach ($group as $obligation) {
                        $sent += $this->notify($obligation, $recipients, $today);
                    }
                }
            });

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(FinanceObligation $obligation, Collection $recipients, CarbonImmutable $today): int
    {
        $state = $obligation->due_on->toDateString() < $today->toDateString() ? 'overdue' : 'upcoming';
        $remaining = Money::format($this->balances->remaining($obligation));
        $what = $obligation->direction->paymentLabel();
        $title = $state === 'overdue' ? "Vadesi geçmiş {$what}" : "Vadesi yaklaşan {$what}";
        $message = "{$obligation->description} — kalan {$remaining}, vade {$obligation->due_on->format('d.m.Y')}";
        $sent = 0;

        foreach ($recipients as $recipient) {
            $alreadyNotified = $recipient->unreadNotifications()
                ->where('type', FinanceDueNotification::class)
                ->where('data->obligation_id', $obligation->id)
                ->where('data->state', $state)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $recipient->notify(new FinanceDueNotification($obligation->id, $state, $title, $message, url('/finans/odemeler')));
            $sent++;
        }

        return $sent;
    }

    /**
     * Finans organizasyon geneli (karar 2.9): Admin + finans okuma yetkili
     * personel. Finans yetkisi yalnızca "tüm şubeler" kapsamıyla verilebildiği
     * için şube süzgeci gerekmez.
     *
     * @return Collection<int, User>
     */
    private function recipients(int $organizationId): Collection
    {
        return User::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('module', Module::Finance->value)->where('can_read', true));
            })
            ->get();
    }
}
```

> `whereDate('due_on', ...)` ve `->where('data->obligation_id', $id)` SQLite ile PostgreSQL'de çalışır (stok uyarıları aynı JSON sorgusunu kullanıyor). `chunkById` id sırasıyla çalışır; organizasyon gruplaması parça içinde `groupBy` ile yapılır.

`app/Domain/Finance/Jobs/ScanFinanceDueJob.php` ve `app/Console/Commands/ScanFinanceDueCommand.php`: Task 5'teki iş ve komutla aynı biçimde; iş `FinanceDueAlertService::scan()` çağırır; komut imzası `finance:scan-due`, açıklama `'Vadesi 3 gün içinde dolacak veya geçmiş ödeme/tahsilatlar için Admin ve finans yetkili personele bildirim oluşturur.'`, çıktı `'Vade taraması kuyruğa alındı.'`.

`routes/console.php` sonuna:

```php
// Faz 5 (Aşama 35): vadesi yaklaşan/geçmiş ödeme ve tahsilat bildirimi. Tekrarlayan
// kalem üretiminden sonra çalışır ki yeni üretilen yükümlülükler de görülsün.
Schedule::command('finance:scan-due')->dailyAt('05:00');
```

`⚡index.blade.php` (bildirimler) `KINDS` sabitine `'finance' => 'Finans',` ekle.

- [ ] **Step 4: Yeşil** — `php artisan test --filter='FinanceDueAlertTest|NotificationsScreenTest'`.
- [ ] **Step 5: Commit** — `feat: Finans vade bildirimleri — yaklaşan ve geçmiş ödeme/tahsilat — Aşama 35 (6/10)`

---

### Task 7: Cariler ekranları

**Önce:** `frontend-design` skill'ini yükle. Görsel dil Aşama 34'ün `⚡accounts.blade.php` sayfasıyla aynı (başlık bloğu "Finans" + açıklama, `x-finance-tabs`, `border-line` tablo kartı, `x-modal` form, `bg-panel-900` birincil düğme, dar ekranda ikincil sütunlar `hidden sm:table-cell` ve `px-3 sm:px-5`).

**Files:**
- Modify: `routes/web.php` (finans rotalarının yanına), `resources/views/components/finance-tabs.blade.php`
- Create: `resources/views/pages/finance/⚡parties.blade.php`, `resources/views/pages/finance/⚡party.blade.php`
- Test: `tests/Feature/Finance/FinancePartiesScreenTest.php`

**Interfaces:**
- Produces: rotalar `finance.parties` (`/finans/cariler`, `pages::finance.parties`) ve `finance.parties.show` (`/finans/cariler/{party}`, `pages::finance.party`, rota model bağlaması `FinanceParty` — global kapsam sayesinde başka organizasyonun carisi 404). Sekme sırası: Hareketler, Ödemeler ve Tahsilatlar (`finance.obligations`, Task 8), Cariler, Tekrarlayanlar (`finance.recurrings`, Task 9), Hesaplar, Kategoriler. **Bu görevde** sekmeler dizisine yalnızca `finance.parties` eklenir; Task 8 ve 9 kendi sekmelerini ekler (rota yokken `route()` hata verir).
- Cariler bileşeni: özellikler `$editingId (?int)`, `$showForm`, `$name`, `$kind ('customer')`, `$phone`, `$email`, `$taxNumber`, `$defaultTermDays (string)`, `$note`, `$search`, `$filterKind`; metodlar `openForm(?int $partyId = null)`, `closeForm()`, `save()`, `deactivate(int)`, `activate(int)`.

Cariler sayfası (`⚡parties.blade.php`) PHP bölümü:

```php
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Support\PartyKind;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $kind = 'customer';

    public string $phone = '';

    public string $email = '';

    public string $taxNumber = '';

    public string $defaultTermDays = '';

    public string $note = '';

    public string $search = '';

    public string $filterKind = '';

    public function openForm(?int $partyId = null): void
    {
        Gate::authorize($partyId === null ? 'finance.create' : 'finance.update');

        $this->closeForm();

        if ($partyId !== null) {
            $party = FinanceParty::findOrFail($partyId);
            $this->editingId = $party->id;
            $this->name = $party->name;
            $this->kind = $party->kind->value;
            $this->phone = (string) $party->phone;
            $this->email = (string) $party->email;
            $this->taxNumber = (string) $party->tax_number;
            $this->defaultTermDays = $party->default_term_days === null ? '' : (string) $party->default_term_days;
            $this->note = (string) $party->note;
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'name', 'kind', 'phone', 'email', 'taxNumber', 'defaultTermDays', 'note');
        $this->resetValidation();
    }

    public function save(FinancePartyService $parties): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(PartyKind::class)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'taxNumber' => ['nullable', 'string', 'max:20'],
            'defaultTermDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'ad', 'defaultTermDays' => 'vade (gün)', 'taxNumber' => 'vergi no']);

        $data = [
            'name' => $validated['name'],
            'kind' => PartyKind::from($validated['kind']),
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'tax_number' => $validated['taxNumber'],
            'default_term_days' => $validated['defaultTermDays'] === '' || $validated['defaultTermDays'] === null ? null : (int) $validated['defaultTermDays'],
            'note' => $validated['note'],
        ];

        try {
            $this->editingId === null
                ? $parties->create($data)
                : $parties->update(FinanceParty::findOrFail($this->editingId), $data);
        } catch (FinanceRuleException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Cari oluşturuldu.' : 'Cari güncellendi.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function deactivate(int $partyId, FinancePartyService $parties): void
    {
        Gate::authorize('finance.update');

        $parties->deactivate(FinanceParty::findOrFail($partyId));
        session()->flash('status', 'Cari pasife alındı; açık kayıtları ve geçmişi korunur.');
    }

    public function activate(int $partyId, FinancePartyService $parties): void
    {
        Gate::authorize('finance.update');

        $parties->activate(FinanceParty::findOrFail($partyId));
        session()->flash('status', 'Cari aktifleştirildi.');
    }

    public function with(FinanceBalanceService $balances): array
    {
        $parties = FinanceParty::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->when(PartyKind::tryFrom($this->filterKind), fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByRaw("status = 'active' desc")
            ->orderBy('name')
            ->get();

        return [
            'parties' => $parties,
            'balances' => $balances->partyBalances($parties),
            'kinds' => PartyKind::cases(),
        ];
    }
};
?>
```

Şablon gereksinimleri (`⚡parties.blade.php`):
- Başlık "Finans", açıklama "Kurumlar, sigortalar ve tedarikçiler. Bakiye, açık ödeme ve tahsilat kayıtlarından hesaplanır. Hasta cari olarak açılmaz.", `@can('finance.create')` içinde "Yeni Cari" düğmesi (`openForm`), altında `<x-finance-tabs />`, `session('status')` bandı.
- Süzgeç satırı: arama kutusu (`wire:model.live.debounce.300ms="search"`, placeholder "Cari ara"), tür seçimi (`filterKind`, "Tüm türler" + `PartyKind::cases()`).
- Tablo sütunları: Cari (ad bağlantısı `route('finance.parties.show', $party)`, altında küçük yazıyla tür etiketi ve pasifse "Pasif" rozeti; dar ekranda telefon da burada), Telefon (`hidden sm:table-cell`), Alacak (`finance-in`, sıfırsa `—`), Borç (`finance-out`, sıfırsa `—`, `hidden sm:table-cell`), Net (`Money::format`, pozitif `text-finance-in`, negatif `text-finance-out`), işlemler (`@can('finance.update')`: "Düzenle" → `openForm(id)`, aktifse "Pasife al" `wire:confirm`, pasifse "Aktifleştir"). Boş durum: "Henüz cari yok. Anlaşmalı kurumlarını ve tedarikçilerini ekle."
- Modal: başlık yeni/düzenle'ye göre "Yeni Cari"/"Cariyi Düzenle"; alanlar Ad, Tür (radio düğmeleri, Kategoriler sayfasındaki `peer` deseni), Telefon, E-posta, Vergi no, Varsayılan vade (gün) — açıklama "Fatura girerken vade tarihi boş bırakılırsa bugünden bu kadar gün sonrası alınır.", Not (textarea). Hata gösterimi mevcut desen.

Cari detay sayfası (`⚡party.blade.php`) PHP bölümü:

```php
<?php

use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public FinanceParty $party;

    public function mount(FinanceParty $party): void
    {
        $this->party = $party;
    }

    public function with(FinanceBalanceService $balances): array
    {
        $obligations = FinanceObligation::where('party_id', $this->party->id)->with('category')->orderByDesc('due_on')->orderByDesc('id')->get();

        return [
            'balance' => $balances->partyBalances([$this->party])[$this->party->id],
            'obligations' => $obligations,
            'paid' => $balances->paidAmounts($obligations),
            'entries' => FinanceEntry::where('party_id', $this->party->id)->with(['account', 'reversal'])->orderByDesc('occurred_on')->orderByDesc('id')->limit(100)->get(),
        ];
    }
};
?>
```

Şablon gereksinimleri (`⚡party.blade.php`): üstte "← Cariler" bağlantısı; başlık cari adı + tür + pasif rozeti; iletişim satırı (telefon, e-posta, vergi no, varsayılan vade varsa); üç küçük özet: "Alacak" (`finance-in`), "Borç" (`finance-out`), "Net" (yorum: pozitifse "Cari size borçlu", negatifse "Siz cariye borçlusunuz", sıfırsa "Hesap kapalı"). İki bölüm: **Ödeme ve tahsilat kayıtları** (tablo: Vade, Açıklama + belge no, Yön `direction->label()`, Tutar, Kalan = `amount - paid[id]` iptalde `—`, Durum rozeti `status->label()`; vadesi geçmiş açık kayıtta vade tarihi `text-finance-out`) ve **Hareketler** (tablo: Tarih, Hesap, Tür `$entry->label()`, Açıklama, Tutar işaretli ve renkli; iptal edilmişse üstü çizili). Boş durum metinleri. Sayfa `finance.viewAny` ile korunur (rota middleware'i).

**Tests** (`tests/Feature/Finance/FinancePartiesScreenTest.php`):

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancePartiesScreenTest extends TestCase
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
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_creates_and_edits_a_party(): void
    {
        Livewire::test('pages::finance.parties')
            ->call('openForm')
            ->set('name', 'ABC Sigorta')->set('kind', 'customer')->set('defaultTermDays', '30')
            ->call('save')
            ->assertHasNoErrors();

        $party = FinanceParty::firstOrFail();
        $this->assertSame(30, $party->default_term_days);

        Livewire::test('pages::finance.parties')
            ->call('openForm', $party->id)
            ->assertSet('name', 'ABC Sigorta')
            ->set('phone', '0212 111 11 11')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0212 111 11 11', $party->fresh()->phone);
    }

    public function test_duplicate_name_shows_error(): void
    {
        FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        Livewire::test('pages::finance.parties')->set('name', 'Depo')->set('kind', 'supplier')->call('save')->assertHasErrors('name');
    }

    public function test_list_shows_derived_balance_and_party_page_shows_records(): void
    {
        $party = FinanceParty::create(['name' => 'ABC Sigorta', 'kind' => PartyKind::Customer]);
        $obligation = FinanceObligation::create(['direction' => ObligationDirection::Receivable, 'party_id' => $party->id, 'amount' => 500_000, 'due_on' => '2026-10-20', 'description' => 'Ekim faturası']);
        $account = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 0, 'opening_date' => '2026-10-01']);
        app(LedgerService::class)->pay($obligation, $account, 200_000, CarbonImmutable::parse('2026-10-14'), $this->admin);

        $this->get('/finans/cariler')->assertOk()->assertSee('ABC Sigorta')->assertSee('3.000,00 ₺');
        $this->get('/finans/cariler/'.$party->id)->assertOk()->assertSee('Ekim faturası')->assertSee('Tahsilat')->assertSee('Cari size borçlu');
    }

    public function test_party_of_another_organization_is_not_found(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreign = FinanceParty::create(['organization_id' => $other->id, 'name' => 'Yabancı', 'kind' => PartyKind::Other]);

        $this->get('/finans/cariler/'.$foreign->id)->assertNotFound();
    }

    public function test_read_only_staff_sees_but_cannot_change(): void
    {
        $party = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->get('/finans/cariler')->assertOk()->assertDontSee('Yeni Cari');
        Livewire::test('pages::finance.parties')->call('deactivate', $party->id)->assertForbidden();
        Livewire::test('pages::finance.parties')->set('name', 'X')->set('kind', 'other')->call('save')->assertForbidden();
    }

    public function test_deactivate_and_activate(): void
    {
        $party = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier]);

        Livewire::test('pages::finance.parties')->call('deactivate', $party->id);
        $this->assertSame('passive', $party->fresh()->status);

        Livewire::test('pages::finance.parties')->call('activate', $party->id);
        $this->assertSame('active', $party->fresh()->status);
    }
}
```

> Not: `test_list_shows_derived_balance...` 500.000 − 200.000 = 300.000 kuruş = "3.000,00 ₺" alacak/net gösterir.

Steps: kırmızı → rotaları ekle:

```php
    Route::livewire('/finans/cariler', 'pages::finance.parties')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.parties');

    Route::livewire('/finans/cariler/{party}', 'pages::finance.party')
        ->middleware(['auth', 'can:finance.viewAny'])
        ->name('finance.parties.show');
```

→ sayfaları yaz → yeşil → `npm run build` + görsel kontrol (liste dolu/boş, detay, modal; 1280 ve 500) → commit `feat: Finans Cariler ekranı ve cari detayı — Aşama 35 (7/10)`.

---

### Task 8: Ödemeler ve Tahsilatlar ekranı

**Önce:** `frontend-design`. Bu ekran günlük kullanılır: ilk bakışta "bu hafta ne ödeyeceğim, ne tahsil edeceğim, ne gecikti" görünmeli.

**Files:**
- Modify: `routes/web.php`, `finance-tabs.blade.php` (Hareketler'den sonra `'finance.obligations' => 'Ödemeler ve Tahsilatlar'`)
- Create: `resources/views/pages/finance/⚡obligations.blade.php`
- Test: `tests/Feature/Finance/FinanceObligationsScreenTest.php`

**Interfaces:**
- Produces: rota `finance.obligations` (`/finans/odemeler`, `pages::finance.obligations`). Bileşen özellikleri: sekme `$tab` (`'overdue'|'week'|'month'|'all'`, varsayılan `'week'`, `#[Url]`), `$direction` (`''|'payable'|'receivable'`, `#[Url]`); yükümlülük formu `$showForm`, `$editingId`, `$formDirection ('payable')`, `$amount`, `$dueOn`, `$partyId`, `$categoryId`, `$description`, `$documentNo`, `$branchId`; ödeme formu `$payingId (?int)`, `$payAccountId`, `$payAmount`, `$payDate`, `$payDescription`; iptal `$cancellingId (?int)`, `$cancelReason`. Metodlar: `openForm(?int $id = null, string $direction = 'payable')`, `closeForm()`, `save()`, `startPay(int)`, `cancelPay()`, `confirmPay()`, `startCancel(int)`, `abortCancel()`, `confirmCancel()`.

PHP bölümü (tam):

```php
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    private const TABS = ['overdue', 'week', 'month', 'all'];

    #[Url]
    public string $tab = 'week';

    #[Url]
    public string $direction = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formDirection = 'payable';

    public string $amount = '';

    public string $dueOn = '';

    public string $partyId = '';

    public string $categoryId = '';

    public string $description = '';

    public string $documentNo = '';

    public string $branchId = '';

    public ?int $payingId = null;

    public string $payAccountId = '';

    public string $payAmount = '';

    public string $payDate = '';

    public string $payDescription = '';

    public ?int $cancellingId = null;

    public string $cancelReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'direction'], true)) {
            $this->resetPage();
        }
    }

    public function openForm(?int $id = null, string $direction = 'payable'): void
    {
        Gate::authorize($id === null ? 'finance.create' : 'finance.update');

        $this->closeForm();

        if ($id !== null) {
            $obligation = FinanceObligation::findOrFail($id);
            $this->editingId = $obligation->id;
            $this->formDirection = $obligation->direction->value;
            $this->amount = Money::input($obligation->amount);
            $this->dueOn = $obligation->due_on->toDateString();
            $this->partyId = (string) $obligation->party_id;
            $this->categoryId = (string) $obligation->category_id;
            $this->description = $obligation->description;
            $this->documentNo = (string) $obligation->document_no;
            $this->branchId = (string) $obligation->branch_id;
        } else {
            $this->formDirection = ObligationDirection::tryFrom($direction)?->value ?? 'payable';
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'formDirection', 'amount', 'dueOn', 'partyId', 'categoryId', 'description', 'documentNo', 'branchId');
        $this->resetValidation();
    }

    public function save(ObligationService $obligations): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $this->validate([
            'formDirection' => ['required', Rule::enum(ObligationDirection::class)],
            'amount' => ['required', 'string'],
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'partyId' => ['nullable', 'integer'],
            'categoryId' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:500'],
            'documentNo' => ['nullable', 'string', 'max:100'],
            'branchId' => ['nullable', 'integer'],
        ], [], ['amount' => 'tutar', 'dueOn' => 'vade', 'description' => 'açıklama']);

        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $data = [
            'direction' => ObligationDirection::from($this->formDirection),
            'amount' => $amount,
            'due_on' => $this->dueOn !== '' ? CarbonImmutable::parse($this->dueOn) : null,
            'party' => $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null,
            'category' => $this->categoryId !== '' ? FinanceCategory::findOrFail($this->categoryId) : null,
            'description' => $this->description,
            'document_no' => $this->documentNo,
            'branch' => $this->branchId !== '' ? Branch::where('organization_id', auth()->user()->organization_id)->findOrFail($this->branchId) : null,
        ];

        try {
            $this->editingId === null
                ? $obligations->create($data)
                : $obligations->update(FinanceObligation::findOrFail($this->editingId), $data);
        } catch (FinanceRuleException $e) {
            $this->addError(str_contains($e->getMessage(), 'Vade') ? 'dueOn' : 'amount', $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Kayıt eklendi.' : 'Kayıt güncellendi.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function startPay(int $id, FinanceBalanceService $balances): void
    {
        Gate::authorize('finance.create');

        $obligation = FinanceObligation::findOrFail($id);
        $this->resetValidation();
        $this->payingId = $obligation->id;
        $this->payAmount = Money::input($balances->remaining($obligation));
        $this->payDate = FinanceCalendar::today()->toDateString();
        $this->payAccountId = '';
        $this->payDescription = '';
    }

    public function cancelPay(): void
    {
        $this->reset('payingId', 'payAccountId', 'payAmount', 'payDate', 'payDescription');
        $this->resetValidation();
    }

    public function confirmPay(LedgerService $ledger): void
    {
        Gate::authorize('finance.create');

        $this->validate([
            'payAccountId' => ['required', 'integer'],
            'payAmount' => ['required', 'string'],
            'payDate' => ['required', 'date_format:Y-m-d'],
            'payDescription' => ['nullable', 'string', 'max:500'],
        ], [], ['payAccountId' => 'hesap', 'payAmount' => 'tutar', 'payDate' => 'tarih']);

        try {
            $amount = Money::parse($this->payAmount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $obligation = FinanceObligation::findOrFail($this->payingId);

        try {
            $entry = $ledger->pay($obligation, FinanceAccount::findOrFail($this->payAccountId), $amount, CarbonImmutable::parse($this->payDate), auth()->user(), $this->payDescription);
        } catch (FinanceRuleException $e) {
            $this->addError(str_contains($e->getMessage(), 'tarih') ? 'payDate' : 'payAmount', $e->getMessage());

            return;
        }

        $this->cancelPay();
        session()->flash('status', "{$entry->label()} kaydedildi.");
    }

    public function startCancel(int $id): void
    {
        Gate::authorize('finance.update');

        $this->cancellingId = FinanceObligation::findOrFail($id)->id;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function abortCancel(): void
    {
        $this->reset('cancellingId', 'cancelReason');
    }

    public function confirmCancel(ObligationService $obligations): void
    {
        Gate::authorize('finance.update');

        try {
            $obligations->cancel(FinanceObligation::findOrFail($this->cancellingId), $this->cancelReason);
        } catch (FinanceRuleException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->abortCancel();
        session()->flash('status', 'Kayıt iptal edildi.');
    }

    public function with(FinanceBalanceService $balances): array
    {
        $today = FinanceCalendar::today();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'week';
        $direction = ObligationDirection::tryFrom($this->direction);
        $open = fn ($query) => $query->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial]);

        $obligations = FinanceObligation::with(['party', 'category'])
            ->when($direction, fn ($query) => $query->where('direction', $direction))
            ->when($tab !== 'all', $open)
            ->when($tab === 'overdue', fn ($query) => $query->whereDate('due_on', '<', $today->toDateString()))
            ->when($tab === 'week', fn ($query) => $query->whereDate('due_on', '<=', $today->addDays(7)->toDateString()))
            ->when($tab === 'month', fn ($query) => $query->whereDate('due_on', '<=', $today->addDays(30)->toDateString()))
            ->orderBy('due_on', $tab === 'all' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25);

        $openAll = FinanceObligation::query()->tap($open)->get();
        $paidAll = $balances->paidAmounts($openAll);
        $sum = fn (callable $filter) => $openAll->filter($filter)->sum(fn (FinanceObligation $o) => $o->amount - $paidAll[$o->id]);
        $isPayable = fn (FinanceObligation $o) => $o->direction === ObligationDirection::Payable;
        $weekEnd = $today->addDays(7)->toDateString();

        return [
            'obligations' => $obligations,
            'paid' => $balances->paidAmounts($obligations->getCollection()),
            'today' => $today,
            'summary' => [
                'overduePayable' => $sum(fn ($o) => $isPayable($o) && $o->due_on->toDateString() < $today->toDateString()),
                'overdueReceivable' => $sum(fn ($o) => ! $isPayable($o) && $o->due_on->toDateString() < $today->toDateString()),
                'weekPayable' => $sum(fn ($o) => $isPayable($o) && $o->due_on->toDateString() <= $weekEnd),
                'weekReceivable' => $sum(fn ($o) => ! $isPayable($o) && $o->due_on->toDateString() <= $weekEnd),
            ],
            'accounts' => FinanceAccount::where('status', 'active')->orderBy('name')->get(),
            'parties' => FinanceParty::where('status', 'active')->orderBy('name')->get(),
            'categories' => FinanceCategory::where('status', 'active')->orderBy('name')->get(),
            'branches' => Branch::where('organization_id', auth()->user()->organization_id)->where('status', 'active')->orderBy('name')->get(),
            'payingObligation' => $this->payingId ? FinanceObligation::find($this->payingId) : null,
        ];
    }
};
?>
```

Şablon gereksinimleri:
- Başlık "Finans", açıklama "Vadeli borçlar ve alacaklar. Ödendikçe kapanır; kısmi ödeme yapılabilir.", sağda `@can('finance.create')` iki düğme: "− Ödenecek ekle" (`openForm(null,'payable')`, `bg-finance-out`) ve "+ Tahsil edilecek ekle" (`openForm(null,'receivable')`, `bg-finance-in`). `<x-finance-tabs />`, durum bandı.
- Özet kartları (2×2 ızgara, dar ekranda 2 sütun): "Geciken ödeme" (`finance-out`), "Geciken tahsilat" (`finance-in`), "7 gün içinde ödenecek", "7 gün içinde tahsil edilecek" (geciken dahil; açıklama küçük yazıyla "gecikenler dahil"). Sıfırsa `—`.
- Alt sekmeler (sayfa içi düğmeler, `wire:click="$set('tab', '...')"`): Geciken / 7 gün / 30 gün / Tümü; sağda yön süzgeci (Tümü / Ödenecek / Tahsil edilecek).
- Tablo: Vade (geçmişse `text-finance-out` ve "N gün gecikti" küçük yazı; bugünse "Bugün"), Açıklama (+ belge no, cari adı bağlantısı `finance.parties.show`, kategori rozeti), Yön (`hidden sm:table-cell`), Tutar (`hidden sm:table-cell`), Kalan (yön rengiyle; iptalde `—`), Durum rozeti, işlemler: açıksa `@can('finance.create')` "Öde"/"Tahsil et" (`startPay`) — etiket `direction->paymentLabel()` fiil hâli: payable → "Öde", receivable → "Tahsil et"; `@can('finance.update')` ödemesizse "Düzenle" ve "İptal" (`startCancel`). Boş durum sekmeye göre: "Geciken kayıt yok." / "Önümüzdeki 7 günde vadesi gelen kayıt yok." vb.
- Yükümlülük modalı: Yön (radio, düzenlemede değişmez — `@disabled($editingId)`), Tutar, Vade (date; açıklama "Boş bırakırsan carinin varsayılan vadesi kullanılır"), Cari (select, "—" + aktif cariler; altında "Cari ekle →" `finance.parties` bağlantısı), Kategori (select, yalnız forma uygun yönde: payable → gider, receivable → gelir kategorileri), Açıklama (zorunlu), Belge no, Şube.
- Ödeme modalı (`payingObligation` varken): başlık `paymentLabel()`; üstte kaydın açıklaması ve kalan tutar; alanlar Hesap (aktif hesaplar), Tutar (kalan ile dolu), Tarih, Açıklama (boşsa kaydın açıklaması kullanılır). Hesap yoksa "Önce bir kasa veya banka hesabı tanımla" + bağlantı.
- İptal modalı: "Kayıt silinmez; iptal edilir ve listede 'İptal' olarak kalır. Ödemesi olan kayıt iptal edilemez." + gerekçe.

**Tests** (`tests/Feature/Finance/FinanceObligationsScreenTest.php`):

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceObligationsScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceAccount $bank;

    private FinanceParty $supplier;

    private FinanceCategory $material;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 5_000_000, 'opening_date' => '2026-10-01']);
        $this->supplier = FinanceParty::create(['name' => 'Depo', 'kind' => PartyKind::Supplier, 'default_term_days' => 30]);
        $this->material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function invoice(string $dueOn = '2026-10-16', array $overrides = []): FinanceObligation
    {
        return FinanceObligation::create(['direction' => ObligationDirection::Payable, 'party_id' => $this->supplier->id, 'category_id' => $this->material->id, 'amount' => 1_500_000, 'due_on' => $dueOn, 'description' => 'Fatura F-77', ...$overrides]);
    }

    public function test_admin_adds_a_payable_with_party_term(): void
    {
        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('amount', '15.000')->set('partyId', (string) $this->supplier->id)->set('categoryId', (string) $this->material->id)
            ->set('description', 'Fatura F-77')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-11-13', FinanceObligation::firstOrFail()->due_on->toDateString());
    }

    public function test_partial_and_full_payment_from_the_screen(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->assertSet('payAmount', '15000,00')
            ->set('payAccountId', (string) $this->bank->id)->set('payAmount', '5.000')
            ->call('confirmPay')
            ->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->assertSet('payAmount', '10000,00')
            ->set('payAccountId', (string) $this->bank->id)
            ->call('confirmPay')
            ->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(3_500_000, app(FinanceBalanceService::class)->balance($this->bank));
    }

    public function test_overpayment_shows_error(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')
            ->call('startPay', $invoice->id)
            ->set('payAccountId', (string) $this->bank->id)->set('payAmount', '20.000')
            ->call('confirmPay')
            ->assertHasErrors('payAmount');
    }

    public function test_tabs_filter_by_due_date_and_summary_shows_totals(): void
    {
        $this->invoice('2026-10-10', ['description' => 'Geciken fatura']);
        $this->invoice('2026-10-18', ['description' => 'Bu hafta fatura']);
        $this->invoice('2026-11-10', ['description' => 'Gelecek ay fatura']);

        Livewire::test('pages::finance.obligations')
            ->set('tab', 'overdue')->assertSee('Geciken fatura')->assertDontSee('Bu hafta fatura')
            ->set('tab', 'week')->assertSee('Bu hafta fatura')->assertSee('Geciken fatura')->assertDontSee('Gelecek ay fatura')
            ->set('tab', 'all')->assertSee('Gelecek ay fatura')
            ->assertSee('30.000,00 ₺');
    }

    public function test_cancel_requires_reason_and_is_blocked_after_payment(): void
    {
        $invoice = $this->invoice();

        Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id)->call('confirmCancel')->assertHasErrors('cancelReason');
        Livewire::test('pages::finance.obligations')->call('startCancel', $invoice->id)->set('cancelReason', 'Mükerrer')->call('confirmCancel')->assertHasNoErrors();

        $this->assertSame(ObligationStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_staff_without_finance_is_forbidden(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/finans/odemeler')->assertForbidden();
    }
}
```

Steps: kırmızı → rota (`Route::livewire('/finans/odemeler', 'pages::finance.obligations')->middleware(['auth', 'can:finance.viewAny'])->name('finance.obligations');`) + sekme → sayfa → yeşil → `npm run build` + görsel (özet kartları, sekmeler, liste, üç modal; 1280/500) → commit `feat: Finans Ödemeler ve Tahsilatlar ekranı — kısmi ödeme, vade sekmeleri, iptal — Aşama 35 (8/10)`.

---

### Task 9: Tekrarlayanlar ekranı ve Hareketler'e cari

**Önce:** `frontend-design`.

**Files:**
- Modify: `routes/web.php`, `finance-tabs.blade.php` (Cariler'den sonra `'finance.recurrings' => 'Tekrarlayanlar'`), `resources/views/pages/finance/⚡entries.blade.php`
- Create: `resources/views/pages/finance/⚡recurrings.blade.php`
- Test: `tests/Feature/Finance/FinanceRecurringsScreenTest.php`, `tests/Feature/Finance/FinanceEntriesScreenTest.php` (ekleme)

**Interfaces:**
- Produces: rota `finance.recurrings` (`/finans/tekrarlayanlar`, `pages::finance.recurrings`). Bileşen: `$showForm`, `$editingId`, `$formDirection`, `$name`, `$amount`, `$categoryId`, `$partyId`, `$frequency ('monthly')`, `$startsOn`, `$endsOn`; metodlar `openForm(?int $id = null)`, `closeForm()`, `save()`, `deactivate(int)`, `activate(int)`. Hareketler bileşenine `$partyId` (gelir/gider formunda) ve liste/filtrede cari.

Tekrarlayanlar PHP bölümü:

```php
<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Services\RecurringService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formDirection = 'payable';

    public string $name = '';

    public string $amount = '';

    public string $categoryId = '';

    public string $partyId = '';

    public string $frequency = 'monthly';

    public string $startsOn = '';

    public string $endsOn = '';

    public function openForm(?int $id = null): void
    {
        Gate::authorize($id === null ? 'finance.create' : 'finance.update');

        $this->closeForm();
        $this->startsOn = FinanceCalendar::today()->toDateString();

        if ($id !== null) {
            $recurring = FinanceRecurring::findOrFail($id);
            $this->editingId = $recurring->id;
            $this->formDirection = $recurring->direction->value;
            $this->name = $recurring->name;
            $this->amount = Money::input($recurring->amount);
            $this->categoryId = (string) $recurring->category_id;
            $this->partyId = (string) $recurring->party_id;
            $this->frequency = $recurring->frequency->value;
            $this->startsOn = $recurring->starts_on->toDateString();
            $this->endsOn = (string) $recurring->ends_on?->toDateString();
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'formDirection', 'name', 'amount', 'categoryId', 'partyId', 'frequency', 'startsOn', 'endsOn');
        $this->resetValidation();
    }

    public function save(RecurringService $recurrings): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $this->validate([
            'formDirection' => ['required', Rule::enum(ObligationDirection::class)],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'categoryId' => ['required', 'integer'],
            'partyId' => ['nullable', 'integer'],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['name' => 'ad', 'amount' => 'tutar', 'categoryId' => 'kategori', 'startsOn' => 'ilk vade', 'endsOn' => 'bitiş']);

        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $common = [
            'name' => $this->name,
            'amount' => $amount,
            'category' => FinanceCategory::findOrFail($this->categoryId),
            'party' => $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null,
            'ends_on' => $this->endsOn !== '' ? CarbonImmutable::parse($this->endsOn) : null,
        ];

        try {
            $this->editingId === null
                ? $recurrings->create([
                    ...$common,
                    'direction' => ObligationDirection::from($this->formDirection),
                    'frequency' => RecurringFrequency::from($this->frequency),
                    'starts_on' => CarbonImmutable::parse($this->startsOn),
                ])
                : $recurrings->update(FinanceRecurring::findOrFail($this->editingId), $common);
        } catch (FinanceRuleException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Tekrarlayan kalem eklendi; ödeme kayıtları her gün 35 gün ilerisi için üretilir.' : 'Tekrarlayan kalem güncellendi; değişiklik henüz üretilmemiş dönemlere uygulanır.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function deactivate(int $id, RecurringService $recurrings): void
    {
        Gate::authorize('finance.update');

        $recurrings->deactivate(FinanceRecurring::findOrFail($id));
        session()->flash('status', 'Durduruldu; üretilmiş kayıtlar yerinde kalır.');
    }

    public function activate(int $id, RecurringService $recurrings): void
    {
        Gate::authorize('finance.update');

        $recurrings->activate(FinanceRecurring::findOrFail($id));
        session()->flash('status', 'Yeniden başlatıldı; kaçırılan dönemler geriye dönük üretilmez.');
    }

    public function with(): array
    {
        return [
            'recurrings' => FinanceRecurring::with(['category', 'party'])->orderByRaw("status = 'active' desc")->orderBy('next_due_on')->get(),
            'categories' => FinanceCategory::where('status', 'active')->orderBy('name')->get(),
            'parties' => FinanceParty::where('status', 'active')->orderBy('name')->get(),
            'frequencies' => RecurringFrequency::cases(),
        ];
    }
};
?>
```

Tekrarlayanlar şablonu: başlık "Finans", açıklama "Kira, maaş, abonelik gibi düzenli kalemler. Sistem her gün önümüzdeki 35 günün ödeme kayıtlarını Ödemeler ve Tahsilatlar'a ekler.", "Yeni Tekrarlayan Kalem" düğmesi. Tablo: Ad (+ cari, kategori rozeti), Sıklık (`frequency->label()` + "her ayın 5'i" / "her Çarşamba" / "her yıl 5 Mart" açıklaması — haftalık için gün adları dizisi `['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar']`), Tutar (yön rengi), Sonraki vade (pasifse "Durduruldu"/bitiş geçtiyse "Bitti"), işlemler (Düzenle, Durdur `wire:confirm`, Yeniden başlat). Modal: Yön (yeni kayıtta), Ad, Tutar, Kategori (forma uygun yönde), Cari, Sıklık ve İlk vade (yeni kayıtta; düzenlemede salt gösterilir), Bitiş (isteğe bağlı). Boş durum "Henüz tekrarlayan kalem yok. Kira ve maaş gibi her ay ödediklerini ekle; unutma riski kalmasın."

Hareketler (`⚡entries.blade.php`) değişiklikleri:
- `public string $partyId = '';` ekle; `resetForm()` reset listesine `'partyId'`; `save()` doğrulamasına `'partyId' => ['nullable', 'integer']`; gelir/gider dalında `$party = $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null;` ve `income`/`expense` çağrılarına son argüman `$party`; `use App\Domain\Finance\Models\FinanceParty;`.
- Gelir/gider formunda Şube alanının yanına "Cari (isteğe bağlı)" select'i (aktif cariler).
- `with()`'e `'parties' => FinanceParty::where('status','active')->orderBy('name')->get()` ve sorgunun `with([...])` listesine `'party'`.
- Liste satırında tür etiketi `$entry->type->label()` yerine `$entry->label()`; cari varsa açıklama satırında `· {cari adı}` bağlantısı (`finance.parties.show`).
- `filterType` seçeneklerinde `Payment` artık enum'da olduğu için kendiliğinden görünür; değişiklik gerekmez.

**Tests:**

`tests/Feature/Finance/FinanceRecurringsScreenTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceRecurringsScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $this->rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_creates_a_monthly_rent_and_sees_it(): void
    {
        Livewire::test('pages::finance.recurrings')
            ->call('openForm')
            ->set('name', 'Kira')->set('amount', '20.000')->set('categoryId', (string) $this->rent->id)
            ->set('frequency', 'monthly')->set('startsOn', '2026-11-05')
            ->call('save')
            ->assertHasNoErrors();

        $recurring = FinanceRecurring::firstOrFail();
        $this->assertSame(RecurringFrequency::Monthly, $recurring->frequency);
        $this->assertSame(5, $recurring->day);
        $this->get('/finans/tekrarlayanlar')->assertOk()->assertSee('Kira')->assertSee('20.000,00 ₺');
    }

    public function test_edit_keeps_schedule_and_stop_restart_work(): void
    {
        Livewire::test('pages::finance.recurrings')
            ->call('openForm')->set('name', 'Kira')->set('amount', '20.000')->set('categoryId', (string) $this->rent->id)->set('startsOn', '2026-11-05')
            ->call('save');
        $recurring = FinanceRecurring::firstOrFail();

        Livewire::test('pages::finance.recurrings')->call('openForm', $recurring->id)->set('amount', '22.000')->call('save')->assertHasNoErrors();
        $this->assertSame(2_200_000, $recurring->fresh()->amount);
        $this->assertSame('2026-11-05', $recurring->fresh()->next_due_on->toDateString());

        Livewire::test('pages::finance.recurrings')->call('deactivate', $recurring->id);
        $this->assertSame('passive', $recurring->fresh()->status);
        Livewire::test('pages::finance.recurrings')->call('activate', $recurring->id);
        $this->assertSame('active', $recurring->fresh()->status);
    }

    public function test_income_category_is_rejected_for_payable(): void
    {
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);

        Livewire::test('pages::finance.recurrings')
            ->call('openForm')->set('name', 'Kira')->set('amount', '1')->set('categoryId', (string) $revenue->id)->set('startsOn', '2026-11-05')
            ->call('save')
            ->assertHasErrors('name');
    }
}
```

`FinanceEntriesScreenTest` sonuna:

```php
    public function test_expense_can_be_linked_to_a_party_and_list_shows_it(): void
    {
        $party = \App\Domain\Finance\Models\FinanceParty::create(['name' => 'Depo Ltd.', 'kind' => \App\Domain\Finance\Support\PartyKind::Supplier]);

        Livewire::test('pages::finance.entries')
            ->call('openForm', 'expense')
            ->set('accountId', $this->cash->id)->set('amount', '100')->set('categoryId', $this->rent->id)->set('partyId', (string) $party->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($party->id, \App\Domain\Finance\Models\FinanceEntry::firstOrFail()->party_id);
        $this->get('/finans/hareketler')->assertSee('Depo Ltd.');
    }
```

(Dosyanın başına `use` satırları ekleyip tam adları kısaltabilirsin.)

Steps: kırmızı → rota + sekme → sayfa + Hareketler değişikliği → yeşil (`php artisan test --filter='FinanceRecurringsScreenTest|FinanceEntriesScreenTest'`) → `npm run build` + görsel (Tekrarlayanlar liste/boş/modal, Hareketler gelir formu cari alanıyla; 1280/500) → commit `feat: Finans Tekrarlayanlar ekranı ve harekete cari bağlama — Aşama 35 (9/10)`.

---

### Task 10: Aşama 35 kabul testi ve belge

**Files:**
- Test: `tests/Feature/Acceptance/Stage35FinanceObligationsAcceptanceTest.php`
- Modify: `README.md` (Finans satırını genişlet)

- [ ] **Step 1: Kabul testi** (spec Doğrulama maddesi birebir)

```php
<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Notifications\FinanceDueNotification;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Services\RecurringService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Aşama 35 Doğrulama: 15.000 TL borç 5.000 + 10.000 ödenir → partial → paid;
 * ödeme iptali → partial; aylık kira tanımı dönemi bir kez üretir (iş iki kez
 * çalışsa da); vadesi geçen için bildirim düşer. Ek: izolasyon.
 */
class Stage35FinanceObligationsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_35_verification(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Istanbul'));
        $clinic = Organization::create(['name' => 'Gülüş Kliniği', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($admin);

        $ledger = app(LedgerService::class);
        $balances = app(FinanceBalanceService::class);
        $today = CarbonImmutable::parse('2026-10-14');

        $bank = FinanceAccount::create(['name' => 'Banka', 'type' => AccountType::Bank, 'opening_balance' => 5_000_000, 'opening_date' => '2026-10-01']);
        $supplier = FinanceParty::create(['name' => 'Dental Depo', 'kind' => PartyKind::Supplier]);
        $material = FinanceCategory::create(['name' => 'Malzeme', 'direction' => CategoryDirection::Expense]);
        $rent = FinanceCategory::create(['name' => 'Kira', 'direction' => CategoryDirection::Expense]);

        // 1) 15.000 TL borç: 5.000 + 10.000 → partial → paid
        $invoice = app(ObligationService::class)->create(['direction' => ObligationDirection::Payable, 'amount' => 1_500_000, 'due_on' => CarbonImmutable::parse('2026-10-10'), 'party' => $supplier, 'category' => $material, 'description' => 'Fatura F-77']);
        $ledger->pay($invoice, $bank, 500_000, $today, $admin);
        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);
        $second = $ledger->pay($invoice->fresh(), $bank, 1_000_000, $today, $admin);
        $this->assertSame(ObligationStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(3_500_000, $balances->balance($bank));
        $this->assertSame(['receivable' => 0, 'payable' => 0, 'net' => 0], $balances->partyBalances([$supplier])[$supplier->id]);

        // 2) Ödeme iptali → partial, kalan 10.000, cari yine borçlu
        $ledger->reverse($second, 'Yanlış hesap', $admin);
        $this->assertSame(ObligationStatus::Partial, $invoice->fresh()->status);
        $this->assertSame(1_000_000, $balances->remaining($invoice->fresh()));
        $this->assertSame(-1_000_000, $balances->partyBalances([$supplier])[$supplier->id]['net']);

        // 3) Aylık kira dönemi bir kez üretilir (iş iki kez çalışsa da)
        app(RecurringService::class)->create(['direction' => ObligationDirection::Payable, 'name' => 'Kira', 'amount' => 2_000_000, 'category' => $rent, 'frequency' => RecurringFrequency::Monthly, 'starts_on' => CarbonImmutable::parse('2026-11-05')]);
        Artisan::call('finance:generate-recurring');
        Artisan::call('finance:generate-recurring');
        $this->assertSame(1, FinanceObligation::whereNotNull('recurring_id')->count());
        $this->assertSame('Kira — Kasım 2026', FinanceObligation::whereNotNull('recurring_id')->value('description'));

        // 4) Vadesi geçen için bildirim
        Artisan::call('finance:scan-due');
        $notice = $admin->notifications()->where('type', FinanceDueNotification::class)->get()->firstWhere('data.obligation_id', $invoice->id);
        $this->assertNotNull($notice);
        $this->assertSame('overdue', $notice->data['state']);

        // 5) İzolasyon
        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $this->assertSame(0, FinanceObligation::count());
        $this->assertSame(0, FinanceParty::count());
        $this->get('/finans/odemeler')->assertOk()->assertDontSee('Fatura F-77');
        $this->get('/finans/cariler/'.$supplier->id)->assertNotFound();

        CarbonImmutable::setTestNow();
    }
}
```

- [ ] **Step 2:** `php artisan test --filter=Stage35FinanceObligationsAcceptanceTest` PASS (kırmızıysa nedenini bul, doğrulamayı gevşetme).
- [ ] **Step 3:** `php artisan test`, `vendor/bin/pint --test`, `npm run build` — hepsi temiz.
- [ ] **Step 4:** README'deki Finans satırını şöyle güncelle (mevcut biçimle): "**Finans (Faz 5):** kasa/banka/POS hesapları, gelir-gider kategorileri, silinmez finans defteri (gelir, gider, transfer, gerekçeli iptal), cari hesaplar, vadeli ödeme/tahsilat (kısmi ödeme), tekrarlayan kalemler (günlük üretim) ve vade bildirimleri."
- [ ] **Step 5: Commit** — `feat: Finans cari ve vadeler kabul testi ve belge — Aşama 35 tamamlandı`
- [ ] **Step 6:** NotebookLM raporu — kontrolcü yazar (alt ajan değil).

---

## Self-Review Notları

- Spec Aşama 35 satırı: cariler ✓ (T2, T7), yükümlülükler ✓ (T3), `pay` ✓ (T4), tekrarlayan kalemler + günlük iş ✓ (T5), vade bildirimi ✓ (T6), Cari ve Ödemeler ekranları ✓ (T7, T8), doğrulama ✓ (T10). Spec bölüm 2'deki "aynı yükümlülük için günde bir kez" yerine stok uyarılarının "okunmamış aynı durum varsa tekrar gönderme, durum değişince yeniden gönder" deseni seçildi (proje içi tutarlılık; okunmuş bildirim bir sonraki taramada tekrar gelebilir — günlük tarama olduğu için en fazla günde bir). Rapora yazılacak.
- Spec'ten bilinçli ekler: cari düzenleme ve aktifleştirme (Aşama 34 son incelemesindeki "geri açılamama" dersi), yükümlülük `cancel_reason` kolonu, tekrarlayan kalemi yeniden başlatırken kaçırılan dönemleri üretmeme, aynı organizasyon savunması (Aşama 34 son incelemesi önerisi), kapanmış organizasyonlarda üretim/bildirim yapılmaması.
- Tür tutarlılığı: `ObligationDirection::entryDirection/categoryDirection/paymentLabel`, `FinanceBalanceService::paid/remaining/paidAmounts/partyBalances`, `LedgerService::pay`, `RecurringService::generateDue/HORIZON_DAYS`, `FinanceDueAlertService::scan/NOTICE_DAYS`, rota adları `finance.parties`, `finance.parties.show`, `finance.obligations`, `finance.recurrings` görevler arasında aynı.
- Bilinen sınırlar: cari adı büyük/küçük harf eşleşmesi Türkçe İ/ı'da kusursuz değil; Finans Özeti, raporlar ve mutabakat Aşama 36'da.
