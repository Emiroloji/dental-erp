<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Reports\AccountStatementService;
use App\Domain\Finance\Reports\CashFlowReportService;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Reports\IncomeExpenseReportService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\ObligationSummaryService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 37 — Faz 5 Uçtan Uca Kabul Testi. "Gülüş Ağız ve Diş Sağlığı"
 * kliniğinin bir aylık finans senaryosu: kurulum (kategoriler, hesaplar,
 * cariler, tekrarlayan kira), Ekim ayının gelir/gider/transfer/ödeme/iptal
 * hareketleri, ay sonu banka mutabakatı, Excel dökümü, Kasım başındaki
 * gecikmeler ve tahsilat; ardından yetki, salt-okunur organizasyon ve
 * organizasyonlar arası izolasyon. Zaman 01.10.2026'dan 10.11.2026'ya ilerler.
 *
 * Kullanıcının yaptığı her işlem ekrandan (Livewire) yürütülür; yalnızca
 * zamanlanmış işler Artisan ile çalıştırılır. Rakamlar elle hesaplanmıştır.
 *
 * Tutarlılık kuralı: her adımın sonunda toplam nakit, hesap ekstreleri, nakit
 * akışı dönem sonu, Gelir-Gider neti (+ mutabakat düzeltmeleri), yükümlülük
 * durumları ve Finans Özeti aynı rakamı söylemelidir (assertBooksAgree).
 */
