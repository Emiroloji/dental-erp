# Finans Aşama 36 — Mutabakat, Finans Özeti ve Raporlar Uygulama Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hesap ekstresi ve banka mutabakatı, Finans Özeti ekranı (toplam nakit, bu ay, bu hafta, 90 günlük nakit projeksiyonu grafiği), dört finans raporu (gelir-gider, nakit akışı, cari yaşlandırma, hesap ekstresi) ve Excel/PDF dışa aktarımı; ayrıca Aşama 35'ten ertelenen maddeler.

**Architecture:** Raporlar `app/Domain/Finance/Reports` altında defterden türetilir; hiçbir rakam saklanmaz. Mutabakat farkı `LedgerService::adjust` ile `adjustment` türünde bir defter hareketine dönüşür. Grafikler sunucuda üretilen satır içi SVG'dir (yeni JS bağımlılığı yok). Dışa aktarım mevcut kuyruk altyapısını (`ReportExportService`, `GenerateReportExportJob`, "Dışa Aktarımlar" listesi) kullanır; yetki rapor türünün modülüne göre seçilir.

**Tech Stack:** Laravel 12, Livewire 4 tek dosyalı sayfalar, Tailwind v4 token'ları, Maatwebsite Excel, DomPDF, PHPUnit (SQLite), Pint. Canlı PostgreSQL 16.

**Spec:** `docs/superpowers/specs/2026-10-14-finans-modulu-design.md` (bölüm 2 ReconciliationService, 3 Finans Özeti ve Hareketler süzgeçleri, 4 raporlar, 5 Aşama 36 satırı). Önceki planlar: `2026-10-14-finans-asama-34-...md`, `2026-10-15-finans-asama-35-...md`.

## Global Constraints

