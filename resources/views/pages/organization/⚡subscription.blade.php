<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanChangeException;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Services\PlanChangeService;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $requested_plan = '';

    public string $note = '';

    public function submitRequest(PlanChangeService $changes): void
    {
        Gate::authorize('subscription.view');

        $this->validate([
            'requested_plan' => ['required', Rule::enum(Plan::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['requested_plan.required' => 'Bir paket seçin.']);

        try {
            $changes->request(auth()->user(), Plan::from($this->requested_plan), $this->note);
        } catch (PlanChangeException $e) {
            $this->addError('requested_plan', $e->getMessage());

            return;
        }

        $this->reset(['requested_plan', 'note']);
        session()->flash('status', 'Paket talebiniz Platform Sahibi\'ne iletildi. Onaylandığında yeni limitler otomatik uygulanır.');
    }

    public function cancelRequest(int $requestId, PlanChangeService $changes): void
    {
        Gate::authorize('subscription.view');

        try {
            $changes->cancel(PlanChangeRequest::findOrFail($requestId), auth()->user());
        } catch (PlanChangeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Paket talebi geri çekildi.');
    }

    public function with(PlanLimitService $limits): array
    {
        $organization = Organization::findOrFail(auth()->user()->organization_id);
        $usage = $limits->usage($organization);
        $limitValues = $organization->limits();
        $selected = Plan::tryFrom($this->requested_plan);

        return [
            'organization' => $organization,
            'rows' => [
                ['label' => 'Aktif şube', 'used' => $usage['branches'], 'limit' => $limitValues['branches'], 'unit' => ''],
                ['label' => 'Aktif kullanıcı', 'used' => $usage['users'], 'limit' => $limitValues['users'], 'unit' => ''],
                ['label' => 'Depolama', 'used' => $usage['storage_mb'], 'limit' => $limitValues['storage_mb'], 'unit' => ' MB'],
            ],
            'plans' => Plan::cases(),
            'pending' => PlanChangeRequest::where('status', PlanChangeStatus::Pending)->latest('id')->first(),
            'history' => PlanChangeRequest::with(['requester', 'decider'])->latest('id')->limit(10)->get(),
            'overages' => $selected && $selected !== $organization->plan ? $limits->overagesFor($organization, $selected) : [],
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
    </section>

    <section class="mt-8 border border-line rounded-lg bg-surface px-5 py-5">
        <h2 class="text-[15px] font-medium text-ink">Paket değişikliği talebi</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Talep bir ödeme başlatmaz; Platform Sahibi ödemeyi kontrol edip onayladığında yeni limitler otomatik uygulanır. Reddedilirse mevcut paketiniz devam eder.</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
        @endif

        @if ($pending)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-status-warn/30 bg-status-warn-bg px-4 py-3 text-[14px]">
                <span><span class="font-medium">{{ $pending->current_plan->label() }} → {{ $pending->requested_plan->label() }}</span> talebiniz onay bekliyor ({{ $pending->created_at->format('d.m.Y H:i') }}).</span>
                <button wire:click="cancelRequest({{ $pending->id }})" wire:confirm="Paket talebi geri çekilsin mi?" class="text-[13px] text-status-critical hover:underline">Talebi Geri Çek</button>
            </div>
        @else
            <form wire:submit="submitRequest" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1">İstenen paket</label>
                        <select wire:model.live="requested_plan" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            <option value="">Paket seçin</option>
                            @foreach ($plans as $plan)
                                @continue($plan === $organization->plan)
                                <option value="{{ $plan->value }}">{{ $plan->label() }}</option>
                            @endforeach
                        </select>
                        @error('requested_plan') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[12px] text-ink-muted mb-1">Not (isteğe bağlı)</label>
                        <input type="text" wire:model="note" placeholder="Örn. yeni şube açıyoruz" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    </div>
                </div>
                @if ($overages !== [])
                    <div class="rounded-md bg-status-warn-bg border border-status-warn/30 text-status-warn text-[13px] px-4 py-3">
                        Bu pakette mevcut kullanımınız limitin üstünde kalır ({{ collect($overages)->map(fn ($o, $key) => ['branches' => 'şube', 'users' => 'kullanıcı', 'storage_mb' => 'depolama MB'][$key].' '.Number::format($o['used'], maxPrecision: 2).'/'.$o['limit'])->join(', ') }}). Onaylanırsa mevcut kayıtlarınız korunur, ancak limitin altına inene kadar yeni ekleme yapamazsınız.
                    </div>
                @endif
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Talep Gönder</button>
            </form>
        @endif

        @if ($history->isNotEmpty())
            <h3 class="mt-6 mb-2 text-[13px] text-ink-muted">Talep geçmişi</h3>
            <div class="divide-y divide-line text-[13px]">
                @foreach ($history as $item)
                    <div class="py-2 flex flex-wrap items-center gap-x-3 gap-y-1" wire:key="plan-request-{{ $item->id }}">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $item->status->badgeClasses() }}">{{ $item->status->label() }}</span>
                        <span>{{ $item->current_plan->label() }} → {{ $item->requested_plan->label() }}</span>
                        <span class="text-ink-muted">{{ $item->created_at->format('d.m.Y') }} · {{ $item->requester?->name ?? '—' }}</span>
                        @if ($item->decision_note)<span class="text-ink-muted">— {{ $item->decision_note }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
