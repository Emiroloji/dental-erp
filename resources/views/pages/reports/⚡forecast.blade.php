<?php

use App\Domain\Forecasting\Services\ConsumptionForecastService;
use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Forecasting\Support\ProductForecast;
use App\Http\Livewire\Concerns\WithReportFilters;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stok tüketim tahmini (Faz 4 — Aşama 24). Tarih filtresi yoktur; tahmin her
 * zaman bugünden geriye son haftaların kullanımına dayanır.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $risk = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'risk'], true)) {
            $this->resetPage();
        }
    }

    public function with(ConsumptionForecastService $forecasts): array
    {
        $all = $forecasts->forecast(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds(), $this->search ?: null);

        $filtered = ForecastRisk::tryFrom($this->risk)
            ? $all->filter(fn (ProductForecast $forecast) => $forecast->risk->value === $this->risk)->values()
            : $all;

        $perPage = 20;
        $page = $this->getPage();

        return [
            'rows' => new LengthAwarePaginator($filtered->forPage($page, $perPage)->values(), $filtered->count(), $perPage, $page),
            'counts' => collect(ForecastRisk::cases())->mapWithKeys(fn (ForecastRisk $risk) => [$risk->value => $all->where('risk', $risk)->count()]),
            'risks' => ForecastRisk::cases(),
            'options' => $this->filterOptions(),
            'weeks' => (int) config('forecasting.history_weeks'),
            'horizon' => (int) config('forecasting.horizon_days'),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
        <p class="text-[14px] text-ink-muted mt-1">
            Ürün bazında tahmini tüketim ve stoğun kaç gün yeteceği. Son {{ $weeks }} haftanın kullanım çıkışları (klinik içi kullanım ve sarf; iptaller hariç) haftalık toplanır, seviye ve trend birlikte değerlendirilerek (Holt yöntemi) gelecek haftanın tüketimi hesaplanır. Yalnızca SKT'si geçmemiş stok sayılır.
        </p>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options" :dates="false">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ürün adı veya kodu ara" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
    </x-report-filters>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        @foreach ([ForecastRisk::OutOfStock, ForecastRisk::Critical, ForecastRisk::Soon, ForecastRisk::Sufficient] as $card)
            <button wire:click="$set('risk', '{{ $risk === $card->value ? '' : $card->value }}')" @class(['bg-surface px-5 py-4 text-left hover:bg-canvas transition-colors', 'ring-2 ring-inset ring-brand-500' => $risk === $card->value])>
                <p class="text-[12px] text-ink-muted">{{ $card->label() }}@if ($card === ForecastRisk::Critical) (≤ {{ config('forecasting.critical_days') }} gün)@elseif ($card === ForecastRisk::Soon) (≤ {{ config('forecasting.warning_days') }} gün)@endif</p>
                <p class="text-[18px] font-medium tabular-nums">{{ $counts[$card->value] }}</p>
            </button>
        @endforeach
    </div>

    <div class="mb-3">
        <select wire:model.live="risk" aria-label="Risk" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Risk Durumları</option>
            @foreach ($risks as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium">Son {{ $weeks }} Hafta</th>
                    <th class="px-5 py-3 font-medium text-right">Kullanılabilir Stok</th>
                    <th class="px-5 py-3 font-medium text-right">Günlük Tüketim</th>
                    <th class="px-5 py-3 font-medium text-right">{{ $horizon }} Günlük Beklenen</th>
                    <th class="px-5 py-3 font-medium text-right">Yeter (gün)</th>
                    <th class="px-5 py-3 font-medium">Tahmini Tükenme</th>
                    <th class="px-5 py-3 font-medium">Min. Seviyeye</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $forecast)
                    @php $max = max(1, max($forecast->weeklyUsage)); @endphp
                    <tr wire:key="forecast-{{ $forecast->product->id }}">
                        <td class="px-5 py-3">
                            <div>{{ $forecast->product->name }}</div>
                            <div class="text-[12px] text-ink-muted">{{ $forecast->product->category?->name ?? '—' }} · {{ $forecast->product->base_unit }}</div>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-end gap-[2px] h-6" title="Haftalık kullanım (eskiden yeniye): {{ implode(', ', array_map(fn ($value) => Number::format($value, maxPrecision: 2), $forecast->weeklyUsage)) }}">
                                @foreach ($forecast->weeklyUsage as $value)
                                    <span class="w-[5px] rounded-sm {{ $value > 0 ? 'bg-brand-500' : 'bg-line' }}" style="height: {{ $value > 0 ? max(12, round($value / $max * 100)) : 8 }}%"></span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">
                            {{ Number::format($forecast->usableStock, maxPrecision: 2) }}
                            @if ($forecast->expiredStock > 0)
                                <div class="text-[12px] text-status-critical">+{{ Number::format($forecast->expiredStock, maxPrecision: 2) }} SKT geçmiş</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">
                            {{ Number::format($forecast->dailyRate, maxPrecision: 2) }}
                            @if ($forecast->dailyRate > 0)
                                <span class="text-[12px] text-ink-muted" title="Trend: {{ $forecast->trend->label() }}">{{ $forecast->trend->symbol() }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($forecast->horizonUsage, maxPrecision: 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ $forecast->daysOfCover !== null ? Number::format($forecast->daysOfCover, maxPrecision: 1) : '—' }}</td>
                        <td class="px-5 py-3 text-[13px]">{{ $forecast->usableStock <= 0 ? 'Tükendi' : ($forecast->stockoutDate?->format('d.m.Y') ?? '—') }}</td>
                        <td class="px-5 py-3 text-[13px]">{{ $forecast->minStockDate?->format('d.m.Y') ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $forecast->risk->badgeClasses() }}">{{ $forecast->risk->label() }}</span>
                            @if ($forecast->lowConfidence)
                                <div class="text-[12px] text-ink-muted mt-0.5" title="İlk kullanımdan bu yana {{ config('forecasting.min_history_weeks') }} haftadan az geçti; basit ortalama kullanıldı.">Düşük güven</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrelerle kullanımı veya stoğu olan ürün yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($rows->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
