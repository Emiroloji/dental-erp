<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanLimitException;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Exceptions\PurchasingException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Purchasing\Support\PurchasingPermissions;
use App\Domain\Stock\Exceptions\ColdChainException;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithFileUploads, WithPagination;

    public string $statusFilter = '';

    // Talep formu
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $supplier_id = '';

    public string $warehouse_id = '';

    public string $expected_delivery_date = '';

    public string $note = '';

    /** @var array<int, array{product_id: string, quantity: string, unit_price: string}> */
    public array $lines = [];

    // Teslim alma formu
    public ?int $receivingId = null;

    public string $invoice_number = '';

    public string $delivery_note_number = '';

    public $document = null;

    /** @var array<int, array{quantity: string, lot_no: string, expiry_date: string, unit_cost: string}> */
    public array $receiptLines = [];

    // Gerekçe (red / iptal / kalanı kapat)
    public ?int $noteOrderId = null;

    public string $noteAction = '';

    public string $actionNote = '';

    /**
     * Detay sayfasındaki "Teslim Al" düğmesi listeye ?teslim={id} ile gelir;
     * teslim alma penceresi doğrudan açılır.
     */
    public function mount(): void
    {
        if ($orderId = request()->integer('teslim')) {
            $this->openReceipt($orderId);
        }
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        Gate::authorize('purchasing.create');

        $this->resetForm();
        $this->warehouse_id = (string) ($this->warehouses()->first()?->id ?? '');
        $this->lines = [$this->emptyLine()];
        $this->showForm = true;
    }

    public function edit(int $orderId): void
    {
        Gate::authorize('purchasing.create');

        $order = $this->findOrder($orderId);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            session()->flash('error', 'Yalnızca taslak halindeki talepler düzenlenebilir.');

            return;
        }

        $this->resetForm();
        $this->editingId = $order->id;
        $this->supplier_id = (string) $order->supplier_id;
        $this->warehouse_id = (string) $order->warehouse_id;
        $this->expected_delivery_date = (string) $order->expected_delivery_date?->toDateString();
        $this->note = (string) $order->note;
        $this->lines = $order->lines->map(fn ($line) => [
            'product_id' => (string) $line->product_id,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
        ])->all();
        $this->showForm = true;
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Seçilen ürünün kartındaki alış fiyatı, birim fiyat boşsa öneri olarak gelir.
     */
    public function updatedLines(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field === 'product_id' && blank($this->lines[$index]['unit_price'] ?? null) && filled($value)) {
            $this->lines[$index]['unit_price'] = (string) (Product::find($value)?->purchase_price ?? '');
        }
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(PurchaseOrderService $orders, bool $submit = false): void
    {
        Gate::authorize('purchasing.create');

        $organizationId = auth()->user()->organization_id;

        $validated = $this->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)->where('status', 'active')],
            'warehouse_id' => ['required', Rule::in($this->warehouses()->modelKeys())],
            'expected_delivery_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'distinct', Rule::exists('products', 'id')->where('organization_id', $organizationId)->where('status', 'active')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'warehouse_id.in' => 'Yalnızca kendi kapsamındaki işleme açık bir depo için talep açabilirsin.',
            'lines.required' => 'Talepte en az bir ürün kalemi olmalı.',
            'lines.min' => 'Talepte en az bir ürün kalemi olmalı.',
            'lines.*.product_id.distinct' => 'Aynı ürün iki kez eklenemez; miktarı tek kalemde artır.',
        ]);

        $lines = array_map(fn (array $line) => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (float) $line['quantity'],
            'unit_price' => filled($line['unit_price']) ? (float) $line['unit_price'] : 0,
        ], $validated['lines']);

        $supplier = Supplier::findOrFail($validated['supplier_id']);
        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);
        $expected = $validated['expected_delivery_date'] ?: null;
        $note = $validated['note'] ?: null;

        $done = $this->attempt(function () use ($orders, $supplier, $warehouse, $lines, $expected, $note, $submit) {
            $order = $this->editingId
                ? $orders->updateDraft($this->findOrder($this->editingId), $supplier, $warehouse, $lines, $expected, $note, auth()->user())
                : $orders->create($supplier, $warehouse, $lines, $expected, $note, auth()->user());

            if ($submit) {
                $orders->submit($order, auth()->user());
            }
        }, $submit ? 'Talep onaya gönderildi.' : 'Talep taslak olarak kaydedildi.');

        if ($done) {
            $this->closeForm();
        }
    }

    public function submit(int $orderId, PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->submit($this->findOrder($orderId), auth()->user()), 'Talep onaya gönderildi.');
    }

    public function approve(int $orderId, PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->approve($this->findOrder($orderId), auth()->user()), 'Talep onaylandı.');
    }

    public function markOrdered(int $orderId, PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->markOrdered($this->findOrder($orderId), auth()->user()), 'Sipariş tedarikçiye verildi olarak işaretlendi.');
    }

    public function askNote(int $orderId, string $action): void
    {
        $this->findOrder($orderId);

        $this->noteOrderId = $orderId;
        $this->noteAction = in_array($action, ['reject', 'cancel', 'close'], true) ? $action : '';
        $this->actionNote = '';
    }

    public function confirmNote(PurchaseOrderService $orders): void
    {
        $this->validate(['actionNote' => ['nullable', 'string', 'max:255']]);

        $order = $this->findOrder((int) $this->noteOrderId);
        $note = $this->actionNote ?: null;

        match ($this->noteAction) {
            'reject' => $this->attempt(fn () => $orders->reject($order, auth()->user(), $note), 'Talep reddedildi.'),
            'cancel' => $this->attempt(fn () => $orders->cancel($order, auth()->user(), $note), 'Sipariş iptal edildi.'),
            'close' => $this->attempt(fn () => $orders->closeRemaining($order, auth()->user(), $note), 'Kalan miktar kapatıldı; sipariş tamamlandı.'),
            default => null,
        };

        $this->closeNote();
    }

    public function closeNote(): void
    {
        $this->reset(['noteOrderId', 'noteAction', 'actionNote']);
    }

    public function openReceipt(int $orderId): void
    {
        $order = $this->findOrder($orderId);

        $this->reset(['invoice_number', 'delivery_note_number', 'document', 'receiptLines']);
        $this->resetValidation();

        foreach ($order->lines as $line) {
            if ($line->remaining() > 0) {
                $this->receiptLines[$line->id] = [
                    'quantity' => rtrim(rtrim(number_format($line->remaining(), 2, '.', ''), '0'), '.'),
                    'lot_no' => '',
                    'expiry_date' => '',
                    'unit_cost' => (string) $line->unit_price,
                    'temperature' => '',
                    'temperature_note' => '',
                ];
            }
        }

        $this->receivingId = $order->id;
    }

    public function closeReceipt(): void
    {
        $this->receivingId = null;
        $this->reset(['invoice_number', 'delivery_note_number', 'document', 'receiptLines']);
        $this->resetValidation();
    }

    public function receive(PurchaseOrderService $orders): void
    {
        $order = $this->findOrder((int) $this->receivingId);

        $this->validate([
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'delivery_note_number' => ['nullable', 'string', 'max:100'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'receiptLines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'receiptLines.*.lot_no' => ['nullable', 'string', 'max:255'],
            'receiptLines.*.expiry_date' => ['nullable', 'date'],
            'receiptLines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'receiptLines.*.temperature' => ['nullable', 'numeric', 'between:-100,100'],
            'receiptLines.*.temperature_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Satır bazında kalan kontrolü: hata ilgili satırın altında görünsün.
        foreach ($order->lines as $line) {
            $quantity = (float) ($this->receiptLines[$line->id]['quantity'] ?? 0);
            if ($quantity > $line->remaining() + 0.0001) {
                $this->addError("receiptLines.{$line->id}.quantity", 'Kalan sipariş miktarından ('.Number::format($line->remaining(), precision: 2).') fazlası teslim alınamaz.');
            }

            // Soğuk zincir (Aşama 26): ölçülen sıcaklık zorunlu, aralık dışıysa gerekçe gerekir.
            if ($quantity > 0 && $line->product->cold_chain) {
                $temperature = $this->receiptLines[$line->id]['temperature'] ?? '';

                if (! is_numeric($temperature)) {
                    $this->addError("receiptLines.{$line->id}.temperature", 'Soğuk zincir ürünü: ölçülen sıcaklığı girin.');
                } elseif (! $line->product->temperatureInRange((float) $temperature) && blank($this->receiptLines[$line->id]['temperature_note'] ?? '')) {
                    $this->addError("receiptLines.{$line->id}.temperature_note", "Sıcaklık saklama aralığı ({$line->product->storageRangeLabel()}) dışında; kabul edilecekse gerekçe yazın.");
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $document = [
            'invoice_number' => $this->invoice_number ?: null,
            'delivery_note_number' => $this->delivery_note_number ?: null,
        ];

        if ($this->document) {
            // Paket depolama limiti (Faz 3): belge diske yazılmadan önce kontrol edilir.
            try {
                app(PlanLimitService::class)->ensureCanStore(Organization::findOrFail(auth()->user()->organization_id), $this->document->getSize());
            } catch (PlanLimitException $e) {
                $this->addError('document', $e->getMessage());

                return;
            }

            $document['document_size'] = $this->document->getSize();
            $document['document_name'] = $this->document->getClientOriginalName();
            $document['document_path'] = $this->document->store('purchase-documents/'.auth()->user()->organization_id, 'local');
        }

        $done = $this->attempt(
            fn () => $orders->receive($order, $this->receiptLines, $document, auth()->user()),
            'Teslim alındı; ürünler stoğa işlendi.',
        );

        if ($done) {
            $this->closeReceipt();
        }
    }

    private function attempt(Closure $action, string $success): bool
    {
        try {
            $action();
        } catch (PurchasingException|InactiveLocationException|ColdChainException $e) {
            session()->flash('error', $e->getMessage());

            return false;
        }

        session()->flash('status', $success);

        return true;
    }

    private function findOrder(int $orderId): PurchaseOrder
    {
        try {
            return $this->visibleOrders()->findOrFail($orderId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function visibleOrders()
    {
        return PurchaseOrder::query()
            ->inBranches(auth()->user()->accessibleBranchIds(Module::Purchasing))
            ->with(['supplier', 'warehouse.branch', 'lines.product', 'requester']);
    }

    private function warehouses()
    {
        return Warehouse::operational()
            ->inBranches(auth()->user()->accessibleBranchIds(Module::Purchasing))
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{product_id: string, quantity: string, unit_price: string}
     */
    private function emptyLine(): array
    {
        return ['product_id' => '', 'quantity' => '', 'unit_price' => ''];
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'supplier_id', 'warehouse_id', 'expected_delivery_date', 'note', 'lines']);
        $this->resetValidation();
    }

    public function with(PurchasingPermissions $permissions): array
    {
        $formOpen = $this->showForm;

        return [
            'orders' => $this->visibleOrders()
                ->when($this->statusFilter === 'open', fn ($query) => $query->whereIn('status', PurchaseOrderStatus::openStatuses()))
                ->when($this->statusFilter && $this->statusFilter !== 'open', fn ($query) => $query->where('status', $this->statusFilter))
                ->latest()
                ->orderByDesc('id')
                ->paginate(15),
            'statuses' => PurchaseOrderStatus::cases(),
            'permissions' => $permissions,
            'suppliers' => $formOpen ? Supplier::where('status', 'active')->orderBy('name')->get() : collect(),
            'products' => $formOpen ? Product::where('status', 'active')->orderBy('name')->get() : collect(),
            'warehouses' => $formOpen ? $this->warehouses() : collect(),
            'receiving' => $this->receivingId ? $this->visibleOrders()->find($this->receivingId) : null,
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Satın Alma</h1>
            <p class="text-[14px] text-ink-muted mt-1">Talep → Admin onayı → tedarikçiye sipariş → kısmi/tam teslim alma. Teslim alınan ürünler lot/SKT ile stoğa işlenir.</p>
        </div>
        @can('purchasing.create')
            <button wire:click="create" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Talep
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Durumlar</option>
            <option value="open">Açık Siparişler (teslimat bekleyen)</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">No</th>
                    <th class="px-5 py-3 font-medium">Tedarikçi</th>
                    <th class="px-5 py-3 font-medium">Teslim Deposu</th>
                    <th class="px-5 py-3 font-medium text-right">Tutar</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($orders as $order)
                    @php
                        $user = auth()->user();
                        $status = $order->status;
                        $canManage = $permissions->canManage($user, $order->warehouse);
                        $canApprove = $permissions->canApprove($user);
                        $remaining = $order->lines->sum(fn ($line) => $line->remaining());
                    @endphp
                    <tr wire:key="order-{{ $order->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('purchasing.show', $order) }}" wire:navigate class="font-mono text-[13px] text-brand-600 hover:underline">{{ $order->number() }}</a>
                            <div class="text-[12px] text-ink-muted">{{ $order->created_at->format('d.m.Y') }} · {{ $order->lines->count() }} kalem</div>
                        </td>
                        <td class="px-5 py-3">{{ $order->supplier->name }}</td>
                        <td class="px-5 py-3 text-[13px] text-ink-muted">{{ $order->warehouse->branch->name }} · {{ $order->warehouse->name }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($order->total(), precision: 2) }} ₺</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $status->badgeClasses() }}">{{ $status->label() }}</span>
                            @if ($status->isOpen())
                                <div class="text-[12px] text-ink-muted mt-0.5">{{ Number::format($remaining, precision: 2) }} kalan</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-3 text-[13px]">
                            @if ($status === PurchaseOrderStatus::Draft && $canManage)
                                <button wire:click="edit({{ $order->id }})" class="text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                                <button wire:click="submit({{ $order->id }})" class="text-brand-600 hover:underline">Onaya Gönder</button>
                            @endif
                            @if ($status === PurchaseOrderStatus::PendingApproval && $canApprove)
                                <button wire:click="approve({{ $order->id }})" class="text-brand-600 hover:underline">Onayla</button>
                                <button wire:click="askNote({{ $order->id }}, 'reject')" class="text-status-critical hover:underline">Reddet</button>
                            @endif
                            @if ($status === PurchaseOrderStatus::Approved && $canManage)
                                <button wire:click="markOrdered({{ $order->id }})" wire:confirm="Sipariş tedarikçiye verildi olarak işaretlensin mi?" class="text-brand-600 hover:underline">Sipariş Verildi</button>
                            @endif
                            @if ($status->canReceive() && $canManage)
                                <button wire:click="openReceipt({{ $order->id }})" class="text-brand-600 hover:underline">Teslim Al</button>
                            @endif
                            @if ($status === PurchaseOrderStatus::PartiallyReceived && $canManage)
                                <button wire:click="askNote({{ $order->id }}, 'close')" class="text-ink-muted hover:underline">Kalanı Kapat</button>
                            @endif
                            @if ($status->canTransitionTo(PurchaseOrderStatus::Cancelled) && $canManage)
                                <button wire:click="askNote({{ $order->id }}, 'cancel')" class="text-ink-muted hover:text-status-critical hover:underline">İptal</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($orders->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $orders->links() }}</div>
        @endif
    </section>

    {{-- Talep formu --}}
    <x-modal :show="$showForm" :title="$editingId ? 'Taslağı Düzenle' : 'Yeni Satın Alma Talebi'" on-close="closeForm">
        <form wire:submit="save(false)" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tedarikçi</label>
                    <select wire:model="supplier_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Tedarikçi seçin</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Teslim Deposu</label>
                    <select wire:model="warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Depo seçin</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Beklenen Teslim</label>
                    <input type="date" wire:model="expected_delivery_date" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('expected_delivery_date') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-[13px] text-ink-muted">Kalemler</label>
                    <button type="button" wire:click="addLine" class="text-[13px] text-brand-600 hover:underline">+ Kalem ekle</button>
                </div>
                @error('lines') <span class="text-status-critical text-[12px] block mb-1">{{ $message }}</span> @enderror
                <div class="space-y-2">
                    @foreach ($lines as $index => $line)
                        <div class="grid grid-cols-12 gap-2 items-start" wire:key="line-{{ $index }}">
                            <div class="col-span-6">
                                <select wire:model.live="lines.{{ $index }}.product_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                    <option value="">Ürün seçin</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                @error("lines.$index.product_id") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-span-2">
                                <input type="number" step="0.01" placeholder="Miktar" wire:model="lines.{{ $index }}.quantity" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error("lines.$index.quantity") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-span-3">
                                <input type="number" step="0.01" placeholder="Birim fiyat ₺" wire:model="lines.{{ $index }}.unit_price" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error("lines.$index.unit_price") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-span-1 pt-2 text-right">
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-ink-muted hover:text-status-critical" title="Kalemi kaldır">✕</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Not / Gerekçe</label>
                <input type="text" wire:model="note" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="button" wire:click="save(true)" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onaya Gönder</button>
                <button type="submit" class="border border-line rounded-md px-4 py-2.5 text-[14px] hover:bg-canvas transition-colors">Taslak Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    {{-- Teslim alma --}}
    <x-modal :show="$receiving !== null" :title="$receiving ? $receiving->number().' — Teslim Al' : ''" on-close="closeReceipt">
        @if ($receiving)
            <form wire:submit="receive" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Fatura No</label>
                        <input type="text" wire:model="invoice_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('invoice_number') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">İrsaliye No</label>
                        <input type="text" wire:model="delivery_note_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('delivery_note_number') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Belge (PDF/JPG/PNG)</label>
                        <input type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-[13px]">
                        @error('document') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="border border-line rounded-md overflow-x-auto">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-ink-muted border-b border-line bg-canvas">
                                <th class="px-3 py-2 font-medium">Ürün</th>
                                <th class="px-3 py-2 font-medium text-right">Kalan</th>
                                <th class="px-3 py-2 font-medium w-24">Gelen</th>
                                <th class="px-3 py-2 font-medium">Lot No</th>
                                <th class="px-3 py-2 font-medium">SKT</th>
                                <th class="px-3 py-2 font-medium w-28">Alış Fiyatı</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($receiving->lines as $line)
                                @continue(! array_key_exists($line->id, $receiptLines))
                                <tr wire:key="receipt-line-{{ $line->id }}" class="align-top">
                                    <td class="px-3 py-2">{{ $line->product->name }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink-muted">{{ Number::format($line->remaining(), precision: 2) }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" wire:model="receiptLines.{{ $line->id }}.quantity" class="w-full border border-line rounded-md px-2 py-1.5">
                                        @error("receiptLines.{$line->id}.quantity") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="px-3 py-2"><input type="text" wire:model="receiptLines.{{ $line->id }}.lot_no" class="w-full border border-line rounded-md px-2 py-1.5"></td>
                                    <td class="px-3 py-2">
                                        <input type="date" wire:model="receiptLines.{{ $line->id }}.expiry_date" class="w-full border border-line rounded-md px-2 py-1.5">
                                        @error("receiptLines.{$line->id}.expiry_date") <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="px-3 py-2"><input type="number" step="0.01" wire:model="receiptLines.{{ $line->id }}.unit_cost" class="w-full border border-line rounded-md px-2 py-1.5"></td>
                                </tr>
                                @if ($line->product->cold_chain)
                                    <tr wire:key="receipt-line-temp-{{ $line->id }}">
                                        <td colspan="6" class="px-3 pb-3">
                                            <x-cold-chain-input :product="$line->product" :temperature="$receiptLines[$line->id]['temperature'] ?? ''" field="receiptLines.{{ $line->id }}.temperature" note-field="receiptLines.{{ $line->id }}.temperature_note" compact />
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-[12px] text-ink-muted">Gelmeyen kalemler için miktarı 0 bırak; kalan miktar açık sipariş olarak beklemeye devam eder.</p>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Teslim Al ve Stoğa İşle</button>
                    <button type="button" wire:click="closeReceipt" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- Gerekçe --}}
    <x-modal :show="$noteOrderId !== null" :title="match ($noteAction) { 'reject' => 'Talebi Reddet', 'close' => 'Kalanı Kapat', default => 'Siparişi İptal Et' }" on-close="closeNote">
        <form wire:submit="confirmNote" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                <input type="text" wire:model="actionNote" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('actionNote') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            @if ($noteAction === 'close')
                <p class="text-[12px] text-ink-muted">Teslim alınan ürünler stokta kalır; kalan miktar artık beklenmez ve sipariş tamamlanır.</p>
            @endif
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-status-critical text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:opacity-90 transition-opacity">Onayla</button>
                <button type="button" wire:click="closeNote" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
