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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceDueAlertTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToIstanbul('2026-10-14 09:00:00');
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Servis CarbonImmutable ile, bildirim created_at'i Carbon ile üretilir; sıralama
     * testinin kararlı olması için ikisi de aynı ana sabitlenir.
     */
    private function travelToIstanbul(string $moment): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, 'Europe/Istanbul'));
        Carbon::setTestNow(Carbon::parse($moment, 'Europe/Istanbul'));
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

        $this->travelToIstanbul('2026-10-17 09:00:00');
        $this->assertSame(1, $service->scan());
        $this->assertSame(['upcoming', 'overdue'], $this->admin->notifications()->reorder()->oldest()->get()->pluck('data.state')->all());
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

    public function test_one_organization_failing_does_not_stop_others_from_being_notified(): void
    {
        $broken = $this->obligation('2026-10-16');
        DB::table('finance_obligations')->where('id', $broken->id)->update(['direction' => 'bozuk']);

        $other = Organization::create(['name' => 'Diğer Klinik', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        FinanceObligation::create(['organization_id' => $other->id, 'direction' => ObligationDirection::Payable, 'amount' => 100, 'due_on' => '2026-10-15', 'description' => 'Sağlam kayıt']);

        $sent = app(FinanceDueAlertService::class)->scan();

        $this->assertSame(1, $sent);
        $this->assertSame(0, $this->admin->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
    }
}
