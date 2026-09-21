<?php

use App\Domain\Platform\Models\Lead;
use App\Domain\Platform\Services\LeadService;
use App\Domain\Platform\Support\LeadStatus;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gelen Talepler (fazlar-adimlar.md Aşama 29.2): tanıtım sitesindeki formdan
 * düşen talepler. Otomatik hiçbir şey olmaz — Platform Sahibi talebi görür,
 * elle iletişime geçer, uygun görürse organizasyonu Organizasyonlar
 * ekranından kendisi açar (proje.md Bölüm 2 onboarding akışı).
 */
new #[Layout('layouts::platform')] class extends Component
{
    use WithPagination;

    public string $statusFilter = 'new';

    /** @var array<int, string> talep id => iç not */
    public array $notes = [];

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function markStatus(int $leadId, string $status, LeadService $leads): void
    {
        $leads->updateStatus(
            Lead::findOrFail($leadId),
            LeadStatus::from($status),
            auth()->user(),
            $this->notes[$leadId] ?? null,
        );

        unset($this->notes[$leadId]);

        session()->flash('status', 'Talebin durumu güncellendi.');
    }

    public function with(): array
    {
        return [
            'leads' => Lead::query()
                ->with('handler')
                ->when($this->statusFilter, fn ($query, $value) => $query->where('status', $value))
                ->latest('id')
                ->paginate(20),
            'statuses' => LeadStatus::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Gelen Talepler</h1>
        <p class="text-[14px] text-ink-muted mt-1">Tanıtım sitesindeki formdan gelen talepler. Form otomatik hesap açmaz; iletişime geçip uygun görürseniz organizasyonu Organizasyonlar ekranından siz oluşturursunuz.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
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
        @forelse ($leads as $lead)
            <section wire:key="lead-{{ $lead->id }}" class="border border-line rounded-lg bg-surface px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[15px] font-medium text-ink">{{ $lead->clinic_name }}</p>
                        <p class="text-[14px] text-ink mt-0.5">{{ $lead->name }} · <span class="text-ink-muted">{{ $lead->contactLine() ?: '—' }}</span></p>
                        <p class="text-[12px] text-ink-muted mt-0.5">{{ $lead->created_at->format('d.m.Y H:i') }}</p>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $lead->status->badgeClasses() }}">{{ $lead->status->label() }}</span>
                </div>

                @if ($lead->note)
                    <p class="mt-3 text-[14px] text-ink-muted leading-relaxed border-l-2 border-line pl-3">{{ $lead->note }}</p>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <input type="text" wire:model="notes.{{ $lead->id }}" placeholder="İç not (yalnızca platform görür)" class="flex-1 min-w-[220px] border border-line rounded-md px-3 py-2 text-[14px]">
                    @foreach ($statuses as $status)
                        @if ($status !== $lead->status)
                            <button wire:click="markStatus({{ $lead->id }}, '{{ $status->value }}')" class="text-[13px] text-ink-muted hover:text-ink hover:underline">{{ $status->label() }}</button>
                        @endif
                    @endforeach
                </div>

                @if ($lead->handled_at)
                    <p class="mt-2 text-[12px] text-ink-muted">Son işlem: {{ $lead->handler?->name ?? '—' }}, {{ $lead->handled_at->format('d.m.Y H:i') }}@if ($lead->internal_note) — {{ $lead->internal_note }}@endif</p>
                @endif
            </section>
        @empty
            <p class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrede talep yok.</p>
        @endforelse
    </div>

    @if ($leads->hasPages())
        <div class="mt-4">{{ $leads->links() }}</div>
    @endif
</div>