- Türkçe ekran/yorum/commit; commit `feat: ... — Aşama 36 (n/11)`, son `— Aşama 36 tamamlandı`; sonunda `Co-Authored-By: <commit'i yazan model> <noreply@anthropic.com>`.
- Tutarlar kuruş `int`; servisler ve rapor satırları kuruş taşır. Yalnızca dışa aktarım katmanı gösterim için çevirir: PDF'te `Money::format`, Excel'de lira cinsinden sayı (`round($kurus / 100, 2)`) — bu bir gösterim dönüşümüdür, hesap değildir.
- `finance_entries` güncellenmez/silinmez; yalnızca `LedgerService` yazar (`adjust` dahil).
- Tarih karşılaştırmaları `Y-m-d` metinle veya `whereDate`; "bugün" `FinanceCalendar::today()` (Europe/Istanbul). Carbon anlık karşılaştırması yapılmaz (tarih cast'leri UTC gece yarısı).
- Tüm finans modelleri `BelongsToOrganization`; sorgular kapsamlı modellerden. Kuyruktaki dışa aktarım isteyen kullanıcı adına çalışır (mevcut desen).
- Yetki: finans ekranları `finance.viewAny`; mutabakat `finance.create`; finans dışa aktarımı finans okuma yetkisi.
- Livewire sayfalarında `use InvalidArgumentException;` yazılmaz; `catch (\InvalidArgumentException $e)`.
- Yeni JS bağımlılığı yok; renkler `app.css` token'ları; tutar renkleri `finance-in`/`finance-out`; `status-*` tutar için kullanılmaz. Tek dosyalı sayfa ~1000 satırı geçmez.
- Arayüz görevlerinde `frontend-design` skill'i, `npm run build`, 1280 ve 500 piksel görsel kontrol (`.superpowers/tools/finance-screenshot.php`).
- Test: `php artisan test --filter=<Ad>`; tümü `php artisan test`; biçim `vendor/bin/pint --dirty`, son kontrol `vendor/bin/pint --test`.

---

### Task 1: Hata alanı ipucu ve Hareketler'de cari süzgeci (Aşama 35 ertelenen)

Neden: Üç ekran hata mesajının **metnine** bakarak hangi alanın altında gösterileceğini seçiyor; mesajın içinde kullanıcı adı (ör. "Kategori Ltd") geçince hata yanlış alana düşüyor. Ayrıca spec bölüm 3 Hareketler süzgeçlerinde "cari" istiyor.

**Files:**
- Modify: `app/Domain/Finance/Exceptions/FinanceRuleException.php`
- Modify: finans servislerindeki tüm `new FinanceRuleException(...)` çağrıları (38 yer: `LedgerService`, `ObligationService`, `RecurringService`, `FinancePartyService`, `FinanceCategoryService`, `FinanceAccountService`)
- Modify: `resources/views/pages/finance/⚡entries.blade.php`, `⚡obligations.blade.php`, `⚡recurrings.blade.php`, `⚡parties.blade.php`, `⚡categories.blade.php`, `⚡accounts.blade.php` (hata yönlendirme), `⚡entries.blade.php` (cari süzgeci)
- Test: `tests/Unit/Finance/FinanceRuleExceptionTest.php`, mevcut ekran testlerine eklemeler

**Interfaces:**
- Produces: `FinanceRuleException::__construct(string $message, ?string $field = null)`, `public readonly ?string $field`. Alan adları (alan bilgisi alana göre, metne göre değil): `'amount'`, `'date'`, `'account'`, `'to_account'`, `'category'`, `'party'`, `'due_on'`, `'ends_on'`, `'name'`, `'reason'`, `'commission'`, `'opening_date'`, `'description'`, `'statement_balance'`. Alan verilmeyen mesajlar genel hatadır.

`FinanceRuleException.php`:

```php
<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Finans iş kuralı ihlali; mesajı kullanıcıya olduğu gibi gösterilir. field,
 * hatanın formda hangi alanın altında gösterileceğini söyler (ekranlar mesaj
 * metnine bakarak tahmin etmesin — mesaj kullanıcı adları içerebilir).
 */
class FinanceRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
```

Alan eşlemesi (her `throw` satırına ikinci argüman olarak eklenir):

| Mesaj (özet) | field |
|---|---|
| Tutar sıfırdan büyük olmalıdır / Kalan tutardan ... fazla ödeme | `amount` |
| Komisyon eksi olamaz / Komisyon için bir gider kategorisi seçin | `commission` |
| Kaynak ve hedef hesap aynı olamaz | `to_account` |
| ... pasif bir hesap / ... açılış tarihinden önce hareket | `account` |
| İleri tarihli hareket yazılamaz | `date` |
| ... pasif bir kategori / Bu hareket/kayıt için bir ... kategorisi seçin / Aktif bir ... kategorisi seçin / "..." adında (pasif) bir ... kategorisi | `category` (kategori adı formunda: `name`) |
| ... pasif bir cari | `party` |
| Cari adı boş olamaz / "..." adında (pasif) bir cari | `name` |
| Ad yazın (örn. Kira, Maaş) | `name` |
| Bitiş tarihi başlangıçtan önce olamaz | `ends_on` |
| Vade tarihini girin | `due_on` |
| Açıklama yazın | `description` |
| İptal için bir gerekçe yazın / Önce bu kayda bağlı ödemeleri iptal edin / Bu kayıt zaten iptal edilmiş / Bu hareket zaten iptal edilmiş / İptal kaydı iptal edilemez | `reason` |
| Açılış tarihi bugünden sonra olamaz | `opening_date` |
| Bu kayıt kapanmış veya iptal edilmiş; ödeme yapılamaz | (yok) |
| Kayıtlar aynı organizasyona ait değil / Ödemesi olan veya kapanmış kayıt düzenlenemez | (yok) |

Her sayfa, domain alanını kendi özellik adına çeviren tek bir sabit kullanır ve alan yoksa sayfanın "genel" alanına düşer. Örnek (`⚡obligations.blade.php` için; diğer sayfalar kendi özellik adlarıyla aynı kalıbı kullanır):

```php
    /** Domain hata alanı → bu formdaki özellik. */
    private const FORM_FIELDS = ['amount' => 'amount', 'date' => 'dueOn', 'due_on' => 'dueOn', 'party' => 'partyId', 'category' => 'categoryId', 'description' => 'description', 'reason' => 'cancelReason'];

    private const PAY_FIELDS = ['amount' => 'payAmount', 'date' => 'payDate', 'account' => 'payAccountId'];
```

ve `catch (FinanceRuleException $e) { $this->addError(self::FORM_FIELDS[$e->field] ?? 'amount', $e->getMessage()); return; }`. `str_contains` ile alan seçen bütün `match (true)` blokları kaldırılır. Hareketler sayfası için `['amount' => 'amount', 'date' => 'date', 'account' => 'accountId', 'to_account' => 'toAccountId', 'category' => 'categoryId', 'party' => 'partyId', 'commission' => 'commission']`, iptal formu için `reason` → `reverseReason`.

Hareketler cari süzgeci: `public string $filterParty = '';`, süzgeç satırına "Tüm cariler" seçimi (yalnızca organizasyonun carileri, pasifler "(pasif)" ekiyle), `with()` sorgusuna `->when((int) $this->filterParty > 0, fn ($q) => $q->where('party_id', (int) $this->filterParty))`. Süzgeç ızgarası 6 öğeye çıkınca dar ekranda 2 sütun, geniş ekranda 3 sütun olacak biçimde düzenlenir.

**Tests:**
- `tests/Unit/Finance/FinanceRuleExceptionTest.php`: `new FinanceRuleException('x', 'party')` → `field === 'party'`; tek argümanla `field === null`.
- Servis testleri: mevcut `expectExceptionMessage` testlerine dokunma; her servis için en az bir yeni test alanı doğrular (ör. `ObligationServiceTest`: yön uyuşmayan kategori → `field === 'category'`; `LedgerServiceTest`: ileri tarih → `'date'`; `RecurringServiceTest`: bitiş < başlangıç → `'ends_on'`; `FinancePartyServiceTest`: çift ad → `'name'`).
- Ekran: adında "Kategori" geçen pasif bir cariyle gelir girişi → hata `partyId` alanında (`assertHasErrors('partyId')`, `assertHasNoErrors('categoryId')`); Hareketler'de `filterParty` yalnızca o carinin hareketlerini gösterir.

Steps: kırmızı → istisna sınıfı → servislerdeki çağrılara alan ekle → sayfaları sabit eşlemeye çevir → yeşil (`php artisan test --filter=Finance`) → commit `feat: Finans hata alanı ipucu ve Hareketler'de cari süzgeci — Aşama 36 (1/11)`.

---

### Task 2: Tekrarlayan geri doldurma tabanı, pasif kayıt hizası, cari adında tekil indeks (Aşama 35 ertelenen)

**Files:**
- Modify: `app/Domain/Finance/Services/RecurringService.php`, `app/Domain/Finance/Services/ObligationService.php`, `app/Domain/Finance/Services/FinancePartyService.php`
- Create: `database/migrations/2026_10_16_090000_add_unique_lower_name_to_finance_parties.php`
- Test: `RecurringServiceTest`, `ObligationServiceTest`, `FinancePartyServiceTest` eklemeleri

Kararlar (rapora yazılır):
1. **Yeniden açılan klinik:** Organizasyon uzun süre pasif/salt-okunur kalıp yeniden açılırsa tekrarlayan kalemler, `create()`'teki gibi en fazla `HORIZON_DAYS` (35) gün geriye üretir; daha eski dönemler atlanır. Gerekçe: aylarca birikmiş "geciken kira" kayıtlarının bir sabah toplu düşmesi ve bildirim yağmuru, kliniğin gerçek durumunu yansıtmaz; gerçekten borç varsa kullanıcı tek kayıt olarak girer.
2. **Pasif cari/kategori hizası:** Yükümlülük düzenlemesinde değişmeyen pasif cari/kategori engel olmaz (tekrarlayanlarla aynı kural); başka bir pasif kayda geçiş reddedilir. Ödeme pasif carinin kaydına yapılabilir (pasife alınmış tedarikçinin faturası da ödenir). Tekrarlayan üretim pasif kategori/cariyle de kayıt üretir (tanım durdurulmadıkça ödeme yükümlülüğü sürer).
3. **Cari adı tekilliği veritabanında:** `(organization_id, LOWER(name))` tekil indeksi; eşzamanlı aynı adlı iki cari açılamaz.

`RecurringService::generateFor` içinde döngüden önce:

```php
            // Uzun süre kapalı kalan klinik yeniden açılınca geçmiş dönemler toplu
            // üretilmez: create() ile aynı taban (bugün − HORIZON_DAYS).
            $floor = FinanceCalendar::today()->subDays(self::HORIZON_DAYS)->toDateString();

            while ($next->toDateString() < $floor) {
                $next = $recurring->frequency->next($next, $recurring->day);
            }
```

`ObligationService::attributes()` — `update` için mevcut yükümlülüğü bilen bir `?FinanceObligation $current` parametresi alır; `$party->id === $current?->party_id` veya `$category->id === $current?->category_id` ise "pasif" kontrolü atlanır, yön kontrolü her zaman uygulanır (RecurringService'teki desenle aynı).

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cari adı organizasyon içinde büyük/küçük harften bağımsız tekildir (Aşama 35
 * son incelemesi: uygulama kontrolü eşzamanlı iki kaydı engelleyemiyordu).
 * İfade indeksi PostgreSQL ve SQLite'ta aynı sözdizimiyle çalışır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX finance_parties_org_lower_name_unique ON finance_parties (organization_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX finance_parties_org_lower_name_unique');
    }
};
```

`FinancePartyService::create/update`: yazmayı `try { ... } catch (UniqueConstraintViolationException) { throw new FinanceRuleException("\"{$name}\" adında bir cari zaten var.", 'name'); }` ile sar.

**Tests:**
- `RecurringServiceTest::test_reactivated_organization_does_not_backfill_beyond_horizon`: tanım 05.06.2026 aylık, organizasyon pasif (üretim yok), `next_due_on` 05.06'da kalır; bugün 14.10'da organizasyon aktif olunca `generateDue()` → en erken üretilen vade `2026-10-05` (taban 09.09'dan sonraki ilk dönem), toplam 2 kayıt (10-05, 11-05).
- `ObligationServiceTest`: carisi sonradan pasife alınmış açık kaydın tutarı güncellenebilir; başka bir pasif cariye geçiş `field === 'party'` ile reddedilir.
- `FinancePartyServiceTest::test_database_rejects_case_insensitive_duplicate_names`: `FinanceParty::create` ile doğrudan "ABC Sigorta" ve "abc sigorta" → `UniqueConstraintViolationException` (SQLite `LOWER` ASCII için çalışır).

Commit: `feat: Finans tekrarlayanlarda geri doldurma tabanı, pasif kayıt hizası ve cari adı tekil indeksi — Aşama 36 (2/11)`

---

### Task 3: Mutabakat çekirdeği — `adjustment` hareketi ve ReconciliationService

**Files:**
- Create: `database/migrations/2026_10_16_100000_create_finance_reconciliations_table.php`, `app/Domain/Finance/Models/FinanceReconciliation.php`, `app/Domain/Finance/Services/ReconciliationService.php`
- Modify: `FinanceEntryType.php` (`Adjustment`), `LedgerService.php` (`adjust`)
- Test: `tests/Feature/Finance/ReconciliationServiceTest.php`

**Interfaces:**
- `FinanceEntryType::Adjustment` (`'adjustment'`, etiket `'Mutabakat düzeltmesi'`, `direction()` null — yönü farkın işaretidir).
- `LedgerService::adjust(FinanceAccount $account, int $signedAmount, CarbonInterface $date, ?User $actor, string $description): FinanceEntry` — `$signedAmount !== 0`; `ensureWritable`; `direction = $signedAmount <=> 0`, `amount = abs($signedAmount)`, kategori yok. Yalnızca `ReconciliationService` çağırır (docblock'ta yaz).
- `ReconciliationService::preview(FinanceAccount $account, CarbonInterface $statementDate, int $statementBalance): array{system_balance: int, difference: int}` — `difference = statementBalance − systemBalance(asOf statementDate)`.
- `ReconciliationService::reconcile(FinanceAccount $account, CarbonInterface $statementDate, int $statementBalance, User $actor, bool $adjust, ?string $note = null): FinanceReconciliation` — tarih bugünden sonra veya hesabın açılışından önce olamaz (`FinanceRuleException`, field `date`); fark 0 değilse ve `$adjust` true ise aynı işlem içinde `adjust($account, $difference, $statementDate, $actor, "Mutabakat: ekstre {tarih} — {not}")` çağrılır ve kayıt `adjustment_entry_id` taşır; `$adjust` false ise fark yalnızca kayda geçer (kullanıcı farkı sonra araştırır).
- `FinanceReconciliation` (BelongsToOrganization + Auditable): `account_id, statement_date (date), statement_balance, system_balance, difference (bigint), adjustment_entry_id, actor_id, note, created_at`; ilişkiler `account`, `adjustment`, `actor`.

Migration:

```php
        Schema::create('finance_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->date('statement_date');
            $table->bigInteger('statement_balance');
            $table->bigInteger('system_balance');
            $table->bigInteger('difference');
            $table->foreignId('adjustment_entry_id')->nullable()->constrained('finance_entries');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'account_id', 'statement_date']);
        });
