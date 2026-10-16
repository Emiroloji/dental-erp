<?php

use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Services\PlatformStatsService;
use App\Domain\Platform\Services\SystemStatus;
use App\Domain\Platform\Support\Plan;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public function with(PlatformStatsService $stats, SystemStatus $system): array
    {
        return [
            'system' => $system->snapshot(),
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

    @php
        $pill = [
            'ok' => ['Sorun yok', 'bg-status-good-bg text-status-good'],
            'warn' => ['Dikkat', 'bg-status-warn-bg text-status-warn'],
            'down' => ['Sorun var', 'bg-status-critical-bg text-status-critical'],
            'unknown' => ['Kayıt yok', 'bg-line text-ink-muted'],
            'off' => ['Kapalı', 'bg-line text-ink-muted'],
        ];
    @endphp
    <section class="mb-8 border border-line rounded-lg bg-surface">
        <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-line">
            <h2 class="text-[15px] font-medium text-ink">Sistem Durumu</h2>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $pill[$system['overall']][1] }}">{{ $pill[$system['overall']][0] }}</span>
        </div>
        <dl class="divide-y divide-line">
            @foreach ($system['checks'] as $check)
                <div class="px-5 py-3 sm:flex sm:items-baseline sm:gap-4">
                    <dt class="sm:w-48 shrink-0 flex items-center gap-2 text-[14px] text-ink">
                        <span @class(['w-2 h-2 rounded-full shrink-0', 'bg-status-good' => $check['status'] === 'ok', 'bg-status-warn' => $check['status'] === 'warn', 'bg-status-critical' => $check['status'] === 'down', 'bg-line' => in_array($check['status'], ['unknown', 'off'], true)])></span>
                        {{ $check['label'] }}
                    </dt>
                    <dd class="mt-1 sm:mt-0 text-[13px] text-ink-muted min-w-0">{{ $check['summary'] }}</dd>
                </div>
            @endforeach
        </dl>
        <details class="border-t border-line">
            <summary class="px-5 py-3 cursor-pointer text-[13px] text-ink-muted hover:text-ink">Görevleri tek tek göster</summary>
            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <tbody>
                        @foreach ($system['tasks'] as $task)
                            <tr class="border-t border-line">
                                <td class="px-5 py-2 font-mono text-ink whitespace-nowrap">{{ $task['command'] }}</td>
                                <td class="px-3 py-2 text-ink-muted whitespace-nowrap">{{ $task['expression'] }}</td>
                                <td class="px-3 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $pill[$task['status']][1] }}">{{ $pill[$task['status']][0] }}</span></td>
                                <td class="px-5 py-2 text-ink-muted">{{ $task['summary'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>

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