class Faz5FinanceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $clinic;

    private User $admin;

    private Branch $central;

    private Branch $north;

    private FinanceAccount $cash;

    private FinanceAccount $bank;

    private FinanceAccount $pos;

    private FinanceParty $insurer;

    private FinanceParty $depot;

    private FinanceObligation $octoberRent;

    private FinanceObligation $insuranceReceivable;

    private FinanceObligation $depotPayable;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_faz5_end_to_end_acceptance(): void
    {
        $this->step1_clinic_sets_up_finance();
        $this->step2_first_week_of_october();
        $this->step3_mid_month_supplier_is_paid_in_two_parts();
        $this->step4_month_end_bank_reconciliation_and_reports();
    }

    private function at(string $datetime): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($datetime, 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse($datetime, 'Europe/Istanbul'));
    }

    private function category(string $name): int
    {
        return FinanceCategory::where('name', $name)->sole()->id;
    }

    private function incomeExpense(string $from, string $to): array
    {
        return app(IncomeExpenseReportService::class)->report(
            FinanceReportFilters::fromArray(['from' => $from, 'to' => $to], CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
        );
    }

    /**
     * Ekrandan gelir/gider hareketi: tarih, o günün saatiyle aynıdır.
     */
    private function entry(string $type, FinanceAccount $account, string $amount, string $category, ?Branch $branch = null): void
    {
        Livewire::test('pages::finance.entries')
            ->call('openForm', $type)
            ->set('accountId', (string) $account->id)
            ->set('amount', $amount)
            ->set('date', FinanceCalendar::today()->toDateString())
            ->set('categoryId', (string) $this->category($category))
            ->set('branchId', $branch ? (string) $branch->id : '')
            ->call('save')
            ->assertHasNoErrors();
    }

    private function pay(FinanceObligation $obligation, FinanceAccount $account, string $amount): void
    {
        Livewire::test('pages::finance.obligations')
            ->call('startPay', $obligation->id)
            ->set('payAccountId', (string) $account->id)
            ->set('payAmount', $amount)
            ->set('payDate', FinanceCalendar::today()->toDateString())
            ->call('confirmPay')
            ->assertHasNoErrors();
    }

    /**
     * Tek defter, her görünüm aynı rakam: toplam nakit, ekstreler, nakit akışı,
     * Gelir-Gider (+ düzeltmeler), yükümlülük durumları ve Finans Özeti.
     */
    private function assertBooksAgree(int $expectedTotal): void
    {
        $balances = app(FinanceBalanceService::class);
        $today = FinanceCalendar::today();

        // 1) Toplam nakit
        $this->assertSame($expectedTotal, $balances->total(), 'Toplam nakit');

        // 2) Her hesabın ekstre kapanışı = bakiyesi; kapanışların toplamı = toplam nakit
        $closings = 0;
        foreach (FinanceAccount::all() as $account) {
            $closing = app(AccountStatementService::class)->statement($account, $account->opening_date, $today)['closing'];
            $this->assertSame($balances->balance($account), $closing, "{$account->name} ekstre kapanışı");
            $closings += $closing;
        }
        $this->assertSame($expectedTotal, $closings, 'Ekstre kapanışlarının toplamı');

        // 3) Nakit akışı dönem sonu
        $filters = FinanceReportFilters::fromArray(['from' => '2026-10-01', 'to' => $today->toDateString()], $today, $today);
        $cashFlow = app(CashFlowReportService::class)->actual($filters);
        $this->assertSame($expectedTotal, end($cashFlow)['closing'], 'Nakit akışı dönem sonu');

        // 4) Defter kimliği: toplam − açılışlar = Gelir-Gider neti + mutabakat düzeltmeleri
        $openings = (int) FinanceAccount::sum('opening_balance');
        $adjustmentIds = FinanceEntry::where('type', FinanceEntryType::Adjustment)->pluck('id');
        $adjustments = (int) FinanceEntry::where(fn ($query) => $query->whereIn('id', $adjustmentIds)
            ->orWhere(fn ($reversal) => $reversal->where('type', FinanceEntryType::Reversal)->whereIn('reverses_entry_id', $adjustmentIds)))
            ->get()->sum(fn (FinanceEntry $entry) => $entry->direction * $entry->amount);
        $net = app(IncomeExpenseReportService::class)->report($filters)['net'];
        $this->assertSame($expectedTotal - $openings, $net + $adjustments, 'Defter kimliği');

        // 5) Yükümlülük durumu kalan tutarla tutarlı
        foreach (FinanceObligation::where('status', '!=', ObligationStatus::Cancelled)->get() as $obligation) {
            $remaining = $balances->remaining($obligation);
            $expected = match (true) {
                $remaining === $obligation->amount => ObligationStatus::Open,
                $remaining === 0 => ObligationStatus::Paid,
                default => ObligationStatus::Partial,
            };
            $this->assertGreaterThanOrEqual(0, $remaining, "{$obligation->description} kalan");
            $this->assertLessThanOrEqual($obligation->amount, $remaining, "{$obligation->description} kalan");
            $this->assertSame($expected, $obligation->status, "{$obligation->description} durumu");
        }

        // 6) Finans Özeti aynı toplamı gösterir
        Livewire::test('pages::finance.overview')->assertSee(Money::format($expectedTotal));
    }

    private function step1_clinic_sets_up_finance(): void
    {
        $this->at('2026-10-01 10:00:00');

        $this->clinic = Organization::create(['name' => 'Gülüş Ağız ve Diş Sağlığı', 'status' => 'active', 'plan' => 'professional']);
        $this->central = Branch::create(['organization_id' => $this->clinic->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->clinic->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->clinic->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        Livewire::test('pages::finance.categories')->call('addSuggested')->assertHasNoErrors();
        $this->assertSame(10, FinanceCategory::count());

        foreach ([['Kasa', 'cash', '20.000,00'], ['Banka', 'bank', '150.000,00'], ['POS', 'pos', '0']] as [$name, $type, $opening]) {
            Livewire::test('pages::finance.accounts')
                ->call('openForm')
                ->set('name', $name)->set('type', $type)->set('openingBalance', $opening)->set('openingDate', '2026-10-01')
                ->call('save')
                ->assertHasNoErrors();
        }
        $this->cash = FinanceAccount::where('name', 'Kasa')->sole();
        $this->bank = FinanceAccount::where('name', 'Banka')->sole();
        $this->pos = FinanceAccount::where('name', 'POS')->sole();
        $this->assertSame([2_000_000, 15_000_000, 0], [$this->cash->opening_balance, $this->bank->opening_balance, $this->pos->opening_balance]);

        foreach ([['Anadolu Sigorta', 'customer', '30'], ['Dental Depo', 'supplier', '15']] as [$name, $kind, $term]) {
            Livewire::test('pages::finance.parties')
                ->call('openForm')
                ->set('name', $name)->set('kind', $kind)->set('defaultTermDays', $term)
                ->call('save')
                ->assertHasNoErrors();
        }
        $this->insurer = FinanceParty::where('name', 'Anadolu Sigorta')->sole();
        $this->depot = FinanceParty::where('name', 'Dental Depo')->sole();

        Livewire::test('pages::finance.recurrings')
            ->call('openForm')
            ->set('formDirection', 'payable')
            ->set('name', 'Kira')
            ->set('amount', '40.000,00')
            ->set('categoryId', (string) $this->category('Kira'))
            ->set('frequency', 'monthly')
            ->set('startsOn', '2026-10-06')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1, FinanceRecurring::count());

        Artisan::call('finance:generate-recurring');

        // Ufuk 01.10 + 35 gün = 05.11: yalnız Ekim kirası üretilir, 06.11 dışarıda kalır.
        $generated = FinanceObligation::whereNotNull('recurring_id')->get();
        $this->assertCount(1, $generated);
        $this->octoberRent = $generated->sole();
        $this->assertSame('Kira — Ekim 2026', $this->octoberRent->description);
        $this->assertSame('2026-10-06', $this->octoberRent->due_on->toDateString());
        $this->assertSame(4_000_000, $this->octoberRent->amount);
        $this->assertSame(ObligationStatus::Open, $this->octoberRent->status);

        $this->assertBooksAgree(17_000_000);
    }

    private function step2_first_week_of_october(): void
    {
        $this->at('2026-10-02 10:00:00');
        $this->entry('income', $this->cash, '12.000,00', 'Hasılat', $this->central);

        $this->at('2026-10-03 10:00:00');
        $this->entry('income', $this->pos, '30.000,00', 'Hasılat', $this->north);

        $this->at('2026-10-05 10:00:00');
        $this->entry('expense', $this->cash, '2.500,00', 'Fatura (elektrik/su/internet)', $this->central);

        $this->at('2026-10-06 10:00:00');
        $this->pay($this->octoberRent, $this->bank, '40.000,00');
        $this->assertSame(ObligationStatus::Paid, $this->octoberRent->fresh()->status);

        $this->at('2026-10-07 10:00:00');
        Livewire::test('pages::finance.entries')
            ->call('openForm', 'transfer')
            ->set('accountId', (string) $this->pos->id)
            ->set('toAccountId', (string) $this->bank->id)
            ->set('amount', '30.000,00')
            ->set('date', '2026-10-07')
            ->set('commission', '600,00')
            ->set('commissionCategoryId', (string) $this->category('Banka/POS Komisyonu'))
            ->call('save')
            ->assertHasNoErrors();
        // Komisyon, transferin hedefi olan Banka'dan gider olarak düşer.
        $commission = FinanceEntry::where('type', FinanceEntryType::Expense)->where('category_id', $this->category('Banka/POS Komisyonu'))->sole();
        $this->assertSame($this->bank->id, $commission->account_id);
        $this->assertSame(60_000, $commission->amount);

        $this->at('2026-10-08 10:00:00');
        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'receivable')
            ->set('formDirection', 'receivable')
            ->set('amount', '25.000,00')
            ->set('dueOn', '')
            ->set('partyId', (string) $this->insurer->id)
            ->set('categoryId', (string) $this->category('Kurum Tahsilatı'))
            ->set('description', 'Sigorta hakedişi')
            ->call('save')
            ->assertHasNoErrors();
        Livewire::test('pages::finance.obligations')
            ->call('openForm', null, 'payable')
            ->set('formDirection', 'payable')
            ->set('amount', '18.000,00')
            ->set('dueOn', '')
            ->set('partyId', (string) $this->depot->id)
            ->set('categoryId', (string) $this->category('Malzeme'))
            ->set('description', 'Malzeme faturası')
            ->call('save')
            ->assertHasNoErrors();
        $this->insuranceReceivable = FinanceObligation::where('party_id', $this->insurer->id)->sole();
        $this->depotPayable = FinanceObligation::where('party_id', $this->depot->id)->sole();
        // Vade boş: carinin vade günü 08.10'a eklenir.
        $this->assertSame('2026-11-07', $this->insuranceReceivable->due_on->toDateString());
        $this->assertSame('2026-10-23', $this->depotPayable->due_on->toDateString());
        $this->assertSame(ObligationDirection::Receivable, $this->insuranceReceivable->direction);
        $this->assertSame(ObligationDirection::Payable, $this->depotPayable->direction);

        $this->at('2026-10-09 10:00:00');
        $this->entry('expense', $this->cash, '1.000,00', 'Malzeme');
        $wrong = FinanceEntry::where('type', FinanceEntryType::Expense)->where('category_id', $this->category('Malzeme'))->sole();

        $this->at('2026-10-10 10:00:00');
        Livewire::test('pages::finance.entries')
            ->call('startReverse', $wrong->id)
            ->set('reverseReason', 'Yanlış kayıt')
            ->call('confirmReverse')
            ->assertHasNoErrors();
        $this->assertNotNull($wrong->fresh()->reversal);

        $balances = app(FinanceBalanceService::class);
        $this->assertSame(2_950_000, $balances->balance($this->cash));
        $this->assertSame(0, $balances->balance($this->pos));
        $this->assertSame(13_940_000, $balances->balance($this->bank));

        $report = $this->incomeExpense('2026-10-01', '2026-10-10');
        $this->assertSame(4_200_000, $report['income_total']);
        $this->assertSame(4_310_000, $report['expense_total']);
        $this->assertSame(-110_000, $report['net']);
        $this->assertSame(
            [['category' => 'Kira', 'total' => 4_000_000], ['category' => 'Fatura (elektrik/su/internet)', 'total' => 250_000], ['category' => 'Banka/POS Komisyonu', 'total' => 60_000]],
            $report['expense'],
        );
        $this->assertNotContains('Malzeme', array_column($report['expense'], 'category'));

        $this->assertBooksAgree(16_890_000);
    }

    private function step3_mid_month_supplier_is_paid_in_two_parts(): void
    {
        $balances = app(FinanceBalanceService::class);

        $this->at('2026-10-20 10:00:00');
        $this->entry('income', $this->cash, '8.000,00', 'Hasılat', $this->central);
        $this->pay($this->depotPayable, $this->bank, '10.000,00');
        $this->assertSame(ObligationStatus::Partial, $this->depotPayable->fresh()->status);
        $this->assertSame(800_000, $balances->remaining($this->depotPayable->fresh()));

        $this->at('2026-10-23 10:00:00');
        $this->pay($this->depotPayable, $this->bank, '8.000,00');
        $this->assertSame(ObligationStatus::Paid, $this->depotPayable->fresh()->status);

        $this->assertSame(3_750_000, $balances->balance($this->cash));
        $this->assertSame(12_140_000, $balances->balance($this->bank));
        $this->assertSame(0, $balances->partyBalances([$this->depot])[$this->depot->id]['net']);

        $this->assertBooksAgree(15_890_000);
    }

    private function step4_month_end_bank_reconciliation_and_reports(): void
    {
        $this->at('2026-10-31 10:00:00');
        $balances = app(FinanceBalanceService::class);

        // Ekstre 121.250,00: kaydedilmemiş 150 TL banka masrafı
        Livewire::test('pages::finance.account', ['account' => $this->bank])
            ->set('statementDate', '2026-10-31')
            ->set('statementBalance', '121.250,00')
            ->set('note', 'Banka masrafı')
            ->call('compare')
            ->assertHasNoErrors()
            ->assertSet('previewSystem', 12_140_000)
            ->assertSet('previewDifference', -15_000)
            ->call('reconcile', true)
            ->assertHasNoErrors();
        $this->assertSame(12_125_000, $balances->balance($this->bank));
        $this->assertSame(1, FinanceEntry::where('type', FinanceEntryType::Adjustment)->count());

        // Ufuk 31.10 + 35 gün = 05.12: Kasım kirası üretilir.
        Artisan::call('finance:generate-recurring');
        $novemberRent = FinanceObligation::where('description', 'Kira — Kasım 2026')->sole();
        $this->assertSame('2026-11-06', $novemberRent->due_on->toDateString());
        $this->assertSame(2, FinanceObligation::whereNotNull('recurring_id')->count());

        // Gelir-Gider (Ekim), ekrandan
        $screen = Livewire::test('pages::finance.reports')
            ->call('selectReport', 'income_expense')
            ->set('from', '2026-10-01')
            ->set('to', '2026-10-31');
        $october = $screen->viewData('income');
        $this->assertSame(5_000_000, $october['income_total']);
        $this->assertSame([['category' => 'Hasılat', 'total' => 5_000_000]], $october['income']);
        $this->assertSame(6_110_000, $october['expense_total']);
        $this->assertSame([
            ['category' => 'Kira', 'total' => 4_000_000],
            ['category' => 'Malzeme', 'total' => 1_800_000],
            ['category' => 'Fatura (elektrik/su/internet)', 'total' => 250_000],
            ['category' => 'Banka/POS Komisyonu', 'total' => 60_000],
        ], $october['expense']);
        $this->assertSame(-1_110_000, $october['net']);
        $this->assertSame([['month' => 'Ekim 2026', 'income' => 5_000_000, 'expense' => 6_110_000, 'net' => -1_110_000]], $october['months']);

        $central = $screen->set('branch', (string) $this->central->id)->viewData('income');
        $this->assertSame(2_000_000, $central['income_total']);
        $this->assertSame(250_000, $central['expense_total']);
        $north = $screen->set('branch', (string) $this->north->id)->viewData('income');
        $this->assertSame(3_000_000, $north['income_total']);
        $this->assertSame(0, $north['expense_total']);
        $rent = $screen->set('branch', '')->set('category', (string) $this->category('Kira'))->viewData('income');
        $this->assertSame(4_000_000, $rent['expense_total']);
        $this->assertSame(0, $rent['income_total']);

        // Nakit Akışı (Ekim) ve 30 günlük projeksiyon, ekrandan
        $cashScreen = Livewire::test('pages::finance.reports')
            ->call('selectReport', 'cash_flow')
            ->set('from', '2026-10-01')
            ->set('to', '2026-10-31')
            ->set('days', '30');
        $cash = $cashScreen->viewData('cash');
        $this->assertCount(1, $cash);
        $this->assertSame('Ekim 2026', $cash[0]['month']);
        $this->assertSame(-1_125_000, $cash[0]['net']);
        $this->assertSame(15_875_000, $cash[0]['closing']);

        $projection = $cashScreen->viewData('projection');
        $this->assertSame(15_875_000, $projection['start']);
        $points = collect($projection['points'])->keyBy('date');
        $this->assertSame(11_875_000, $points['2026-11-06']['balance']);
        $this->assertSame(14_375_000, $points['2026-11-07']['balance']);
        $this->assertNull($projection['first_negative']);

        // Finans Özeti
        Livewire::test('pages::finance.overview')
            ->assertSee('Toplam nakit')
            ->assertSee('158.750,00 ₺')
            ->assertSee('50.000,00 ₺')
            ->assertSee('61.100,00 ₺')
            ->assertSee('40.000,00 ₺')
            ->assertSee('25.000,00 ₺');
        $summary = app(ObligationSummaryService::class)->summarize(0);
        $this->assertSame(4_000_000, $summary['weekPayable']);
        $this->assertSame(2_500_000, $summary['weekReceivable']);

        $this->assertBooksAgree(15_875_000);
    }
}