```

(`FinanceReconciliation::UPDATED_AT = null`.)

Test (`ReconciliationServiceTest`, bugün 14.10.2026 İstanbul; kasa açılışı 10.000 TL 01.10):

```php
    public function test_matching_statement_records_zero_difference_without_entry(): void
    // 2.500 gelir (10.10) → ekstre 10.10 tarihli 12.500 → difference 0, adjustment_entry_id null, FinanceEntry sayısı 1

    public function test_difference_is_closed_with_an_adjustment_entry_dated_at_the_statement(): void
    // ekstre 12.10 tarihli 12.450 (sistem 12.500) → difference −5.000 kuruş; adjust=true → Adjustment hareketi direction −1, amount 5.000, occurred_on 2026-10-12;
    // balance(asOf 12.10) === 1_245_000; kayıt adjustment_entry_id dolu; açıklama "Mutabakat" içerir

    public function test_difference_can_be_recorded_without_adjusting(): void
    // adjust=false → kayıt difference −5.000, adjustment_entry_id null, bakiye değişmez

    public function test_future_or_pre_opening_statement_date_is_rejected(): void
    // 15.10 → FinanceRuleException field 'date'; 30.09 → field 'date'

    public function test_adjustment_can_be_reversed_like_any_entry(): void
    // reverse(adjustment) → bakiye eski hâline döner

    public function test_reconciliations_are_isolated_per_organization(): void
