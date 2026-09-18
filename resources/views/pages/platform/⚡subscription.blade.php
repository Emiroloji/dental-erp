<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\Plan;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public function with(PlanLimitService $limits): array
    {
        $organization = Organization::findOrFail(auth()->user()->organization_id);
        $usage = $limits->usage($organization);
        $limitValues = $organization->limits();

        return [
            'organization' => $organization,
            'rows' => [
                ['label' => 'Aktif şube', 'used' => $usage['branches'], 'limit' => $limitValues['branches'], 'unit' => ''],
                ['label' => 'Aktif kullanıcı', 'used' => $usage['users'], 'limit' => $limitValues['users'], 'unit' => ''],
                ['label' => 'Depolama', 'used' => $usage['storage_mb'], 'limit' => $limitValues['storage_mb'], 'unit' => ' MB'],
            ],
            'plans' => Plan::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Paket ve Kullanım</h1>
        <p class="text-[14px] text-ink-muted mt-1">{{ $organization->name }} — <span class="font-medium text-ink">{{ $organization->plan->label() }}</span> paketi. Limit dolduğunda yeni şube, personel veya belge eklenemez; mevcut kayıtlar etkilenmez.</p>
    </div>

    <section class="border border-line rounded-lg bg-surface divide-y divide-line">
        @foreach ($rows as $row)
            @php
                $ratio = $row['limit'] ? min(1, $row['used'] / max($row['limit'], 1)) : 0;
                $full = $row['limit'] !== null && $row['used'] >= $row['limit'];
            @endphp
            <div class="px-5 py-4">
                <div class="flex items-baseline justify-between text-[14px]">
                    <span>{{ $row['label'] }}</span>
                    <span class="tabular-nums {{ $full ? 'text-status-critical font-medium' : 'text-ink-muted' }}">
                        {{ Number::format($row['used'], maxPrecision: 2) }}{{ $row['unit'] }} / {{ $row['limit'] === null ? 'Sınırsız' : Number::format($row['limit']).$row['unit'] }}
                        @if ($full) · limit dolu @endif
                    </span>
                </div>
                @if ($row['limit'] !== null)
                    <div class="mt-2 h-1.5 rounded-full bg-line overflow-hidden">
                        <div class="h-full rounded-full {{ $full ? 'bg-status-critical' : ($ratio >= 0.8 ? 'bg-status-warn' : 'bg-brand-500') }}" style="width: {{ round($ratio * 100) }}%"></div>
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    <section class="mt-8">
        <h2 class="text-[15px] font-medium text-ink mb-3">Paketler</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach ($plans as $plan)
                <div class="border rounded-lg px-4 py-4 bg-surface {{ $plan === $organization->plan ? 'border-brand-500' : 'border-line' }}">
                    <p class="text-[14px] font-medium">{{ $plan->label() }} @if ($plan === $organization->plan)<span class="text-[12px] text-brand-600 font-normal">· mevcut</span>@endif</p>
                    <ul class="mt-2 text-[13px] text-ink-muted space-y-0.5">
                        <li>{{ $plan->maxBranches() === null ? 'Sınırsız' : $plan->maxBranches() }} şube</li>
                        <li>{{ $plan->maxUsers() === null ? 'Sınırsız' : $plan->maxUsers() }} kullanıcı</li>
                        <li>{{ $plan->maxStorageMb() === null ? 'Özel' : Number::format($plan->maxStorageMb() / 1024).' GB' }} depolama</li>
                    </ul>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-[12px] text-ink-muted">Paket değişikliği talebi Platform Sahibi'nin onayıyla uygulanır.</p>
    </section>
</div>
