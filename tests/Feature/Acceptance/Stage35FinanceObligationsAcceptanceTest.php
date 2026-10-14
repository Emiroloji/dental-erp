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