```

(Testlerin tam gövdesini uygulayıcı yazar; her yorumdaki sayılar birebir doğrulanır.)

Commit: `feat: Finans mutabakat — ekstre bakiyesi, fark ve düzeltme hareketi — Aşama 36 (3/11)`

---

### Task 4: Hesap ekstresi servisi ve hesap detay ekranı

**Files:**
- Create: `app/Domain/Finance/Reports/AccountStatementService.php`, `resources/views/pages/finance/⚡account.blade.php`
- Modify: `routes/web.php` (`finance.accounts.show` → `/finans/hesaplar/{account}`), `⚡accounts.blade.php` (hesap adı detaya bağlantı)
- Test: `tests/Feature/Finance/AccountStatementTest.php`, `tests/Feature/Finance/FinanceAccountScreenTest.php`

**Interfaces:**
- `AccountStatementService::statement(FinanceAccount $account, CarbonInterface $from, CarbonInterface $to): array{opening: int, rows: list<array{entry: FinanceEntry, balance: int}>, closing: int, in: int, out: int}` — `opening` = `FinanceBalanceService::balance($account, $from->subDay())` (açılış tarihinden önceyse açılış bakiyesi); satırlar `occurred_on` + `id` sırasıyla, yürüyen bakiye; `in` = pozitif hareketler toplamı, `out` = negatiflerin mutlak toplamı; `closing = opening + in − out` ve `FinanceBalanceService::balance($account, $to)` ile eşit olmalı (testte doğrula).
- `AccountStatementService::table(FinanceAccount, from, to): array{headings, rows, totals}` — dışa aktarım için (Task 9 kullanır): başlıklar `['Tarih', 'Tür', 'Açıklama', 'Cari', 'Giriş', 'Çıkış', 'Bakiye']`, tutarlar kuruş.
- Hesap detay ekranı (`pages::finance.account`, rota model bağlaması `FinanceAccount`, başka organizasyon → 404): üstte hesap adı, türü, IBAN, güncel bakiye; dönem seçimi (varsayılan bu ayın başı – bugün; `#[Url]` `from`, `to`, `Y-m-d` biçimi doğrulanır, geçersizse varsayılan); ekstre tablosu (Tarih, Tür `label()`, Açıklama + cari, Giriş, Çıkış, Bakiye; iptal edilmiş satır üstü çizili); altta dönem başı/sonu ve toplamlar. **Mutabakat** bölümü (`@can('finance.create')`): "Banka ekstresindeki bakiye" formu (tarih, tutar) → "Karşılaştır" (`preview`) → fark 0 ise "Kayıt et", değilse fark gösterilir ve iki düğme: "Farkı düzeltme hareketiyle kapat" (`adjust=true`) / "Yalnızca kaydet" (`adjust=false`); not alanı. **Mutabakat geçmişi** tablosu (tarih, ekstre bakiyesi, sistem, fark, düzeltme yapıldı mı, kim). Dışa aktarım düğmeleri (Excel/PDF) Task 9'da eklenir.

**Tests:**
- `AccountStatementTest`: açılış 10.000 (01.10); 05.10 +2.500 gelir; 08.10 −1.000 gider; 09.10 3.000 transfer çıkışı; 11.10 gider iptali (+1.000, iptal günü — `LedgerService::reverse` iptali "bugün" tarihler, bu yüzden iptalden önce test saatini `2026-10-11` İstanbul'a al, sonra `2026-10-14`'e geri dön; Carbon ve CarbonImmutable birlikte). Dönem 06.10–10.10 → opening 1_250_000; satırlar −100_000 (bakiye 1_150_000), −300_000 (850_000); closing 850_000 = `balance(asOf 10.10)`; in 0, out 400_000. Dönem 01.10–14.10 → opening 1_000_000 (açılıştan önceki gün) ve closing = güncel bakiye.
- `FinanceAccountScreenTest`: sayfa ekstre satırlarını gösterir; başka organizasyonun hesabı 404; salt-okuma personel mutabakat formunu görmez ve `reconcile` çağrısı 403; fark −50,00 ₺ gösterilir ve "Farkı düzeltme hareketiyle kapat" sonrası bakiye ekstreye eşit olur.

Görsel kontrol + commit: `feat: Finans hesap ekstresi ve mutabakat ekranı — Aşama 36 (4/11)`

---

### Task 5: Rapor süzgeçleri ve Gelir-Gider raporu

**Files:**
- Create: `app/Domain/Finance/Reports/FinanceReportFilters.php`, `app/Domain/Finance/Reports/IncomeExpenseReportService.php`
- Test: `tests/Feature/Finance/IncomeExpenseReportTest.php`

