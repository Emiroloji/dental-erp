<?php

namespace App\Domain\Platform\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Platform istatistikleri (fazlar-adimlar.md Faz 3). Yalnızca platform
 * seviyesi sayılar: organizasyon, paket, durum, kullanıcı ve limit kullanımı.
 * Stok, ürün veya klinik verisi hiçbir zaman okunmaz (kurallar.md Bölüm 4).
 */
class PlatformStatsService
{
    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * @return array{organizations: int, byStatus: array<string, int>, byPlan: array<string, int>, users: int, newThisMonth: int, pendingPlanRequests: int}
     */
    public function summary(): array
    {
        $statusCounts = Organization::query()->toBase()->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
        $planCounts = Organization::query()->toBase()->groupBy('plan')->selectRaw('plan, COUNT(*) as total')->pluck('total', 'plan');

        return [
            'organizations' => (int) $statusCounts->sum(),
            'byStatus' => collect(OrganizationStatus::cases())->mapWithKeys(fn (OrganizationStatus $status) => [$status->value => (int) ($statusCounts[$status->value] ?? 0)])->all(),
            'byPlan' => collect(Plan::cases())->mapWithKeys(fn (Plan $plan) => [$plan->value => (int) ($planCounts[$plan->value] ?? 0)])->all(),
            'users' => User::whereNotNull('organization_id')->where('status', 'active')->count(),
            'newThisMonth' => Organization::where('created_at', '>=', now()->startOfMonth())->count(),
            'pendingPlanRequests' => PlanChangeRequest::withoutGlobalScopes()->where('status', PlanChangeStatus::Pending)->count(),
        ];
    }

    /**
     * Limitinin %80'ine ulaşmış veya aşmış aktif organizasyonlar — paket
     * görüşmesi yapılacak müşteriler.
     *
     * @return Collection<int, array{organization: Organization, usage: array<string, int|float>, limits: array<string, ?int>, highest: float}>
     */
    public function nearLimits(float $threshold = 0.8): Collection
    {
        return Organization::where('status', '!=', OrganizationStatus::Passive->value)
            ->orderBy('name')
            ->get()
            ->map(function (Organization $organization) {
                $usage = $this->limits->usage($organization);
                $limits = $organization->limits();
                $ratios = collect($limits)->filter()->map(fn (int $limit, string $key) => $usage[$key] / $limit);

                return ['organization' => $organization, 'usage' => $usage, 'limits' => $limits, 'highest' => (float) ($ratios->max() ?? 0)];
            })
            ->filter(fn (array $row) => $row['highest'] >= $threshold)
            ->sortByDesc('highest')
            ->values();
    }
}
