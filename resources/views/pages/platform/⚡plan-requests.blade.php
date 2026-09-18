<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanChangeException;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Services\PlanChangeService;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\PlanChangeStatus;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::platform')] class extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    /** @var array<int, string> talep id => karar notu */
    public array $notes = [];

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function approve(int $requestId, PlanChangeService $changes): void
    {
        $this->decide(fn () => $changes->approve($this->find($requestId), auth()->user(), $this->notes[$requestId] ?? null), 'Talep onaylandı; yeni paketin limitleri uygulandı.');
    }

    public function reject(int $requestId, PlanChangeService $changes): void
    {
        $this->decide(fn () => $changes->reject($this->find($requestId), auth()->user(), $this->notes[$requestId] ?? null), 'Talep reddedildi; organizasyon mevcut paketinde kaldı.');
    }

    private function decide(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (PlanChangeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', $success);
    }

    private function find(int $requestId): PlanChangeRequest
    {
        return PlanChangeRequest::withoutGlobalScopes()->findOrFail($requestId);
    }

    public function with(PlanLimitService $limits): array
    {
        $requests = PlanChangeRequest::withoutGlobalScopes()
            ->with(['requester', 'decider'])
            ->when($this->statusFilter, fn ($query, $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate(20);

        $organizations = Organization::whereIn('id', $requests->getCollection()->pluck('organization_id'))->get()->keyBy('id');

        $requests->setCollection($requests->getCollection()->map(fn (PlanChangeRequest $request) => [
            'request' => $request,
            'organization' => $organizations[$request->organization_id],
            'overages' => $request->status === PlanChangeStatus::Pending ? $limits->overagesFor($organizations[$request->organization_id], $request->requested_plan) : [],
        ]));

        return [
            'rows' => $requests,
            'statuses' => PlanChangeStatus::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Paket Talepleri</h1>
        <p class="text-[14px] text-ink-muted mt-1">Kliniklerin yükseltme/düşürme talepleri. Ödemeyi sistem dışında kontrol edip onaylayın veya reddedin; onayda yeni paketin limitleri hemen uygulanır.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px]">
            <option value="">Tüm Talepler</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="space-y-3">
        @forelse ($rows as $row)
            @php($request = $row['request'])
            <section wire:key="plan-request-{{ $request->id }}" class="border border-line rounded-lg bg-surface px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <a href="{{ route('platform.organizations.show', $row['organization']) }}" class="text-[15px] font-medium text-brand-600 hover:underline">{{ $row['organization']->name }}</a>
                        <div class="text-[14px] mt-0.5">
                            {{ $request->current_plan->label() }} → <span class="font-medium">{{ $request->requested_plan->label() }}</span>
                            <span class="ml-1 text-[12px] {{ $request->isUpgrade() ? 'text-status-good' : 'text-status-warn' }}">{{ $request->isUpgrade() ? 'yükseltme' : 'düşürme' }}</span>
                        </div>
                        <div class="text-[12px] text-ink-muted mt-0.5">{{ $request->requester?->name ?? '—' }} · {{ $request->created_at->format('d.m.Y H:i') }}@if ($request->note) · "{{ $request->note }}"@endif</div>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $request->status->badgeClasses() }}">{{ $request->status->label() }}</span>
                </div>

                @if ($row['overages'] !== [])
                    <div class="mt-3 rounded-md bg-status-warn-bg border border-status-warn/30 text-status-warn text-[13px] px-4 py-2.5">
                        Limit aşımı uyarısı: {{ collect($row['overages'])->map(fn ($o, $key) => ['branches' => 'şube', 'users' => 'kullanıcı', 'storage_mb' => 'depolama MB'][$key].' '.Number::format($o['used'], maxPrecision: 2).'/'.$o['limit'])->join(', ') }}. Onaylarsanız mevcut kayıtlar korunur, limitin altına inene kadar yeni ekleme yapılamaz.
                    </div>
                @endif

                @if ($request->status === \App\Domain\Platform\Support\PlanChangeStatus::Pending)
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <input type="text" wire:model="notes.{{ $request->id }}" placeholder="Not / red gerekçesi (klinik görür)" class="flex-1 min-w-[220px] border border-line rounded-md px-3 py-2 text-[14px]">
                        <button wire:click="approve({{ $request->id }})" wire:confirm="Ödemeyi kontrol ettiniz mi? Talep onaylanırsa {{ $request->requested_plan->label() }} paketinin limitleri hemen uygulanır." class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onayla</button>
                        <button wire:click="reject({{ $request->id }})" class="text-[14px] text-status-critical hover:underline">Reddet</button>
                    </div>
                @elseif ($request->decided_at)
                    <p class="mt-2 text-[12px] text-ink-muted">{{ $request->status->label() }}: {{ $request->decider?->name ?? '—' }}, {{ $request->decided_at->format('d.m.Y H:i') }}@if ($request->decision_note) — {{ $request->decision_note }}@endif</p>
                @endif
            </section>
        @empty
            <p class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrede paket talebi yok.</p>
        @endforelse
    </div>

    @if ($rows->hasPages())
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</div>