**Interfaces:**
- `FinanceReportFilters` (readonly): `from: CarbonImmutable`, `to: CarbonImmutable`, `branchId: ?int`, `accountId: ?int`, `direction: ?ObligationDirection`, `days: int` (30|60|90); `static fromArray(array $input, CarbonImmutable $defaultFrom, CarbonImmutable $defaultTo): self` — geçersiz/eksik değerlerde varsayılan; `days` yalnızca 30/60/90 (varsayılan 90); `toArray(): array` (kuyruğa parametre olarak yazılır); `describe(): string` ("Tarih: 01.10.2026 – 31.10.2026 · Şube: Merkez · Hesap: ...").
- `IncomeExpenseReportService::report(FinanceReportFilters): array{income: list<array{category: string, total: int}>, expense: list<array{category: string, total: int}>, income_total: int, expense_total: int, net: int, months: list<array{month: string, income: int, expense: int, net: int}>}`
  - Dahil: `income`, `expense`, `payment` türleri ve bunların iptalleri (`reversal` satırının iptal ettiği kaydın türü bu üçünden biriyse). Transfer ayakları ve iptalleri, `adjustment` ve iptali dahil **değil** (transfer içindeki komisyon gideri `expense` türünde olduğu için dahil).
  - Kova: satırın (iptalse iptal ettiği kaydın) yönü +1 → gelir, −1 → gider. Gelir toplamı = Σ(direction×amount) gelir kovasında; gider = Σ(−direction×amount) gider kovasında (iptaller kendiliğinden düşer).
  - Kategori adı: `category_id` boşsa "Kategorisiz". Şube süzgeci `branch_id`; hesap süzgeci `account_id`.
  - Aylar: dönemdeki her ay (boş aylar 0 ile), `month` biçimi "Ekim 2026" (`RecurringFrequency::Monthly->periodLabel`).
  - Uygulama: kapsamlı `FinanceEntry` sorgusu `leftJoin('finance_entries as original', 'original.id', '=', 'finance_entries.reverses_entry_id')`, `whereBetween` yerine `whereDate(finance_entries.occurred_on, >=/<=)`, `groupBy('finance_entries.occurred_on', 'finance_entries.category_id', bucket)` ile günlük/kategori toplamları SQL'de, ay ve kategori birleştirmesi PHP'de (veritabanına özgü tarih biçimlendirme fonksiyonu kullanılmaz). `bucket` = `COALESCE(original.direction, finance_entries.direction)`.
- `IncomeExpenseReportService::table(FinanceReportFilters): array{headings, rows, totals}` — başlıklar `['Tür', 'Kategori', 'Tutar']`, satırlar gelir kategorileri sonra gider kategorileri, totals `['', 'Net', net]`.

**Test** (`IncomeExpenseReportTest`; bugün 14.10.2026; kategoriler Hasılat (gelir), Kira, Malzeme, Banka/POS Komisyonu (gider); hesaplar kasa, banka; dönem 01.10–31.10):
- 02.10 gelir 5.000 Hasılat; 03.10 gider 2.000 Kira; 04.10 gider 1.000 Malzeme ve 06.10'da iptali; 05.10 transfer 3.000 kasa→banka komisyon 50 (Banka/POS Komisyonu); 07.10 Malzeme kategorili 4.000'lik borç yükümlülüğüne 1.500 ödeme; 08.10 kategorisiz alacak yükümlülüğüne 700 tahsilat; 09.10 mutabakat düzeltmesi −20 (kasa).
- Beklenen: income = [Hasılat 500_000, Kategorisiz 70_000], income_total 570_000; expense = [Kira 200_000, Malzeme 150_000, Banka/POS Komisyonu 5_000] (Malzeme: 100_000 − 100_000 iptal + 150_000 ödeme), expense_total 355_000; net 215_000; months: [Ekim 2026 → 570_000 / 355_000 / 215_000]. Transfer ve düzeltme hiçbir kovada yok.
- Dönem 01.09–31.10 → months iki satır, Eylül 0/0/0.
- Şube süzgeci: 02.10 gelirine şube A verildiyse şube A süzgecinde yalnızca o satır.
- İzolasyon: ikinci organizasyonun hareketleri rapora girmez.

Commit: `feat: Finans gelir-gider raporu — kategori ve ay kırılımı — Aşama 36 (5/11)`

---

### Task 6: Nakit akışı — gerçekleşen ve projeksiyon

**Files:**
- Create: `app/Domain/Finance/Reports/CashFlowReportService.php`, `app/Domain/Finance/Reports/CashFlowProjectionService.php`
- Test: `tests/Feature/Finance/CashFlowReportTest.php`, `tests/Feature/Finance/CashFlowProjectionTest.php`

**Interfaces:**
- `CashFlowReportService::actual(FinanceReportFilters): list<array{month: string, in: int, out: int, net: int, closing: int}>` — dönemdeki her ay; `in`/`out` tüm defter hareketleri **hariç** transfer ayakları ve onların iptalleri (hesaplar arası para nakit toplamını değiştirmez; komisyon gideri dahil, mutabakat düzeltmesi dahil — gerçek nakit farkıdır); `closing` = ay sonu itibarıyla tüm hesapların (pasifler dahil — geçmiş nakit gerçekti) açılış bakiyesi (açılış tarihi ≤ ay sonu olanlar) + o tarihe kadarki tüm hareketler. `accountId` süzgeci verilirse yalnızca o hesap (bu durumda transferler o hesap için gerçek giriş/çıkıştır ve **dahil** edilir).
- `CashFlowReportService::table(FinanceReportFilters)` — başlıklar `['Ay', 'Giriş', 'Çıkış', 'Net', 'Dönem sonu nakit']`.
- `CashFlowProjectionService::project(int $days, ?CarbonImmutable $today = null): array{start: int, points: list<array{date: string, balance: int, in: int, out: int}>, first_negative: ?string, receivable: int, payable: int}` — `start` = `FinanceBalanceService::total()` (aktif hesaplar); açık/kısmi yükümlülüklerin kalanı vadeye göre güne eklenir; vadesi geçmiş olanlar bugüne yığılır; bugün dahil `days + 1` nokta, birikimli `balance`; `first_negative` bakiyenin ilk sıfırın altına düştüğü gün; `receivable`/`payable` dönem içindeki toplamlar.
- `CashFlowProjectionService::table(int $days)` — başlıklar `['Tarih', 'Tahsilat', 'Ödeme', 'Tahmini nakit']`, yalnız hareketli günler + son gün.

**Tests:**
- Gerçekleşen: Eylül 01'de açılmış kasa 1.000; Eylül'de gelir 500, gider 200, kasa→banka transfer 300 (banka Eylül'de açık, 0); Ekim'de gider 100 ve Eylül giderinin Ekim'de iptali (+200), düzeltme −10. Beklenen Eylül: in 50_000, out 20_000, net 30_000, closing 130_000 (iki hesap toplamı); Ekim: in 20_000, out 11_000 (100 gider + 10 düzeltme → 10_000 + 1_000), net 9_000, closing 139_000. `accountId = banka` süzgecinde Eylül in 30_000 (transfer girişi), closing 30_000.
- Projeksiyon (bugün 14.10, aktif nakit 10.000): 10.10 vadeli 2.000 borç (geciken) → bugüne; 16.10 vadeli 5.000 alacak, 1.000 tahsil edilmiş (kalan 4.000); 20.10 vadeli 15.000 borç; iptal edilmiş ve kapanmış kayıtlar yok sayılır; 30 gün. Beklenen: start 1_000_000; 14.10 balance 800_000; 16.10 1_200_000; 20.10 −300_000; first_negative `2026-10-20`; nokta sayısı 31; son nokta 13.11 balance −300_000; receivable 400_000, payable 1_700_000.
- Geçersiz `days` → 90'a düşer (FinanceReportFilters testi Task 5'te değilse burada).

