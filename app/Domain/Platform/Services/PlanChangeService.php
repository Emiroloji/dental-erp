<?php

namespace App\Domain\Platform\Services;

use App\Domain\Access\Services\NotificationRecipients;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanChangeException;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Paket değişikliği: talep → Platform Sahibi manuel onayı (proje.md Bölüm 12,
 * Faz 3 kullanıcı kararı). Ödeme entegrasyonu yoktur; talep yalnızca bir
 * kayıttır. Platform Sahibi ödemeyi sistem dışında kontrol eder.
 *
 * - Onay: yeni paketin limitleri hemen uygulanır (organizasyona özel limitler
 *   temizlenir). Paket düşürmede mevcut kullanım aşılıyorsa kayıtlar korunur,
 *   yalnızca yeni ekleme engellenir; aşım onay ekranında uyarı olarak görünür.
 * - Red: organizasyon eski paketinde kalır.
 */
class PlanChangeService
{
    public function __construct(
        private readonly OrganizationAdminService $organizations,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function request(User $admin, Plan $plan, ?string $note = null): PlanChangeRequest
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('Paket değişikliğini yalnızca Ana Klinik Sahibi talep edebilir.');
        }

        return DB::transaction(function () use ($admin, $plan, $note) {
            $organization = Organization::whereKey($admin->organization_id)->lockForUpdate()->firstOrFail();

            if ($organization->plan === $plan) {
                throw new PlanChangeException("Organizasyonunuz zaten {$plan->label()} paketinde.");
            }

            $pending = PlanChangeRequest::where('organization_id', $organization->id)->where('status', PlanChangeStatus::Pending)->exists();

            if ($pending) {
                throw new PlanChangeException('Onay bekleyen bir paket talebiniz var; yeni talep için önce onu geri çekin veya kararı bekleyin.');
            }

            return PlanChangeRequest::create([
                'organization_id' => $organization->id,
                'current_plan' => $organization->plan,
                'requested_plan' => $plan,
                'note' => trim((string) $note) ?: null,
                'status' => PlanChangeStatus::Pending,
                'requested_by' => $admin->id,
            ]);
        });
    }

    public function cancel(PlanChangeRequest $request, User $admin): PlanChangeRequest
    {
        if (! $admin->isAdmin() || $admin->organization_id !== $request->organization_id) {
            throw new AuthorizationException('Bu talebi geri çekme yetkiniz yok.');
        }

        return $this->decide($request, PlanChangeStatus::Cancelled, $admin, null);
    }

    public function approve(PlanChangeRequest $request, User $owner, ?string $note = null): PlanChangeRequest
    {
        $this->ensurePlatformOwner($owner);

        $request = $this->decide($request, PlanChangeStatus::Approved, $owner, $note, function (PlanChangeRequest $locked) use ($owner) {
            $organization = Organization::whereKey($locked->organization_id)->lockForUpdate()->firstOrFail();

            // Talep sonrası paket panelden elle değiştirildiyse onay anlamını yitirir.
            if ($organization->plan !== $locked->current_plan) {
                throw new PlanChangeException("Talep açıldıktan sonra organizasyonun paketi {$organization->plan->label()} olarak değişmiş; talebi reddedip gerekirse yeniden değerlendirin.");
            }

            $this->organizations->applyPlan($organization, $locked->requested_plan, [], $owner);
        });

        $this->notifyClinic($request, 'Paket talebiniz onaylandı', WorkflowNotification::LEVEL_GOOD);

        return $request;
    }

    public function reject(PlanChangeRequest $request, User $owner, ?string $note): PlanChangeRequest
    {
        $this->ensurePlatformOwner($owner);

        if (trim((string) $note) === '') {
            throw new PlanChangeException('Red gerekçesini yazın; kliniğe iletilecek.');
        }

        $request = $this->decide($request, PlanChangeStatus::Rejected, $owner, $note);
        $this->notifyClinic($request, 'Paket talebiniz reddedildi', WorkflowNotification::LEVEL_WARN);

        return $request;
    }

    /**
     * Satır kilidiyle durumu yeniden okur; yalnızca bekleyen talep sonuçlanır.
     */
    private function decide(PlanChangeRequest $request, PlanChangeStatus $status, User $actor, ?string $note, ?\Closure $effect = null): PlanChangeRequest
    {
        DB::transaction(function () use ($request, $status, $actor, $note, $effect) {
            $locked = PlanChangeRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlanChangeStatus::Pending) {
                throw new PlanChangeException("Bu talep zaten sonuçlanmış ({$locked->status->label()}).");
            }

            if ($effect) {
                $effect($locked);
            }

            $locked->update([
                'status' => $status,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => trim((string) $note) ?: null,
            ]);
        });

        return PlanChangeRequest::withoutGlobalScopes()->findOrFail($request->id);
    }

    private function notifyClinic(PlanChangeRequest $request, string $title, string $level): void
    {
        $message = "{$request->current_plan->label()} → {$request->requested_plan->label()}"
            .(filled($request->decision_note) ? " — {$request->decision_note}" : '');

        $this->recipients->send(
            $this->recipients->admins($request->organization_id),
            new WorkflowNotification('plan', $title, $message, route('subscription.show'), $level),
        );
    }

    private function ensurePlatformOwner(User $actor): void
    {
        if (! $actor->isPlatformOwner()) {
            throw new AuthorizationException('Paket talebini yalnızca Platform Sahibi sonuçlandırabilir.');
        }
    }
}
