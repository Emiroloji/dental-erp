<?php

namespace Tests\Feature\Finance;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceRecurringsScreenTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCategory $rent;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Europe/Istanbul'));
        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
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
            ->assertHasErrors('categoryId');
    }

    private function makeRecurring(): FinanceRecurring
    {
        return FinanceRecurring::create([
            'direction' => ObligationDirection::Payable, 'name' => 'Kira', 'amount' => 2_000_000, 'category_id' => $this->rent->id,
            'frequency' => RecurringFrequency::Monthly, 'day' => 5, 'starts_on' => '2026-11-05', 'next_due_on' => '2026-11-05',
        ]);
    }

    public function test_direction_change_resets_category_and_errors_route_to_fields(): void
    {
        $revenue = FinanceCategory::create(['name' => 'Hasılat', 'direction' => CategoryDirection::Income]);

        Livewire::test('pages::finance.recurrings')
            ->call('openForm')
            ->set('categoryId', (string) $this->rent->id)
            ->set('formDirection', 'receivable')
            ->assertSet('categoryId', '');

        Livewire::test('pages::finance.recurrings')
            ->call('openForm')->set('name', 'Kira')->set('amount', '1')->set('categoryId', (string) $revenue->id)->set('startsOn', '2026-11-05')
            ->call('save')
            ->assertHasErrors('categoryId')->assertHasNoErrors('name');

        $passive = FinanceParty::create(['name' => 'Eski', 'kind' => PartyKind::Supplier, 'status' => 'passive']);
        Livewire::test('pages::finance.recurrings')
            ->call('openForm')->set('name', 'Kira')->set('amount', '1')->set('categoryId', (string) $this->rent->id)->set('partyId', (string) $passive->id)->set('startsOn', '2026-11-05')
            ->call('save')
            ->assertHasErrors('partyId');

        Livewire::test('pages::finance.recurrings')
            ->call('openForm')->set('name', 'Kira')->set('amount', '1')->set('categoryId', (string) $this->rent->id)->set('startsOn', '2026-11-05')->set('endsOn', '2026-10-01')
            ->call('save')
            ->assertHasErrors('endsOn');

        $this->assertSame(0, FinanceRecurring::count());
    }

    public function test_edit_ignores_tampered_direction_frequency_and_start(): void
    {
        $recurring = $this->makeRecurring();

        Livewire::test('pages::finance.recurrings')
            ->call('openForm', $recurring->id)
            ->set('formDirection', 'receivable')->set('categoryId', (string) $this->rent->id)->set('frequency', 'weekly')->set('startsOn', '2027-01-01')->set('amount', '21.000')
            ->call('save')->assertHasNoErrors();

        $fresh = $recurring->fresh();
        $this->assertSame(ObligationDirection::Payable, $fresh->direction);
        $this->assertSame(RecurringFrequency::Monthly, $fresh->frequency);
        $this->assertSame('2026-11-05', $fresh->starts_on->toDateString());
        $this->assertSame(2_100_000, $fresh->amount);
    }

    public function test_read_only_staff_cannot_mutate(): void
    {
        $recurring = $this->makeRecurring();
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Finance->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        Livewire::test('pages::finance.recurrings')->call('openForm')->assertForbidden();
        Livewire::test('pages::finance.recurrings')->call('openForm', $recurring->id)->assertForbidden();
        Livewire::test('pages::finance.recurrings')->set('name', 'X')->call('save')->assertForbidden();
        Livewire::test('pages::finance.recurrings')->call('deactivate', $recurring->id)->assertForbidden();
        Livewire::test('pages::finance.recurrings')->call('activate', $recurring->id)->assertForbidden();

        $this->assertSame('active', $recurring->fresh()->status);
        $this->assertSame(1, FinanceRecurring::count());
    }

    public function test_other_organization_cannot_touch_recurring(): void
    {
        $recurring = $this->makeRecurring();
        $orgB = Organization::create(['name' => 'Klinik B', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $orgB->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        foreach ([
            fn () => Livewire::test('pages::finance.recurrings')->call('deactivate', $recurring->id),
            fn () => Livewire::test('pages::finance.recurrings')->call('openForm', $recurring->id),
        ] as $action) {
            try {
                $action();
                $this->fail('Yabancı kiracı kaydı için ModelNotFoundException bekleniyordu.');
            } catch (ModelNotFoundException $e) {
                $this->assertInstanceOf(ModelNotFoundException::class, $e);
            }
        }

        $fresh = $recurring->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame(2_000_000, $fresh->amount);
    }
}