Commit: `feat: Finans nakit akışı — gerçekleşen aylar ve vade bazlı projeksiyon — Aşama 36 (6/11)`

---

### Task 7: Cari yaşlandırma raporu

**Files:**
- Create: `app/Domain/Finance/Reports/AgingReportService.php`
- Test: `tests/Feature/Finance/AgingReportTest.php`

**Interfaces:**
- `AgingReportService::BUCKETS = ['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus']` ve `bucketLabel(string): string` ('Vadesi gelmemiş', '1–30 gün', '31–60 gün', '61–90 gün', '90+ gün').
- `AgingReportService::report(ObligationDirection $direction, ?CarbonImmutable $today = null): array{rows: list<array{party: string, party_id: ?int, buckets: array<string, int>, total: int}>, totals: array<string, int>, total: int}` — açık/kısmi yükümlülüklerin kalanı; gecikme günü = `today − due_on` (gün farkı `Y-m-d` tarihlerinden; ≤ 0 → not_due, 1–30, 31–60, 61–90, 90+); carisiz kayıtlar "Carisiz" satırında; satırlar toplam kalana göre azalan.
- `AgingReportService::table(ObligationDirection)` — başlıklar `['Cari', 'Vadesi gelmemiş', '1–30 gün', '31–60 gün', '61–90 gün', '90+ gün', 'Toplam']`.

**Test** (bugün 14.10.2026, alacak yönü): ABC Sigorta: vade 20.10 kalan 3.000 (not_due), vade 13.10 kalan 1.000 (1 gün → d0_30), vade 14.09 kalan 2.000 (30 gün → d0_30), vade 13.09 kalan 500 (31 gün → d31_60); XYZ Kurum: vade 15.07 kalan 4.000 (91 gün → d90_plus), aynı carinin kapanmış ve iptal edilmiş kayıtları sayılmaz; carisiz vade 01.10 kalan 700 (d0_30). Kısmi ödeme kalanı düşer: ABC'nin 20.10 vadeli kaydı 5.000 tutarlıdır ve 2.000'i tahsil edilmiştir (kalan 3.000). Borç yönündeki kayıtlar alacak raporuna girmez. Beklenen satır sırası: ABC (650_000), XYZ (400_000), Carisiz (70_000); totals d0_30 = 100_000+200_000+70_000 = 370_000; total 1_120_000. İzolasyon testi.

Commit: `feat: Finans cari yaşlandırma raporu — Aşama 36 (7/11)`

---

### Task 8: SVG grafik yardımcısı ve bileşenleri

**Files:**
- Create: `app/Domain/Finance/Support/FinanceChart.php`, `resources/views/components/finance/line-chart.blade.php`, `resources/views/components/finance/bar-chart.blade.php`
- Test: `tests/Unit/Finance/FinanceChartTest.php`

**Interfaces:**
- `FinanceChart::line(list<int> $values, int $width = 720, int $height = 200, int $padding = 8): array{path: string, area: string, zero_y: float, min: int, max: int, points: list<array{x: float, y: float}>}` — y ekseni `min(0, min(values))` ile `max(0, max(values))` arasında ölçeklenir (sıfır çizgisi her zaman görünür); tek değer veya tüm değerler eşitse düz çizgi, sıfıra bölme yok; boş dizi → boş path. Koordinatlar 2 ondalık.
- `FinanceChart::bars(list<array{in: int, out: int}> $groups, int $width = 720, int $height = 200, int $padding = 8): array{bars: list<array{x: float, y: float, width: float, height: float, kind: 'in'|'out'}>, zero_y: float, max: int}` — her grupta iki yan yana çubuk; ölçek en büyük değere göre; tümü 0 ise yükseklik 0.
- `<x-finance.line-chart :values :labels="[başlangıç, bitiş]" :title>`: `<svg viewBox role="img" aria-label="{title}">`, sıfır çizgisi (kesikli, `stroke-line`), sıfırın altındaki alan `fill-finance-out/15`, çizgi `stroke-brand-500`; alt köşelerde başlangıç/bitiş etiketleri; ekran genişliğine uyar (`w-full h-auto`).
- `<x-finance.bar-chart :groups :labels :title>`: giriş `fill-finance-in`, çıkış `fill-finance-out`; altında ay etiketleri (dar ekranda yalnız ilk/son).

**Test:** `line([0, 100, -100])` → min −100, max 100, zero_y = height/2 (±0.01), 3 nokta, ilk x = padding, son x = width − padding; `line([5, 5, 5])` sıfır çizgisi altta, path üretir (NaN yok: `assertStringNotContainsString('NAN', strtoupper($path))`); `line([])` boş path; `bars([['in'=>100,'out'=>50],['in'=>0,'out'=>0]])` → 4 çubuk, en yüksek çubuk yüksekliği `height − 2×padding`, sıfır grubunun yükseklikleri 0.

Commit: `feat: Finans SVG grafik yardımcısı ve çizgi/çubuk bileşenleri — Aşama 36 (8/11)`

---

### Task 9: Dışa aktarım entegrasyonu ve Finans Raporları ekranı

