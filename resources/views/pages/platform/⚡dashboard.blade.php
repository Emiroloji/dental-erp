<?php

use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Services\PlatformStatsService;
use App\Domain\Platform\Support\Plan;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public function with(PlatformStatsService $stats): array
    {
        return [
            'summary' => $stats->summary(),
            'nearLimits' => $stats->nearLimits(),
            'statuses' => OrganizationStatus::cases(),
            'plans' => Plan::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Genel Bakış</h1>
        <p class="text-[14px] text-ink-muted mt-1">Platform geneli: organizasyonlar, paketler ve limit kullanımı. Kliniklerin stok ve klinik verisi burada görünmez.</p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Organizasyon</p><p class="text-[22px] font-medium tabular-nums">{{ $summary['organizations'] }}</p></div>
        <a href="{{ route('platform.plan-requests.index') }}" class="bg-surface px-5 py-4 hover:bg-canvas"><p class="text-[12px] text-ink-muted">Bekleyen paket talebi</p><p class="text-[22px] font-medium tabular-nums {{ $summary['pendingPlanRequests'] ? 'text-status-warn' : '' }}">{{ $summary['pendingPlanRequests'] }}</p></a>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Bu ay açılan</p><p class="text-[22px] font-medium tabular-nums">{{ $summary['newThisMonth'] }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Limite yakın</p><p class="text-[22px] font-medium tabular-nums {{ $nearLimits->isNotEmpty() ? 'text-status-warn' : '' }}">{{ $nearLimits->count() }}</p></div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        <section class="border border-line rounded-lg bg-surface px-5 py-4">
            <h2 class="text-[13px] text-ink-muted mb-3">Duruma göre</h2>
            @foreach ($statuses as $status)
                <div class="flex items-center justify-between py-1.5 text-[14px]">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $status->badgeClasses() }}">{{ $status->label() }}</span>
                    <span class="tabular-nums">{{ $summary['byStatus'][$status->value] }}</span>
                </div>
            @endforeach
        </section>
        <section class="border border-line rounded-lg bg-surface px-5 py-4">
            <h2 class="text-[13px] text-ink-muted mb-3">Pakete göre</h2>
            @foreach ($plans as $plan)
                <div class="flex items-center justify-between py-1.5 text-[14px]">
                    <span>{{ $plan->label() }}</span>
                    <span class="tabular-nums">{{ $summary['byPlan'][$plan->value] }}</span>
                </div>
            @endforeach
        </section>
    </div>

    <section>
        <h2 class="text-[15px] font-medium text-ink mb-3">Limitine yaklaşan organizasyonlar (%80+)</h2>
        <div class="border border-line rounded-lg bg-surface overflow-x-auto">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Organizasyon</th>
                        <th class="px-5 py-3 font-medium">Paket</th>
                        <th class="px-5 py-3 font-medium text-right">Şube</th>
                        <th class="px-5 py-3 font-medium text-right">Kullanıcı</th>
                        <th class="px-5 py-3 font-medium text-right">Depolama (MB)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($nearLimits as $row)
                        <tr wire:key="near-{{ $row['organization']->id }}">
                            <td class="px-5 py-3"><a href="{{ route('platform.organizations.show', $row['organization']) }}" class="text-brand-600 hover:underline">{{ $row['organization']->name }}</a></td>
                            <td class="px-5 py-3 text-ink-muted">{{ $row['organization']->plan->label() }}</td>
                            @foreach (['branches', 'users', 'storage_mb'] as $key)
                                <td class="px-5 py-3 text-right tabular-nums {{ $row['limits'][$key] !== null && $row['usage'][$key] >= $row['limits'][$key] ? 'text-status-critical' : '' }}">{{ Number::format($row['usage'][$key], maxPrecision: 1) }} / {{ $row['limits'][$key] ?? '∞' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-ink-muted text-[13px]">Limitine yaklaşan organizasyon yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
