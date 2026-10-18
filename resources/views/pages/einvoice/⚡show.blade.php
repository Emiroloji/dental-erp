<?php

use App\Domain\Catalog\Services\SupplierService;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Services\EInvoiceFinanceService;
use App\Domain\EInvoice\Services\EInvoiceMatcher;
use App\Domain\EInvoice\Services\EInvoiceStockService;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Gelen e-fatura detayı (Faz 6): başlık, tutarlar ve kalemler; ilaç/tıbbi
 * cihaz kalemlerinde GTIN, lot, seri ve tarih. Eşleşmeler (Aşama 45):
 * tedarikçi, satın alma teslimi, kalem ürün kartı ve ÜTS'de bu faturaya ait
 * kabul bekleyenler. Kabul yine ÜTS ekranının onay panelinden geçer.
 * Finans yazma yetkisiyle fatura cari borç olarak aktarılır (Aşama 46).
 * Başka organizasyonun faturası 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $invoiceId;

    public bool $transferring = false;

    public string $dueOn = '';

    public function mount(int $invoice, EInvoiceMatcher $matcher): void
    {
        $model = EInvoice::findOrFail($invoice);
        $this->invoiceId = $model->id;

        // Fatura geldikten sonra açılan tedarikçi, teslim ya da ürün kartı
        // burada yakalanır; elle yapılmış eşleşmeye dokunulmaz.
        $matcher->match($model);
    }

    public function createSupplier(SupplierService $suppliers, EInvoiceMatcher $matcher): void
    {
        Gate::authorize('supplier_management.create');

        $invoice = EInvoice::findOrFail($this->invoiceId);

        if ($invoice->supplier_id === null && filled($invoice->sender_name)) {
            $supplier = $suppliers->create(['name' => $invoice->sender_name, 'tax_number' => $invoice->sender_tax_number]);
            $invoice->update(['supplier_id' => $supplier->id]);
            $matcher->match($invoice->fresh());
            session()->flash('status', 'Tedarikçi eklendi ve faturaya bağlandı.');
        }
    }

    public function undoStock(EInvoiceStockService $stock): void
    {
        Gate::authorize('stock_movement.create');

        try {
            $stock->undo(EInvoice::findOrFail($this->invoiceId), auth()->user());
        } catch (EInvoiceException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Stok girişi geri alındı.');
    }

    public function openTransfer(): void
    {
        Gate::authorize('finance.create');

        $this->dueOn = EInvoice::findOrFail($this->invoiceId)->issue_date->toDateString();
        $this->transferring = true;
    }

    public function closeTransfer(): void
    {
        $this->transferring = false;
        $this->resetValidation();
    }

    public function transfer(EInvoiceFinanceService $finance): void
    {
        Gate::authorize('finance.create');

        $this->validate(['dueOn' => ['required', 'date']], ['dueOn.required' => 'Vade tarihini girin.']);

        try {
            $finance->transfer(EInvoice::findOrFail($this->invoiceId), Carbon::parse($this->dueOn));
        } catch (EInvoiceException $e) {
            $this->addError('dueOn', $e->getMessage());

            return;
        }

        $this->transferring = false;
        session()->flash('status', 'Fatura Finans\'a ödenecek olarak aktarıldı.');
    }

    public function with(EInvoiceFinanceService $finance, EInvoiceStockService $stock): array
    {
        $invoice = EInvoice::with(['lines.identities', 'lines.product', 'supplier', 'purchaseReceipt', 'financeObligation.party'])->findOrFail($this->invoiceId);

        return [
            'invoice' => $invoice,
            'transferred' => $finance->hasOpenTransfer($invoice),
            'stockRefusal' => $stock->refusal($invoice),
            ...$this->utsPending($invoice),
        ];
    }

    /**
     * ÜTS'de bu faturanın numarasıyla gelmiş, kabul bekleyen Verme
     * bildirimleri (ilk sayfa). Yalnızca takip kimliği olan faturada ve stok
     * görme yetkisiyle sorulur.
     *
     * @return array{utsRows: list<\App\Domain\Uts\Support\PendingReceipt>|null, utsError: ?string}
     */
    private function utsPending(EInvoice $invoice): array
    {
        $tracked = $invoice->lines->contains(fn ($line) => $line->identities->isNotEmpty());

        if (! $tracked || ! Gate::allows('stock_movement.viewAny')) {
            return ['utsRows' => null, 'utsError' => null];
        }

        try {
            return ['utsRows' => app(UtsClient::class)->pendingReceipts(null, 0, $invoice->invoice_number)->items, 'utsError' => null];
        } catch (UtsException $e) {
            return ['utsRows' => null, 'utsError' => $e->getMessage()];
        }
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between gap-4">
        <div>
            <a href="{{ route('einvoice.index') }}" class="text-[13px] text-ink-muted hover:text-ink">Gelen e-Faturalar</a>
            <h1 class="text-[22px] font-medium tracking-tight text-ink mt-1 font-mono">{{ $invoice->invoice_number }}</h1>
            <p class="text-[14px] text-ink-muted mt-1">{{ $invoice->sender_name ?? '—' }}, {{ $invoice->senderIdLabel() }} {{ $invoice->sender_tax_number ?? '—' }}</p>
        </div>
        <a href="{{ route('einvoice.pdf', $invoice->id) }}" target="_blank" rel="noopener" class="mt-4 sm:mt-0 inline-flex border border-line rounded-md px-4 py-2 text-[14px] text-ink hover:border-panel-900">PDF'i aç</a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line border border-line rounded-lg overflow-hidden mb-6 text-[14px]">
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Fatura tarihi</dt><dd class="text-ink mt-0.5">{{ $invoice->issue_date->format('d.m.Y') }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Senaryo / tip</dt><dd class="text-ink mt-0.5">{{ $invoice->profileLabel() }}, {{ $invoice->typeLabel() }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">KDV hariç / KDV</dt><dd class="text-ink mt-0.5 tabular-nums">{{ $invoice->formatAmount($invoice->tax_exclusive) }} / {{ $invoice->formatAmount($invoice->tax_total) }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Ödenecek</dt><dd class="text-ink mt-0.5 font-medium tabular-nums">{{ $invoice->formatAmount($invoice->payable) }}</dd></div>
    </dl>

    <section class="border border-line rounded-lg bg-surface mb-6">
        <h2 class="px-5 py-4 border-b border-line text-[15px] font-medium text-ink">Eşleşmeler</h2>
        <dl class="divide-y divide-line text-[14px]">
            <div class="px-5 py-3 sm:flex sm:items-center sm:justify-between gap-4">
                <dt class="text-ink-muted sm:w-48 shrink-0">Tedarikçi</dt>
                <dd class="flex-1 flex flex-wrap items-center justify-between gap-2">
                    @if ($invoice->supplier)
                        <span class="text-ink">{{ $invoice->supplier->name }}</span>
                    @else
                        <span class="text-status-warn">Tedarikçi kayıtlı değil (VKN eşleşmedi).</span>
                        @can('supplier_management.create')
                            <button type="button" wire:click="createSupplier" class="border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900">Tedarikçi olarak ekle</button>
                        @endcan
                    @endif
                </dd>
            </div>
            <div class="px-5 py-3 sm:flex sm:items-center gap-4">
                <dt class="text-ink-muted sm:w-48 shrink-0">Satın alma teslimi</dt>
                <dd class="flex-1">
                    @if ($invoice->purchaseReceipt)
                        <a href="{{ route('purchasing.show', $invoice->purchaseReceipt->purchase_order_id) }}" class="text-ink underline">Sipariş #{{ $invoice->purchaseReceipt->purchase_order_id }}, teslim kaydı</a>
                    @else
                        <span class="text-ink-muted">Bu fatura numarasıyla kaydedilmiş teslim yok. Teslim alırken fatura no olarak {{ $invoice->invoice_number }} girilirse eşleşir.</span>
                    @endif
                </dd>
            </div>
            <div class="px-5 py-3 sm:flex sm:items-center gap-4">
                <dt class="text-ink-muted sm:w-48 shrink-0">Stok</dt>
                <dd class="flex-1 flex flex-wrap items-center justify-between gap-2">
                    @if ($invoice->stocked_at)
                        <span class="text-ink">Stoğa alındı, {{ $invoice->stocked_at->format('d.m.Y H:i') }}.</span>
                        @can('stock_movement.create')
                            <button type="button" wire:click="undoStock" wire:confirm="Bu faturadan yapılan stok girişleri iptal edilsin mi?" class="text-[13px] text-status-critical hover:underline">Stok girişini geri al</button>
                        @endcan
                    @elseif ($invoice->purchase_receipt_id)
                        <span class="text-ink-muted">Satın alma teslimiyle stoğa girdi.</span>
                    @elseif ($stockRefusal)
                        <span class="text-ink-muted">{{ $stockRefusal }}</span>
                    @else
                        <span class="text-ink-muted">Stoğa alınmadı.</span>
                        @can('stock_movement.create')
                            <a href="{{ route('einvoice.stock', $invoice->id) }}" class="border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900">Stoğa al</a>
                        @endcan
                    @endif
                </dd>
            </div>
            <div class="px-5 py-3 sm:flex sm:items-center gap-4">
                <dt class="text-ink-muted sm:w-48 shrink-0">Finans</dt>
                <dd class="flex-1 flex flex-wrap items-center justify-between gap-2">
                    @if ($transferred)
                        <span class="text-ink">Ödenecek olarak aktarıldı: {{ $invoice->financeObligation->party?->name }}, vade {{ $invoice->financeObligation->due_on->format('d.m.Y') }}.
                            @can('finance.viewAny') <a href="{{ route('finance.obligations') }}" class="underline ml-1">Ödemeler ve tahsilatlar</a> @endcan
                        </span>
                    @elseif ($invoice->currency !== 'TRY' || $invoice->type === 'IADE')
                        <span class="text-ink-muted">{{ $invoice->type === 'IADE' ? 'İade faturası borç olarak aktarılmaz.' : 'TL dışı fatura Finans\'a aktarılmaz.' }}</span>
                    @else
                        <span class="text-ink-muted">Finans'a aktarılmadı.</span>
                        @can('finance.create')
                            <button type="button" wire:click="openTransfer" class="border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900">Cari borç olarak aktar</button>
                        @endcan
                    @endif
                </dd>
            </div>
            @if ($utsRows !== null || $utsError)
                <div class="px-5 py-3 sm:flex gap-4">
                    <dt class="text-ink-muted sm:w-48 shrink-0">ÜTS</dt>
                    <dd class="flex-1">
                        @if ($utsError)
                            <span class="text-ink-muted">{{ $utsError }}</span>
                        @elseif (count($utsRows) === 0)
                            <span class="text-ink-muted">ÜTS'de bu faturaya ait kabul bekleyen kayıt yok.</span>
                        @else
                            <p class="text-ink">ÜTS'de bu faturaya ait {{ count($utsRows) }} kabul bekleyen kayıt var.</p>
                            <ul class="mt-1 font-mono text-[12px] text-ink-muted">
                                @foreach ($utsRows as $row)
                                    <li>{{ $row->uno }}@if ($row->lot) <span class="ml-2">Lot {{ $row->lot }}</span>@endif @if ($row->serial) <span class="ml-2">Seri {{ $row->serial }}</span>@endif <span class="ml-2">{{ $row->quantity }} adet</span></li>
                                @endforeach
                            </ul>
                            <a href="{{ route('uts.pending', ['belge' => $invoice->invoice_number]) }}" class="inline-block mt-2 border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900">Kabul Bekleyenler'de aç</a>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="relative border border-line rounded-lg bg-surface overflow-x-auto">
        <h2 class="px-5 py-4 border-b border-line text-[15px] font-medium text-ink">Kalemler</h2>
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">#</th>
                    <th class="px-5 py-3 font-medium">Ürün / hizmet</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                    <th class="px-5 py-3 font-medium text-right">Birim fiyat</th>
                    <th class="px-5 py-3 font-medium text-right">KDV</th>
                    <th class="px-5 py-3 font-medium text-right">Tutar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($invoice->lines as $line)
                    <tr wire:key="line-{{ $line->id }}" class="align-top">
                        <td class="px-5 py-3 text-ink-muted tabular-nums">{{ $line->line_no }}</td>
                        <td class="px-5 py-3 min-w-[16rem]">
                            <div class="text-ink">{{ $line->name }}</div>
                            @if ($line->seller_code) <div class="text-[12px] text-ink-muted">Satıcı kodu {{ $line->seller_code }}</div> @endif
                            @if ($line->identities->isNotEmpty())
                                <ul class="mt-2 space-y-1">
                                    @foreach ($line->identities as $identity)
                                        <li class="font-mono text-[12px] text-ink-muted flex flex-wrap gap-x-3">
                                            <span class="whitespace-nowrap">{{ $identity->gtin ?? '—' }}</span>
                                            @if ($identity->lot) <span class="whitespace-nowrap">Lot {{ $identity->lot }}</span> @endif
                                            @if ($identity->serial) <span class="whitespace-nowrap">Seri {{ $identity->serial }}</span> @endif
                                            @if ($identity->expiry_date) <span class="whitespace-nowrap">SKT {{ $identity->expiry_date->format('d.m.Y') }}</span> @endif
                                            @if ($identity->production_date) <span class="whitespace-nowrap">Üretim {{ $identity->production_date->format('d.m.Y') }}</span> @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="mt-1.5 text-[12px] {{ $line->product ? 'text-brand-600' : 'text-ink-muted' }}">{{ $line->product ? 'Ürün kartı: '.$line->product->name : 'Ürün kartı yok' }}</div>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ Number::format((float) $line->quantity, maxPrecision: 4) }} <span class="text-ink-muted text-[12px]">{{ $line->unitLabel() }}</span></td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ $invoice->formatAmount($line->unit_price) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums text-ink-muted">{{ $line->vat_rate !== null ? '%'.Number::format((float) $line->vat_rate, maxPrecision: 2) : '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ $invoice->formatAmount($line->line_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <x-modal :show="$transferring" title="Cari borç olarak aktar" on-close="closeTransfer">
        <form wire:submit="transfer" class="space-y-4">
            <p class="text-[14px] text-ink">{{ $invoice->sender_name }} carisine <span class="font-medium tabular-nums">{{ $invoice->formatAmount($invoice->payable) }}</span> ödenecek kaydı açılır (belge no {{ $invoice->invoice_number }}). Cari yoksa VKN ile Tedarikçi olarak açılır.</p>
            <div class="max-w-[12rem]">
                <label for="due-on" class="block text-[13px] text-ink-muted mb-1.5">Vade tarihi</label>
                <input id="due-on" type="date" wire:model="dueOn" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            @error('dueOn') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Aktar</button>
                <button type="button" wire:click="closeTransfer" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
