<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Seri numarası takibi (Aşama 26): bir birimin güncel durumu ve tüm hareket
 * geçmişi (hangi lot, hangi depo, kim, ne zaman, hangi işlemle). Seri numarası
 * yazılabilir veya kutudaki GS1 DataMatrix okutulabilir. Personel yalnızca
 * Stok modülündeki şube kapsamındaki hareketleri görür.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    #[Url(as: 'seri', except: '')]
    public string $search = '';

    /**
     * Okutulan GS1 kodundan seri numarası alınır.
     */
    public function updatedSearch(): void
    {
        if ($gs1 = Gs1::parse($this->search)) {
            $this->search = (string) ($gs1['serial'] ?? '');
        }
    }

    public function with(): array
    {
        $branchIds = auth()->user()->accessibleBranchIds(Module::StockMovement);
        $term = trim($this->search);

        $serials = collect();

        if (mb_strlen($term) >= 2) {
            $serials = StockSerial::query()
                ->whereLike('serial_no', "%{$term}%")
                ->whereHas('product')
                // Kapsam: güncel lotu ya da herhangi bir hareketi kullanıcının şubelerinde.
                ->when($branchIds !== null, fn ($query) => $query->where(fn ($query) => $query
                    ->whereHas('lot', fn ($lot) => $lot->inBranches($branchIds))
                    ->orWhereHas('movements', fn ($movement) => $movement->inBranches($branchIds))))
                ->with(['product', 'lot.warehouse.branch'])
                ->orderBy('serial_no')
                ->limit(20)
                ->get();
        }

        $histories = $serials->mapWithKeys(fn (StockSerial $serial) => [$serial->id => $serial->movements()
            ->inBranches($branchIds)
            ->with(['lot', 'warehouse.branch', 'actor'])
            ->orderBy('stock_movements.created_at')
            ->orderBy('stock_movements.id')
            ->get()]);

        return ['serials' => $serials, 'histories' => $histories];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Seri Takibi</h1>
        <p class="text-[14px] text-ink-muted mt-1">Seri takipli bir birimin (ör. implant) nerede olduğunu ve geçmişini görün. Seri numarasını yazın veya kutudaki kare kodu okutun.</p>
    </div>

    <input type="text" wire:model.live.debounce.300ms="search" autofocus placeholder="Seri numarası veya GS1 kodu" class="mb-6 w-full sm:w-96 border border-line rounded-md px-3 py-2.5 text-[15px] font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">

    @if (mb_strlen(trim($search)) >= 2 && $serials->isEmpty())
        <p class="text-[14px] text-ink-muted">"{{ $search }}" ile eşleşen seri numarası bulunamadı.</p>
    @endif

    @foreach ($serials as $serial)
        <section class="mb-6 border border-line rounded-lg bg-surface" wire:key="serial-{{ $serial->id }}">
            <div class="px-5 py-3 border-b border-line flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="font-mono text-[15px] text-ink">{{ $serial->serial_no }}</span>
                    <span class="text-[13px] text-ink-muted">· {{ $serial->product->name }}</span>
                </div>
                <div class="flex items-center gap-2 text-[13px]">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $serial->status->badgeClasses() }}">{{ $serial->status->label() }}</span>
                    <span class="text-ink-muted">Lot {{ $serial->lot->lot_no ?? '—' }} · SKT {{ $serial->lot->expiry_date?->format('d.m.Y') ?? '—' }} · {{ $serial->lot->warehouse->branch->name }} {{ $serial->lot->warehouse->name }}</span>
                </div>
            </div>
            <ol class="px-5 py-4 border-l border-line ml-6 space-y-3">
                @forelse ($histories[$serial->id] as $movement)
                    <li class="pl-4 relative">
                        <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full {{ (float) $movement->quantity < 0 ? 'bg-status-critical' : 'bg-brand-500' }}"></span>
                        <div class="text-[13px]"><span class="font-medium">{{ $movement->type->label() }}</span> · {{ $movement->warehouse->branch->name }} {{ $movement->warehouse->name }} · Lot {{ $movement->lot->lot_no ?? '—' }}</div>
                        <div class="text-[12px] text-ink-muted">{{ $movement->created_at->format('d.m.Y H:i') }} · {{ $movement->actor?->name ?? 'Sistem' }}@if ($movement->reason) — {{ $movement->reason }}@endif</div>
                    </li>
                @empty
                    <li class="pl-4 text-[13px] text-ink-muted">Bu seriye ait, erişiminiz dahilinde hareket yok.</li>
                @endforelse
            </ol>
        </section>
    @endforeach
</div>