**Files:**
- Modify: `app/Domain/Reporting/Support/ReportType.php` (yeni türler + `module()` + `pageRoute()`), `app/Domain/Reporting/Services/ReportExportService.php` (yetki türün modülüne göre, finans içeriği, hata bildirimi rotası), `routes/web.php` (indirme rotası yetkisi), `app/Providers/AuthServiceProvider.php` (`report_exports.download` yeteneği)
- Create: `app/Domain/Finance/Reports/FinanceReportExporter.php`, `resources/views/pages/finance/⚡reports.blade.php`
- Modify: `⚡account.blade.php` (ekstre dışa aktarım düğmeleri), `finance-tabs.blade.php` (Raporlar sekmesi, en sonda)
- Test: `tests/Feature/Finance/FinanceReportExportTest.php`, `tests/Feature/Finance/FinanceReportsScreenTest.php`

**Interfaces:**
- `ReportType` yeni case'ler: `FinanceIncomeExpense = 'finance_income_expense'` ("Gelir-Gider Raporu", dosya `gelir-gider-raporu`), `FinanceCashFlow = 'finance_cash_flow'` ("Nakit Akışı Raporu", `nakit-akisi-raporu`), `FinanceAging = 'finance_aging'` ("Cari Yaşlandırma Raporu", `cari-yaslandirma-raporu`), `FinanceStatement = 'finance_statement'` ("Hesap Ekstresi", `hesap-ekstresi`). `module(): Module` (finans türleri `Module::Finance`, diğerleri `Module::Reports`), `pageRoute(): string` (finans türleri `finance.reports`, diğerleri `reports.stock`).
- `ReportExportService::generate`: yetki kontrolü `canModule($export->report->module(), 'read')`; `markFailed` bildirim URL'si `route($export->report->pageRoute())`; `content()` finans türlerini `FinanceReportExporter::content(ReportType, string $format, array $parameters): string`'e devreder (parametreler: `filters` → `FinanceReportFilters::fromArray`, `account_id`, `direction`, `days`).
- `FinanceReportExporter`: rapor servislerinin `table()` çıktısındaki kuruş değerleri gösterime çevirir (PDF: `Money::format`; Excel: `round($k / 100, 2)`), sonra mevcut `ReportDownloader::pdfContent/excelContent` (başlık + `FinanceReportFilters::describe()` — `pdfContent` imzası `ReportFilters` bekliyorsa, finans için `describe()` metnini taşıyan küçük bir uyarlama ekle: `ReportDownloader::pdfContentWithDescription(string $title, array $table, string $description)` ve mevcut `pdfContent` bunu çağırsın; davranış değişmez).
- İndirme rotası: `can:reports.viewAny` yerine `can:report_exports.download`; `AuthServiceProvider`'da `Gate::define('report_exports.download', fn (User $user) => $user->canModule(Module::Reports, 'read') || $user->canModule(Module::Finance, 'read'))` (Admin `Gate::before` ile geçer). Denetleyici zaten yalnızca isteyen kullanıcının dosyasını verir.
- Finans Raporları ekranı (`/finans/raporlar`, `finance.reports`, `finance.viewAny`): sekmeler `#[Url] $report` ∈ `income_expense|cash_flow|aging` (geçersiz → `income_expense`); süzgeçler: Gelir-Gider → tarih aralığı (varsayılan bu ay), şube, hesap; Nakit Akışı → tarih aralığı (varsayılan son 6 ayın başı – bugün), hesap, projeksiyon günü 30/60/90 (varsayılan 90); Yaşlandırma → yön (Tahsil edilecek / Ödenecek). İçerik: Gelir-Gider — iki sütunlu kategori listesi + toplamlar + aylık `x-finance.bar-chart`; Nakit Akışı — aylık tablo + `x-finance.bar-chart`, altında projeksiyon `x-finance.line-chart` ve "Nakit {tarih}'de eksiye düşüyor" uyarısı (varsa); Yaşlandırma — kova tablosu, cari adı `finance.parties.show` bağlantısı. Her rapor için "Excel'e Aktar" / "PDF'e Aktar" (`Gate::authorize('finance.viewAny')` + `ReportExportService::request`), sayfada `<livewire:report-exports />` ve `export-status` bandı (mevcut `report-tabs` deseni).
- Hesap detayında ekstre için aynı iki düğme (`FinanceStatement`, parametre `account_id` + dönem).

**Tests:**
- `FinanceReportExportTest`: finans yetkisi olan, Raporlar yetkisi olmayan personel finans raporu isteyebilir, iş tamamlanır (`QUEUE_CONNECTION=sync`), dosya oluşur, indirme 200; aynı personel stok raporu dışa aktarımı yapamaz (mevcut `reports.viewAny` kontrolü); finans yetkisi kaldırılan kullanıcının bekleyen finans raporu "failed" olur; Excel içeriğinde Gelir-Gider toplamı lira olarak (ör. 5.000 TL → 5000) bulunur (Maatwebsite `Excel::fake()` yerine üretilen dosyayı `PhpSpreadsheet` ile okumak gerekmiyorsa `Excel::raw` çıktısının boş olmadığını ve kaydın `completed` olduğunu doğrulamak yeterli); PDF üretimi hata vermez.
- `FinanceReportsScreenTest`: üç sekme sayıları gösterir (Task 5–7 senaryolarından küçük bir veriyle: gelir toplamı, projeksiyon eksiye düşme uyarısı, yaşlandırma satırı); geçersiz `report`/`days` normalleşir; finans yetkisiz personel 403; dışa aktarım düğmesi bir `ReportExport` kaydı açar.

Görsel kontrol (üç sekme + hesap detayı, 1280/500; grafikler görünür ve taşmıyor) + commit `feat: Finans raporları ekranı ve Excel/PDF dışa aktarımı — Aşama 36 (9/11)`

---

### Task 10: Finans Özeti ekranı

**Files:**
- Create: `resources/views/pages/finance/⚡overview.blade.php`
- Modify: `routes/web.php` (`/finans` → `finance.overview`), `resources/views/layouts/authenticated.blade.php` (menüdeki Finans bağlantısı `finance.overview`, aktiflik `finance.*`), `finance-tabs.blade.php` (ilk sekme "Özet")
- Test: `tests/Feature/Finance/FinanceOverviewScreenTest.php`

