<?php

namespace Tests\Feature\Platform;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Platform\Exceptions\PlanChangeException;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Services\PlanChangeService;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 19 — paket değişiklik talebi (proje.md Bölüm 12). Doğrulama: talep
 * gönderilir; onaylanınca limitler hemen değişir; reddedilince paket aynı kalır.
 * Ödeme entegrasyonu yok: talep yalnızca bir kayıttır.
 */
class PlanChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
        $this->organization = Organization::create(['name' => 'Gülümseme Diş', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Varsayılan Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    private function openBranch(string $name)
    {
        $this->actingAs($this->admin->fresh());

        return Livewire::test('pages::organization.branches')->call('create')->set('name', $name)->call('save');
    }

    private function requestFromScreen(Plan $plan, string $note = '')
    {
        $this->actingAs($this->admin->fresh());

        return Livewire::test('pages::organization.subscription')->set('requested_plan', $plan->value)->set('note', $note)->call('submitRequest');
    }

    private function titlesFor(User $user): array
    {
        return $user->fresh()->notifications()->where('type', WorkflowNotification::class)->get()->pluck('data.title')->all();
    }

    public function test_an_approved_upgrade_applies_the_new_limits_immediately(): void
    {
        // Başlangıç: 1 şube limiti dolu.
        $this->openBranch('Kadıköy')->assertHasErrors('name');

        // 1) Admin talep gönderir — ödeme tetiklenmez, yalnızca kayıt oluşur; paket henüz değişmez.
        $this->requestFromScreen(Plan::Professional, 'Kadıköy şubesini açıyoruz')->assertHasNoErrors()
            ->assertSee('Başlangıç → Profesyonel')->assertSee('onay bekliyor');
        $request = PlanChangeRequest::sole();
        $this->assertSame(PlanChangeStatus::Pending, $request->status);
        $this->assertSame(Plan::Starter, $this->organization->fresh()->plan);
        $this->openBranch('Kadıköy')->assertHasErrors('name');

        // İkinci bekleyen talep açılamaz.
        $this->expectPlanChangeError(fn () => app(PlanChangeService::class)->request($this->admin->fresh(), Plan::Enterprise));

        // 2) Platform Sahibi talebi görür (menüde sayaç) ve onaylar.
        $this->actingAs($this->owner);
        $this->get('/platform')->assertOk()->assertSee('Paket Talepleri');
        Livewire::test('pages::platform.plan-requests')
            ->assertSee('Gülümseme Diş')->assertSee('yükseltme')->assertSee('Kadıköy şubesini açıyoruz')
            ->set("notes.{$request->id}", 'Havale alındı')
            ->call('approve', $request->id)
            ->assertSee('Talep onaylandı');

        // 3) Yeni limitler hemen uygulanır.
        $this->assertSame(Plan::Professional, $this->organization->fresh()->plan);
        $this->assertSame(PlanChangeStatus::Approved, $request->fresh()->status);
        $this->assertSame($this->owner->id, $request->fresh()->decided_by);
        $this->openBranch('Kadıköy')->assertHasNoErrors();
        $this->assertSame(2, Branch::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());

        // Admin bilgilendirilir; talep geçmişi ve denetim kaydı tutulur.
        $this->assertSame(['Paket talebiniz onaylandı'], $this->titlesFor($this->admin));
        Livewire::test('pages::organization.subscription')->assertSee('Profesyonel')->assertSee('Onaylandı')->assertSee('Havale alındı');
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('entity_type', PlanChangeRequest::class)->where('entity_id', $request->id)->where('organization_id', $this->organization->id)->exists());
        $this->assertSame('professional', AuditLog::withoutGlobalScopes()->where('entity_type', Organization::class)->where('entity_id', $this->organization->id)->latest('id')->first()->after['plan']);
    }

    public function test_a_rejected_request_leaves_the_plan_unchanged(): void
    {
        $this->requestFromScreen(Plan::Enterprise)->assertHasNoErrors();
        $request = PlanChangeRequest::sole();

        $this->actingAs($this->owner);
        // Red gerekçesi zorunlu.
        Livewire::test('pages::platform.plan-requests')->call('reject', $request->id)->assertSee('Red gerekçesini yazın');
        $this->assertSame(PlanChangeStatus::Pending, $request->fresh()->status);

        Livewire::test('pages::platform.plan-requests')->set("notes.{$request->id}", 'Ödeme alınmadı')->call('reject', $request->id);

        $this->assertSame(PlanChangeStatus::Rejected, $request->fresh()->status);
        $this->assertSame(Plan::Starter, $this->organization->fresh()->plan);
        $this->openBranch('Kadıköy')->assertHasErrors('name');
        $this->assertSame(['Paket talebiniz reddedildi'], $this->titlesFor($this->admin));
        $this->assertStringContainsString('Ödeme alınmadı', $this->admin->fresh()->notifications()->sole()->data['message']);

        // Sonuçlanmış talep yeniden sonuçlandırılamaz; yeni talep açılabilir.
        $this->expectPlanChangeError(fn () => app(PlanChangeService::class)->approve($request->fresh(), $this->owner));
        $this->requestFromScreen(Plan::Professional)->assertHasNoErrors();
    }

    public function test_a_downgrade_over_current_usage_warns_both_sides_and_keeps_existing_records(): void
    {
        $this->organization->update(['plan' => 'professional', 'max_users' => 40]);
        $this->openBranch('Kadıköy')->assertHasNoErrors();
        $this->openBranch('Beşiktaş')->assertHasNoErrors();

        $this->actingAs($this->admin->fresh());
        Livewire::test('pages::organization.subscription')->set('requested_plan', Plan::Starter->value)
            ->assertSee('mevcut kullanımınız limitin üstünde kalır')->assertSee('şube 3/1')
            ->call('submitRequest')->assertHasNoErrors();
        $request = PlanChangeRequest::sole();
        $this->assertFalse($request->isUpgrade());

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.plan-requests')->assertSee('düşürme')->assertSee('Limit aşımı uyarısı')->assertSee('şube 3/1')
            ->call('approve', $request->id);

        $organization = $this->organization->fresh();
        $this->assertSame(Plan::Starter, $organization->plan);
        $this->assertNull($organization->max_users, 'onayda organizasyona özel limitler temizlenir, yeni paketin limitleri uygulanır');
        $this->assertSame(3, Branch::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'active')->count(), 'mevcut şubeler korunur');
        $this->openBranch('Üsküdar')->assertHasErrors('name');
    }

    public function test_admin_can_withdraw_a_pending_request(): void
    {
        $this->requestFromScreen(Plan::Professional)->assertHasNoErrors();
        $request = PlanChangeRequest::sole();

        Livewire::test('pages::organization.subscription')->call('cancelRequest', $request->id)->assertSee('geri çekildi');
        $this->assertSame(PlanChangeStatus::Cancelled, $request->fresh()->status);

        $this->actingAs($this->owner);
        $this->expectPlanChangeError(fn () => app(PlanChangeService::class)->approve($request->fresh(), $this->owner));
        $this->assertSame(Plan::Starter, $this->organization->fresh()->plan);
    }

    public function test_request_rules_and_roles(): void
    {
        $changes = app(PlanChangeService::class);

        $this->requestFromScreen(Plan::Starter)->assertHasErrors('requested_plan');
        $this->assertSame(0, PlanChangeRequest::withoutGlobalScopes()->count(), 'mevcut paket talep edilemez');

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::SystemSettings->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'all']);
        $this->actingAs($staff->fresh());
        $this->get('/paket')->assertForbidden();

        foreach ([
            fn () => $changes->request($staff->fresh(), Plan::Professional),
            fn () => $changes->request($this->owner, Plan::Professional),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('yalnızca Ana Klinik Sahibi talep edebilir');
            } catch (AuthorizationException) {
            }
        }

        $request = $changes->request($this->admin, Plan::Professional);

        $this->expectException(AuthorizationException::class);
        $changes->approve($request, $this->admin);
    }

    public function test_a_plan_changed_by_hand_after_the_request_blocks_the_approval(): void
    {
        $this->requestFromScreen(Plan::Professional)->assertHasNoErrors();
        $request = PlanChangeRequest::sole();

        $this->actingAs($this->owner);
        Livewire::test('pages::platform.organization', ['organization' => $this->organization->id])
            ->assertSee('Bekleyen paket talebi')
            ->set('plan', Plan::Enterprise->value)->call('savePlan');

        Livewire::test('pages::platform.plan-requests')->call('approve', $request->id)->assertSee('paketi Kurumsal olarak değişmiş');
        $this->assertSame(PlanChangeStatus::Pending, $request->fresh()->status);
        $this->assertSame(Plan::Enterprise, $this->organization->fresh()->plan);
    }

    public function test_requests_are_isolated_between_organizations(): void
    {
        $this->requestFromScreen(Plan::Professional)->assertHasNoErrors();
        $request = PlanChangeRequest::sole();

        $other = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        Livewire::test('pages::organization.subscription')->assertDontSee('Başlangıç → Profesyonel')->assertSee('Talep Gönder');
        $this->assertNull(PlanChangeRequest::find($request->id));

        try {
            app(PlanChangeService::class)->cancel($request, $otherAdmin);
            $this->fail('başka organizasyonun talebi geri çekilemez');
        } catch (AuthorizationException) {
            $this->assertSame(PlanChangeStatus::Pending, $request->fresh()->status);
        }
    }

    public function test_a_read_only_organization_cannot_send_a_request(): void
    {
        $this->organization->update(['status' => 'read_only']);

        try {
            $this->requestFromScreen(Plan::Professional);
        } catch (\Throwable) {
        }

        $this->assertSame(0, PlanChangeRequest::withoutGlobalScopes()->count());
    }

    private function expectPlanChangeError(\Closure $attempt): void
    {
        try {
            $attempt();
            $this->fail('iş kuralı hatası bekleniyordu');
        } catch (PlanChangeException) {
            $this->addToAssertionCount(1);
        }
    }
}
