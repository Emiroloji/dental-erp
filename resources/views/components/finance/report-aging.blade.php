@props(['report', 'labels'])
@php
    use App\Domain\Finance\Support\Money;
@endphp
<div>
    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Cari</th>
                    @foreach ($labels as $label)
                        <th class="hidden md:table-cell px-4 py-3 font-medium text-right whitespace-nowrap">{{ $label }}</th>
                    @endforeach
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Toplam</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($report['rows'] as $row)
                    <tr wire:key="ag-{{ $row['party_id'] ?? 0 }}">
                        <td class="px-3 sm:px-5 py-2.5">
                            @if ($row['party_id'])
                                <a href="{{ route('finance.parties.show', $row['party_id']) }}" wire:navigate class="text-ink hover:underline">{{ $row['party'] }}</a>
                            @else
                                <span class="text-ink-muted">{{ $row['party'] }}</span>
                            @endif
                        </td>
                        @foreach ($labels as $bucket => $label)
                            <td @class(['hidden md:table-cell px-4 py-2.5 text-right whitespace-nowrap tabular-nums', 'text-ink-muted' => $row['buckets'][$bucket] === 0, 'text-finance-out' => $row['buckets'][$bucket] > 0 && $bucket === 'd90_plus'])>{{ $row['buckets'][$bucket] === 0 ? '—' : Money::format($row['buckets'][$bucket]) }}</td>
                        @endforeach
                        <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums font-medium">{{ Money::format($row['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($labels) + 2 }}" class="px-5 py-8 text-center text-ink-muted text-[13px]">Açık kalem yok.</td></tr>
                @endforelse
            </tbody>
            @if ($report['rows'] !== [])
                <tfoot>
                    <tr class="border-t border-line text-[13px]">
                        <td class="px-3 sm:px-5 py-3 font-medium">Toplam</td>
                        @foreach ($labels as $bucket => $label)
                            <td class="hidden md:table-cell px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ Money::format($report['totals'][$bucket]) }}</td>
                        @endforeach
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium">{{ Money::format($report['total']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </section>
</div>
