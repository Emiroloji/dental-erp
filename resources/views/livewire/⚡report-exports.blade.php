<?php

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Support\ReportExportStatus;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Kullanıcının son rapor dışa aktarımları (kuyrukta hazırlanır). Hazırlanan
 * bir dosya varken liste birkaç saniyede bir yenilenir.
 */
new class extends Component
{
    #[On('report-export-queued')]
    public function refreshList(): void
    {
        //
    }

    public function with(): array
    {
        $exports = ReportExport::where('user_id', auth()->id())
            ->where('created_at', '>=', now()->subDays(ReportExport::RETENTION_DAYS))
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'exports' => $exports,
            'hasPending' => $exports->contains('status', ReportExportStatus::Pending),
        ];
    }
};
?>

<div @if ($hasPending) wire:poll.5s @endif>
    @if ($exports->isNotEmpty())
        <section class="mb-6 border border-line rounded-lg bg-surface">
            <h2 class="px-5 pt-3 pb-2 text-[13px] font-medium text-ink">Dışa Aktarımlar</h2>
            <ul class="divide-y divide-line text-[13px]">
                @foreach ($exports as $export)
                    <li class="px-5 py-2 flex flex-wrap items-center justify-between gap-2">
                        <span>
                            {{ $export->report->label() }} · {{ $export->format === 'pdf' ? 'PDF' : 'Excel' }}
                            <span class="text-ink-muted">· {{ $export->created_at->format('d.m.Y H:i') }}</span>
                        </span>
                        <span class="flex items-center gap-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $export->status->badgeClasses() }}">{{ $export->status->label() }}</span>
                            @if ($export->status === ReportExportStatus::Completed)
                                <a href="{{ route('reports.exports.download', $export) }}" class="text-brand-600 hover:underline">İndir</a>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
