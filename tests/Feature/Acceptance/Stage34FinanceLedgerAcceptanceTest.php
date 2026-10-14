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

        $cash = FinanceAccount::create(['name' => 'Merkez Kasa', 'type' => AccountType::Cash, 'opening_balance' => 1_000_000, 'opening_date' => '2026-10-01']);
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
        $this->get('/finans/hareketler')->assertOk()->assertDontSee('Merkez Kasa');

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