**Interfaces / içerik** (spec bölüm 3):
- Hesap yoksa: "Önce bir kasa veya banka hesabı tanımlayın" + Hesaplar bağlantısı (Hareketler'deki boş durum deseni).
- Kartlar: **Toplam nakit** (`FinanceBalanceService::total()`, eksiyse `finance-out`), **Bu ay** (Gelir / Gider / Net — `IncomeExpenseReportService` bu ayın başı–bugün), **7 gün içinde** (ödenecek / tahsil edilecek kalan, gecikenler dahil), **Geciken** (ödeme ve tahsilat kalanı + kayıt sayısı; tıklayınca `finance.obligations?tab=overdue`).
- **90 günlük nakit projeksiyonu**: `x-finance.line-chart` (`CashFlowProjectionService::project(90)`), eksiye düşüyorsa uyarı bandı (tarih ve tutar), altında "Raporlar → Nakit Akışı" bağlantısı.
- **Hesaplar**: hesap adı + bakiye listesi (detaya bağlantı).
- **Yaklaşan**: vadesi en yakın 5 açık kayıt (vade, açıklama, cari, kalan, yöne göre renk) + "Tümü" bağlantısı.
- Sorgu sayısı küçük kalır: bakiye ve kalanlar mevcut toplu servislerden; özet tek istekte hesaplanır.

**Tests:** hesapsız boş durum; kartlardaki rakamlar (küçük senaryo: nakit 10.000, bu ay gelir 2.500 / gider 1.000, 7 gün içinde 2.000 borç ve 500 alacak, 1 geciken); projeksiyon eksiye düşünce uyarı metni; menü bağlantısı `/finans`'a gider ve `finance.viewAny` olmayan personel 403; başka organizasyonun verisi görünmez.

Görsel kontrol (dolu, boş, eksi projeksiyon; 1280/500) + commit `feat: Finans Özeti ekranı — nakit, bu ay, bu hafta, projeksiyon — Aşama 36 (10/11)`

---

### Task 11: Aşama 36 kabul testi ve belge

**Files:**
- Test: `tests/Feature/Acceptance/Stage36FinanceReportsAcceptanceTest.php`
- Modify: `README.md` (Finans satırını genişlet)

Kabul testi (spec Doğrulama: "Ekstre farkı düzeltme hareketiyle kapanır; projeksiyon elle hesaplanmış senaryoyla birebir; Excel dökümü açılır; rakamlar özet ile raporlar arasında tutarlı"):
1. Kasa (açılış 10.000, 01.10), banka (0, 01.10). Gelir 2.500 Hasılat (02.10), gider 1.000 Kira (03.10), kasa→banka 3.000 (04.10).
2. Mutabakat: banka ekstresi 04.10 tarihli 2.950 → fark −5.000 kuruş → `reconcile(adjust: true)` → banka bakiyesi 295_000 ve ekstre (04.10–14.10) kapanışı 295_000.
3. Yükümlülükler: 16.10 vadeli 4.000 alacak, 20.10 vadeli 15.000 borç. Projeksiyon 30 gün: start = toplam nakit (kasa 850_000 + banka 295_000 = 1_145_000); 16.10 → 1_545_000; 20.10 → 45_000; first_negative null.
4. Gelir-Gider (Ekim): income_total 250_000, expense_total 100_000 (düzeltme ve transfer hariç), net 150_000.
5. Tutarlılık: Finans Özeti sayfası "Toplam nakit" olarak `11.450,00 ₺`, bu ay gelir `2.500,00 ₺` ve gider `1.000,00 ₺` gösterir — Gelir-Gider raporuyla aynı; Raporlar sayfası Nakit Akışı sekmesindeki Ekim dönem sonu nakit (`1.145.000` kuruş) Özet'teki toplam nakitle aynı.
6. Dışa aktarım: Gelir-Gider Excel isteği `completed`, dosya mevcut, indirme 200.
7. İzolasyon: ikinci organizasyon Özet'te `0,00 ₺` görür, birinci organizasyonun hesap detayı 404.

README: "...vade bildirimleri, hesap ekstresi ve banka mutabakatı, Finans Özeti (90 günlük nakit projeksiyonu) ve gelir-gider / nakit akışı / cari yaşlandırma raporları (Excel/PDF)."

Steps: kabul testi → tam suite, `pint --test`, `npm run build` → commit `feat: Finans mutabakat, özet ve raporlar kabul testi ve belge — Aşama 36 tamamlandı`. NotebookLM raporunu kontrolcü yazar.

---

## Self-Review Notları

- Spec Aşama 36 satırı: mutabakat ✓ (T3–T4), Finans Özeti ✓ (T10), dört rapor ✓ (T4 ekstre, T5, T6, T7), dışa aktarım ✓ (T9), doğrulama ✓ (T11). Spec bölüm 3 Hareketler "cari" süzgeci ✓ (T1).
- Aşama 35'ten ertelenen: hata alanı ipucu (T1), cari süzgeci (T1), yeniden açılan klinikte geri doldurma kararı (T2), pasif cari/kategori hizası (T2), cari adı tekil indeksi (T2).
- Bilinçli kararlar (rapora): rapor nakit "dönem sonu" tüm hesaplar (pasifler dahil) üzerinden, Özet'teki "toplam nakit" aktif hesaplar üzerinden — kabul testindeki senaryoda pasif hesap yok, ikisi eşit; pasif hesapta bakiye kalmışsa fark doğaldır ve Özet açıklamasında "aktif hesaplar" yazar. Projeksiyon aktif hesaplardan başlar. Excel'de tutarlar lira sayısı (muhasebeci toplayabilsin), PDF'te biçimli metin.
- Tür tutarlılığı: `FinanceReportFilters`, `IncomeExpenseReportService::report/table`, `CashFlowReportService::actual/table`, `CashFlowProjectionService::project/table`, `AgingReportService::report/table/BUCKETS`, `AccountStatementService::statement/table`, `ReconciliationService::preview/reconcile`, `LedgerService::adjust`, `FinanceChart::line/bars`, rota adları `finance.overview`, `finance.reports`, `finance.accounts.show`.
- Not: Task 3 ve 4'te bazı test gövdeleri yorum + birebir beklenen sayılar olarak verildi (senaryo ve rakamlar kesin); uygulayıcı testleri bu sayılarla yazar.
