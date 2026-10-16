<?php

use App\Domain\Access\Support\Module;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\Support\CountDifferenceReason;
use App\Domain\Inventory\Support\StockCountPermissions;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Reporting\Services\ReportDownloader;
use App\Domain\Reporting\Services\StockCountReportService;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Number;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    public int $countId;

    /** @var array<int, array{counted_quantity: string, reason: string, note: string}> satır id => giriş (yalnızca görünen sayfa) */
    public array $entries = [];

    public string $actionNote = '';

    /** Aşama 40: barkod okutarak sayma. */
    public string $scanCode = '';

    public string $scanError = '';

    /** @var array{line_id: int, label: string, counted: string, serial: ?string}|null son okutma (geri almak için) */
    public ?array $lastScan = null;

    public function mount(int $count): void
    {
        $this->countId = $this->findCount($count)->id;
        $this->loadEntries();
    }

    /**
     * Sayfa değişmeden önce görünen sayfadaki girişler kaydedilir; sayım
     * formu sayfalı olsa da hiçbir giriş kaybolmaz.
     */
    public function updatingPaginators(): void
    {
        $this->persistEntries();
    }

    public function updatedPaginators(): void
    {
        $this->loadEntries();
    }

    public function save(StockCountService $counts): void
    {
        if ($this->persistEntries($counts)) {
            session()->flash('status', 'Sayım girişleri kaydedildi.');
        }
    }

    public function submit(StockCountService $counts): void
    {
        if (! $this->persistEntries($counts)) {
            return;
        }

        $this->attempt(fn () => $counts->submit($this->count(), auth()->user()), 'Sayım onaya gönderildi.');
    }

    /**
     * Okutulan kod ilgili satırı bir artırır. Önce görünen sayfadaki elle
     * girişler kaydedilir; okutma onları ezmez.
     */
    public function scan(StockCountService $counts): void
    {
        $code = trim($this->scanCode);
        $this->reset(['scanCode', 'scanError']);

        if ($code === '' || ! $this->persistEntries($counts)) {
            return;
        }

        try {
            ['line' => $line, 'counted' => $counted] = $counts->scan($this->count(), $code, auth()->user());
        } catch (StockCountException $e) {
            $this->scanError = $e->getMessage();
            $this->dispatch('count-scanned');

            return;
        }

        $line->loadMissing('product', 'lot');
        $serials = $line->counted_serials ?? [];
        $this->lastScan = [
            'line_id' => $line->id,
            'label' => $line->product->name.' · '.($line->lot->lot_no ?? 'Lot #'.$line->lot->id),
            'counted' => Number::format($counted, maxPrecision: 2),
            'serial' => $line->product->tracks_serials ? end($serials) : null,
        ];
        $this->loadEntries();
        $this->dispatch('count-scanned');
    }

    public function undoScan(StockCountService $counts): void
    {
        if (! $this->lastScan) {
            return;
        }

        $this->persistEntries($counts);

        if ($this->attempt(fn () => $counts->undoScan($this->count(), $this->lastScan['line_id'], $this->lastScan['serial'], auth()->user()), null)) {
            $this->scanError = '';
            $this->lastScan = null;
            session()->flash('status', 'Son okutma geri alındı.');
        }

        $this->loadEntries();
        $this->dispatch('count-scanned');
    }

    public function refreshSystem(StockCountService $counts): void
    {
        $this->persistEntries($counts);
        $this->attempt(fn () => $counts->refreshSystemQuantities($this->count(), auth()->user()), 'Sistem miktarları güncellendi; farkları kontrol edin.');
        $this->loadEntries();
    }

    public function approve(StockCountService $counts): void
    {
        $this->attempt(fn () => $counts->approve($this->count(), auth()->user()), 'Sayım onaylandı; farklar düzeltme hareketi olarak stoğa işlendi.');
    }

    public function sendBack(StockCountService $counts): void
    {
        $this->attempt(fn () => $counts->sendBack($this->count(), auth()->user(), $this->actionNote ?: null), 'Sayım yeniden sayım için geri gönderildi.');
        $this->actionNote = '';
    }

    public function cancel(StockCountService $counts): void
    {
        $this->attempt(fn () => $counts->cancel($this->count(), auth()->user(), $this->actionNote ?: null), 'Sayım iptal edildi.');
        $this->actionNote = '';
    }

    public function exportExcel(StockCountReportService $report, ReportDownloader $downloader)
    {
        $count = $this->count();

        return $downloader->excel($count->number(), 'sayim-'.$count->number(), $report->table($count));
    }

    public function exportPdf(StockCountReportService $report, ReportDownloader $downloader)
    {
        $count = $this->count();

        return $downloader->pdf($report->title($count), 'sayim-'.$count->number(), $report->table($count), new ReportFilters);
    }

    private function persistEntries(?StockCountService $counts = null): bool
    {
        $count = $this->count();

        if ($count->status !== StockCountStatus::Counting || ! app(StockCountPermissions::class)->canCount(auth()->user(), $count->warehouse)) {
            return true;
        }

        $this->validate([
            'entries.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'entries.*.reason' => ['nullable', 'in:'.implode(',', array_column(CountDifferenceReason::cases(), 'value'))],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
            'entries.*.counted_serials' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->attempt(fn () => ($counts ?? app(StockCountService::class))->record($count, $this->entries, auth()->user()), null);
    }

    private function loadEntries(): void
    {
        $this->entries = $this->pageLines()->getCollection()->mapWithKeys(fn ($line) => [$line->id => [
            'counted_quantity' => $line->counted_quantity === null ? '' : rtrim(rtrim((string) $line->counted_quantity, '0'), '.'),
            'reason' => $line->reason?->value ?? '',
            'note' => (string) $line->note,
            // Seri takipli lot (Aşama 26): bulunan seriler, her satıra bir seri.
            ...($line->product->tracks_serials ? ['counted_serials' => implode("\n", $line->counted_serials ?? [])] : []),
        ]])->all();
    }

    private function attempt(Closure $action, ?string $success): bool
    {
        try {
            $action();
        } catch (StockCountException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return false;
        }

        if ($success) {
            session()->flash('status', $success);
        }

        return true;
    }

    private function count(): StockCount
    {
        return $this->findCount($this->countId);
    }

    private function findCount(int $countId): StockCount
    {
        try {
            $count = StockCount::with('warehouse.branch')->findOrFail($countId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        abort_unless(app(StockCountPermissions::class)->canView(auth()->user(), $count), 404);

        return $count;
    }

    private function pageLines()
    {
        return $this->count()->lines()
            ->with(['product', 'lot', 'movement'])
            ->join('products', 'stock_count_lines.product_id', '=', 'products.id')
            ->orderBy('products.name')
            ->orderBy('stock_count_lines.id')
            ->select('stock_count_lines.*')
            ->paginate(self::PER_PAGE);
    }

    public function with(StockCountPermissions $permissions): array
    {
        $count = $this->count()->load(['events.actor', 'starter']);
        $allLines = $count->lines()->get();

        return [
            'count' => $count,
            'lines' => $this->pageLines(),
            'reasons' => CountDifferenceReason::cases(),
            'canCount' => $count->status === StockCountStatus::Counting && $permissions->canCount(auth()->user(), $count->warehouse),
            'canApprove' => $count->status === StockCountStatus::PendingApproval && $permissions->canApprove(auth()->user()),
            'canCancel' => $count->status->canTransitionTo(StockCountStatus::Cancelled) && $permissions->canCount(auth()->user(), $count->warehouse),
            'summary' => [
                'total' => $allLines->count(),
                'counted' => $allLines->filter->isCounted()->count(),
                'differences' => $allLines->filter->hasDifference()->count(),
                'net' => (float) $allLines->filter->isCounted()->sum(fn ($line) => $line->difference()),
            ],
        ];
    }
};
?>

@script
<script>
    // Aşama 40: kamera sayım boyunca açık kalır; aynı kod 1,5 sn içinde tekrar okunursa yok sayılır.
    Alpine.data('countScan', () => ({
        cameraOpen: false,
        cameraError: '',
        controls: null,
        last: { text: '', at: 0 },
        focusInput() {
            if (! this.cameraOpen) {
                this.$nextTick(() => this.$refs.scanInput?.focus());
            }
        },
        async openCamera() {
            this.cameraError = '';
            this.cameraOpen = true;
            try {
                this.controls = await window.startBarcodeScanner(this.$refs.video, (text) => {
                    const now = Date.now();
                    if (text === this.last.text && now - this.last.at < 1500) {
                        return;
                    }
                    this.last = { text, at: now };
                    $wire.set('scanCode', text, false);
                    $wire.scan();
                });
            } catch (error) {
                this.cameraError = 'Kamera açılamadı: ' + (error?.message ?? error) + ' (Kamera yalnızca HTTPS veya localhost üzerinde çalışır.)';
            }
        },
        closeCamera() {
            this.controls?.stop();
            this.controls = null;
            this.cameraOpen = false;
            this.focusInput();
        },
    }));
</script>
@endscript

<div>
    <div class="mb-6">
        <a href="{{ route('inventory.index') }}" class="text-[13px] text-ink-muted hover:text-ink">← Stok Sayımı</a>
        <div class="mt-2 sm:flex sm:items-end sm:justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-medium tracking-tight text-ink flex items-center gap-3">
                    {{ $count->number() }}
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] font-normal {{ $count->status->badgeClasses() }}">{{ $count->status->label() }}</span>
                </h1>
                <p class="text-[14px] text-ink-muted mt-1">{{ $count->warehouse->branch->name }} · {{ $count->warehouse->name }} — {{ $count->starter?->name ?? '—' }}, {{ $count->created_at->format('d.m.Y H:i') }}@if ($count->note) · {{ $count->note }}@endif</p>
            </div>
            <div class="flex gap-2 shrink-0 mt-4 sm:mt-0">
                <button wire:click="exportExcel" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
                <button wire:click="exportPdf" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Satır</p><p class="text-[20px] font-medium tabular-nums">{{ $summary['total'] }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Sayılan</p><p class="text-[20px] font-medium tabular-nums">{{ $summary['counted'] }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Farklı Satır</p><p class="text-[20px] font-medium tabular-nums {{ $summary['differences'] ? 'text-status-warn' : '' }}">{{ $summary['differences'] }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Net Fark</p><p class="text-[20px] font-medium tabular-nums {{ $summary['net'] < 0 ? 'text-status-critical' : ($summary['net'] > 0 ? 'text-status-good' : '') }}">{{ $summary['net'] > 0 ? '+' : '' }}{{ Number::format($summary['net'], precision: 2) }}</p></div>
    </div>

    @if ($canCount)
        <section x-data="countScan" x-on:count-scanned.window="focusInput()" class="mb-6 border border-line rounded-lg bg-surface p-4">
            <h2 class="text-[14px] font-medium text-ink">Barkod okutarak say</h2>
            <p class="text-[13px] text-ink-muted mt-0.5">Her okutma ilgili satırın sayılan miktarını 1 artırır. Ürünün birden çok lotu varsa lot etiketini okutun.</p>
            <form wire:submit="scan" class="mt-3 flex gap-2">
                <input x-ref="scanInput" type="text" wire:model="scanCode" autocomplete="off" inputmode="text" placeholder="Barkodu okutun veya yazın"
                    class="flex-1 min-w-0 border border-line rounded-md px-3 py-2.5 text-[15px] font-mono bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                <button type="submit" wire:loading.attr="disabled" wire:target="scan" class="border border-line rounded-md px-4 text-[14px] bg-surface hover:bg-canvas">Say</button>
                <button type="button" x-on:click="cameraOpen ? closeCamera() : openCamera()" class="bg-panel-900 text-white rounded-md px-3 text-[14px]" title="Kamerayla okut">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 12h10"/></svg>
                </button>
            </form>

            <div x-show="cameraOpen" x-cloak class="mt-3 rounded-lg overflow-hidden border border-line bg-black relative">
                <video x-ref="video" class="w-full max-h-[40vh] object-cover" playsinline muted></video>
                <button type="button" x-on:click="closeCamera()" class="absolute top-2 right-2 bg-white/90 text-ink rounded-md px-3 py-1.5 text-[13px]">Kapat</button>
                <p x-show="cameraError" x-text="cameraError" class="absolute bottom-0 inset-x-0 bg-status-critical text-white text-[13px] px-3 py-2"></p>
            </div>

            @if ($scanError)
                <p class="mt-3 text-[13px] text-status-critical">{{ $scanError }}</p>
            @elseif ($lastScan)
                <div class="mt-3 flex items-center justify-between gap-3 rounded-md bg-brand-100 border border-brand-500/20 px-3 py-2">
                    <p class="text-[14px] text-brand-600 min-w-0 truncate">{{ $lastScan['label'] }}: {{ $lastScan['counted'] }} sayıldı</p>
                    <button type="button" wire:click="undoScan" wire:loading.attr="disabled" class="text-[13px] text-ink-muted hover:text-ink shrink-0">Geri al</button>
                </div>
            @endif
        </section>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-4 py-3 font-medium">Ürün</th>
                    <th class="px-4 py-3 font-medium">Lot / SKT</th>
                    <th class="px-4 py-3 font-medium text-right">Sistem</th>
                    <th class="px-4 py-3 font-medium w-28">Sayılan</th>
                    <th class="px-4 py-3 font-medium text-right">Fark</th>
                    <th class="px-4 py-3 font-medium">Neden</th>
                    <th class="px-4 py-3 font-medium">Açıklama</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($lines as $line)
                    @php
                        $entry = $entries[$line->id] ?? null;
                        $counted = $canCount ? ($entry['counted_quantity'] ?? '') : $line->counted_quantity;
                        $difference = ($counted === '' || $counted === null) ? null : (float) $counted - (float) $line->system_quantity;
                    @endphp
                    <tr wire:key="line-{{ $line->id }}" class="align-top">
                        <td class="px-4 py-2.5">{{ $line->product->name }}</td>
                        <td class="px-4 py-2.5 text-[13px]">
                            <span class="font-mono">{{ $line->lot->lot_no ?? '—' }}</span>
                            <div class="text-[12px] text-ink-muted">{{ $line->lot->expiry_date?->format('d.m.Y') ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-ink-muted">{{ Number::format((float) $line->system_quantity, precision: 2) }}</td>
                        <td class="px-4 py-2.5">
                            @if ($canCount && $line->product->tracks_serials)
                                @php
                                    try {
                                        $serialCount = count(\App\Domain\Stock\Services\SerialRegistry::parseList($entry['counted_serials'] ?? ''));
                                    } catch (\App\Domain\Stock\Exceptions\SerialException) {
                                        $serialCount = null;
                                    }
                                    $counted = blank($entry['counted_serials'] ?? '') && ($entry['counted_quantity'] ?? '') === '' ? '' : $serialCount;
                                    $difference = $counted === '' || $counted === null ? null : (float) $counted - (float) $line->system_quantity;
                                @endphp
                                <textarea wire:model.live.debounce.400ms="entries.{{ $line->id }}.counted_serials" rows="2" placeholder="Bulunan seriler" class="w-40 border border-line rounded-md px-2 py-1.5 text-[12px] font-mono"></textarea>
                                <div class="text-[12px] text-ink-muted">{{ $serialCount === null ? 'Tekrar eden seri' : "{$serialCount} seri" }}</div>
                            @elseif ($canCount)
                                <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="entries.{{ $line->id }}.counted_quantity" class="w-full border border-line rounded-md px-2 py-1.5 tabular-nums">
                                @error("entries.{$line->id}.counted_quantity") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            @else
                                <span class="tabular-nums">{{ $line->counted_quantity === null ? '—' : Number::format((float) $line->counted_quantity, precision: 2) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $difference === null ? 'text-ink-muted' : ($difference < 0 ? 'text-status-critical' : ($difference > 0 ? 'text-status-good' : 'text-ink-muted')) }}">
                            {{ $difference === null ? '—' : ($difference > 0 ? '+' : '').Number::format($difference, precision: 2) }}
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($canCount)
                                <select wire:model="entries.{{ $line->id }}.reason" class="w-full border border-line rounded-md px-2 py-1.5 text-[13px]">
                                    <option value="">—</option>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="text-[13px]">{{ $line->reason?->label() ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($canCount)
                                <input type="text" wire:model="entries.{{ $line->id }}.note" class="w-full border border-line rounded-md px-2 py-1.5 text-[13px]">
                            @else
                                <span class="text-[13px] text-ink-muted">{{ $line->note ?? '—' }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($lines->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $lines->links() }}</div>
        @endif
    </section>

    @if ($canCount || $canApprove || $canCancel)
        <div class="mt-6 flex flex-wrap items-center gap-3">
            @if ($canCount)
                <button wire:click="submit" wire:confirm="Sayım onaya gönderilsin mi? Gönderdikten sonra girişler değiştirilemez." class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onaya Gönder</button>
                <button wire:click="save" class="border border-line rounded-md px-4 py-2.5 text-[14px] hover:bg-canvas transition-colors">Kaydet</button>
                <button wire:click="refreshSystem" wire:confirm="Sistem miktarları depodaki güncel miktarlarla değiştirilecek; sayılan miktarlar korunur. Devam edilsin mi?" class="text-[14px] text-ink-muted hover:text-ink hover:underline">Sistem Miktarlarını Yenile</button>
            @endif
            @if ($canApprove)
                <button wire:click="approve" wire:confirm="Onaylanan farklar düzeltme hareketi olarak stoğa işlenecek. Onaylıyor musun?" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onayla</button>
            @endif
            @if ($canApprove || $canCancel)
                <input type="text" wire:model="actionNote" placeholder="Gerekçe (geri gönderme / iptal)" class="border border-line rounded-md px-3 py-2 text-[14px] w-64">
            @endif
            @if ($canApprove)
                <button wire:click="sendBack" class="text-[14px] text-brand-600 hover:underline">Yeniden Sayıma Gönder</button>
            @endif
            @if ($canCancel)
                <button wire:click="cancel" wire:confirm="Sayım iptal edilsin mi? Stok değişmez." class="text-[14px] text-status-critical hover:underline">Sayımı İptal Et</button>
            @endif
        </div>
    @endif

    <section class="mt-10">
        <h2 class="text-[15px] font-medium text-ink mb-3">Durum Geçmişi</h2>
        <ol class="border-l border-line ml-1 space-y-3">
            @foreach ($count->events->sortBy('id') as $event)
                <li class="pl-4 relative">
                    <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                    <div class="text-[13px]"><span class="font-medium">{{ $event->status->label() }}</span> · {{ $event->actor?->name ?? 'Sistem' }}</div>
                    <div class="text-[12px] text-ink-muted">{{ $event->created_at->format('d.m.Y H:i') }}@if ($event->note) — {{ $event->note }}@endif</div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
