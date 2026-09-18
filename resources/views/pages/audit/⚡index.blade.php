<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditAction;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $entityFilter = '';

    public string $actionFilter = '';

    public function updatingEntityFilter(): void
    {
        $this->resetPage();
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->when($this->entityFilter, fn ($query) => $query->where('entity_type', $this->entityFilter))
            ->when($this->actionFilter, fn ($query) => $query->where('action', $this->actionFilter))
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return [
            'logs' => $logs,
            'entityTypes' => AuditLog::ENTITY_LABELS,
            'actions' => AuditAction::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Denetim Kayıtları</h1>
        <p class="text-[14px] text-ink-muted mt-1">Kritik kayıtlarda kimin, ne zaman, neyi değiştirdiğini görüntüle. Kayıtlar değiştirilemez.</p>
    </div>

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <select wire:model.live="entityFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Kayıt Türleri</option>
            @foreach ($entityTypes as $class => $label)
                <option value="{{ $class }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="actionFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm İşlemler</option>
            @foreach ($actions as $action)
                <option value="{{ $action->value }}">{{ $action->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Tarih</th>
                    <th class="px-5 py-3 font-medium">Personel</th>
                    <th class="px-5 py-3 font-medium">Kayıt</th>
                    <th class="px-5 py-3 font-medium">İşlem</th>
                    <th class="px-5 py-3 font-medium">Değişiklikler</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($logs as $log)
                    <tr wire:key="audit-{{ $log->id }}" class="align-top">
                        <td class="px-5 py-3 text-ink-muted whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $log->actor?->name ?? 'Sistem' }}</td>
                        <td class="px-5 py-3">
                            <span class="text-ink-muted text-[12px]">{{ $log->entityLabel() }}</span>
                            <div>{{ $log->entityName() }}</div>
                        </td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-brand-100 text-brand-600' => $log->action === AuditAction::Created,
                                'bg-line text-ink-muted' => $log->action === AuditAction::Updated,
                                'bg-status-critical-bg text-status-critical' => $log->action === AuditAction::Deleted,
                            ])>{{ $log->action->label() }}</span>
                        </td>
                        <td class="px-5 py-3 text-[13px]">
                            <dl class="space-y-0.5">
                                @foreach ($log->changes() as $field => $change)
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="font-mono text-[12px] text-ink-muted">{{ $field }}</dt>
                                        <dd>
                                            @if ($log->action === AuditAction::Updated)
                                                <span class="text-status-critical line-through">{{ AuditLog::formatValue($change['before']) }}</span>
                                                <span class="text-ink-muted">→</span>
                                                <span class="text-status-good">{{ AuditLog::formatValue($change['after']) }}</span>
                                            @else
                                                {{ AuditLog::formatValue($change['after'] ?? $change['before']) }}
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($logs->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $logs->links() }}
            </div>
        @endif
    </section>
</div>
