<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Services\ObligationSummaryService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    /** Domain hata alanı → bu formdaki özellik. */
    private const FORM_FIELDS = ['amount' => 'amount', 'date' => 'dueOn', 'due_on' => 'dueOn', 'party' => 'partyId', 'category' => 'categoryId', 'description' => 'description', 'reason' => 'cancelReason'];

    private const PAY_FIELDS = ['amount' => 'payAmount', 'date' => 'payDate', 'account' => 'payAccountId'];

    private const TABS = ['overdue', 'week', 'month', 'all'];

    #[Url]
    public string $tab = 'week';

    #[Url]
    public string $direction = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formDirection = 'payable';

    public string $amount = '';

    public string $dueOn = '';

    public string $partyId = '';

    public string $categoryId = '';

    public string $description = '';

    public string $documentNo = '';

    public string $branchId = '';

    public ?int $payingId = null;

    public string $payAccountId = '';

    public string $payAmount = '';

    public string $payDate = '';

    public string $payDescription = '';

    public ?int $cancellingId = null;

    public string $cancelReason = '';

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'direction'], true)) {
            $this->normalizeFilters();
            $this->resetPage();
        }
    }

    public function updatedFormDirection(): void
    {
        // Kategori yöne bağlı: yön değişince eski yönün kategorisi kalmasın.
        $this->categoryId = '';
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'week';
        }

        if (ObligationDirection::tryFrom($this->direction) === null) {
            $this->direction = '';
        }
    }

    public function openForm(?int $id = null, string $direction = 'payable'): void
    {
        Gate::authorize($id === null ? 'finance.create' : 'finance.update');

        $this->closeForm();

        if ($id !== null) {
            $obligation = FinanceObligation::findOrFail($id);
            $this->editingId = $obligation->id;
            $this->formDirection = $obligation->direction->value;
            $this->amount = Money::input($obligation->amount);
            $this->dueOn = $obligation->due_on->toDateString();
            $this->partyId = (string) $obligation->party_id;
            $this->categoryId = (string) $obligation->category_id;
            $this->description = $obligation->description;
            $this->documentNo = (string) $obligation->document_no;
            $this->branchId = (string) $obligation->branch_id;
        } else {
            $this->formDirection = ObligationDirection::tryFrom($direction)?->value ?? 'payable';
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'formDirection', 'amount', 'dueOn', 'partyId', 'categoryId', 'description', 'documentNo', 'branchId');
        $this->resetValidation();
    }

    public function save(ObligationService $obligations): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $editing = $this->editingId === null ? null : FinanceObligation::findOrFail($this->editingId);

        // Düzenlemede yön değişmez: sahte formDirection yok sayılır.
        if ($editing !== null) {
            $this->formDirection = $editing->direction->value;
        }

        $this->validate([
            'formDirection' => ['required', Rule::enum(ObligationDirection::class)],
            'amount' => ['required', 'string'],
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'partyId' => ['nullable', 'integer'],
            'categoryId' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:500'],
            'documentNo' => ['nullable', 'string', 'max:100'],
            'branchId' => ['nullable', 'integer'],
        ], [], ['amount' => 'tutar', 'dueOn' => 'vade', 'description' => 'açıklama']);

        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $data = [
            'direction' => ObligationDirection::from($this->formDirection),
            'amount' => $amount,
            'due_on' => $this->dueOn !== '' ? CarbonImmutable::parse($this->dueOn) : null,
            'party' => $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null,
            'category' => $this->categoryId !== '' ? FinanceCategory::findOrFail($this->categoryId) : null,
            'description' => $this->description,
            'document_no' => $this->documentNo,
            'branch' => $this->branchId !== '' ? Branch::where('organization_id', auth()->user()->organization_id)->findOrFail($this->branchId) : null,
        ];

        try {
            $editing === null
                ? $obligations->create($data)
                : $obligations->update($editing, $data);
        } catch (FinanceRuleException $e) {
            $this->addError(self::FORM_FIELDS[$e->field] ?? 'amount', $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Kayıt eklendi.' : 'Kayıt güncellendi.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function startPay(int $id, FinanceBalanceService $balances): void
    {
        Gate::authorize('finance.create');

        $obligation = FinanceObligation::findOrFail($id);
        abort_unless($obligation->isOpen(), 404);
        $this->resetValidation();
        $this->payingId = $obligation->id;
        $this->payAmount = Money::input($balances->remaining($obligation));
        $this->payDate = FinanceCalendar::today()->toDateString();
        $this->payAccountId = '';
        $this->payDescription = '';
    }

    public function cancelPay(): void
    {
        $this->reset('payingId', 'payAccountId', 'payAmount', 'payDate', 'payDescription');
        $this->resetValidation();
    }

    public function confirmPay(LedgerService $ledger): void
    {
        Gate::authorize('finance.create');

        $this->validate([
            'payAccountId' => ['required', 'integer'],
            'payAmount' => ['required', 'string'],
            'payDate' => ['required', 'date_format:Y-m-d'],
            'payDescription' => ['nullable', 'string', 'max:500'],
        ], [], ['payAccountId' => 'hesap', 'payAmount' => 'tutar', 'payDate' => 'tarih']);

        try {
            $amount = Money::parse($this->payAmount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $obligation = FinanceObligation::findOrFail($this->payingId);

        try {
            $entry = $ledger->pay($obligation, FinanceAccount::findOrFail($this->payAccountId), $amount, CarbonImmutable::parse($this->payDate), auth()->user(), $this->payDescription);
        } catch (FinanceRuleException $e) {
            $this->addError(self::PAY_FIELDS[$e->field] ?? 'payAmount', $e->getMessage());

            return;
        }

        $this->cancelPay();
        session()->flash('status', "{$entry->label()} kaydedildi.");
    }

    public function startCancel(int $id): void
    {
        Gate::authorize('finance.update');

        $this->cancellingId = FinanceObligation::findOrFail($id)->id;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function abortCancel(): void
    {
        $this->reset('cancellingId', 'cancelReason');
    }

    public function confirmCancel(ObligationService $obligations): void
    {
        Gate::authorize('finance.update');

        try {
            $obligations->cancel(FinanceObligation::findOrFail($this->cancellingId), $this->cancelReason);
        } catch (FinanceRuleException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->abortCancel();
        session()->flash('status', 'Kayıt iptal edildi.');
    }

    public function with(FinanceBalanceService $balances, ObligationSummaryService $summary): array
    {
        $today = FinanceCalendar::today();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'week';
        $direction = ObligationDirection::tryFrom($this->direction);
        $open = fn ($query) => $query->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial]);

        $obligations = FinanceObligation::with(['party', 'category'])->withExists('payments')
            ->when($direction, fn ($query) => $query->where('direction', $direction))
            ->when($tab !== 'all', $open)
            ->when($tab === 'overdue', fn ($query) => $query->whereDate('due_on', '<', $today->toDateString()))
            ->when($tab === 'week', fn ($query) => $query->whereDate('due_on', '<=', $today->addDays(7)->toDateString()))
            ->when($tab === 'month', fn ($query) => $query->whereDate('due_on', '<=', $today->addDays(30)->toDateString()))
            ->orderBy('due_on', $tab === 'all' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25);

        return [
            'obligations' => $obligations,
            'paid' => $balances->paidAmounts($obligations->getCollection()),
            'today' => $today,
            'summary' => $summary->summarize(0, $today),
            'accounts' => FinanceAccount::where('status', 'active')->orderBy('name')->get(),
            'parties' => FinanceParty::where('status', 'active')->orderBy('name')->get(),
            'categories' => FinanceCategory::where('status', 'active')->orderBy('name')->get(),
            'branches' => Branch::where('organization_id', auth()->user()->organization_id)->where('status', 'active')->orderBy('name')->get(),
            'payingObligation' => $this->payingId ? FinanceObligation::find($this->payingId) : null,
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Vadeli borçlar ve alacaklar. Ödendikçe kapanır; kısmi ödeme yapılabilir.</p>
        </div>
        @can('finance.create')
            <div class="mt-4 sm:mt-0 flex flex-col sm:flex-row gap-2">
                <button wire:click="openForm(null, 'payable')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-out hover:opacity-90">− Ödenecek ekle</button>
                <button wire:click="openForm(null, 'receivable')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-in hover:opacity-90">+ Tahsil edilecek ekle</button>
            </div>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-3">
        @foreach ([
            ['Geciken ödeme', $summary['overduePayable'], 'text-finance-out', null],
            ['Geciken tahsilat', $summary['overdueReceivable'], 'text-finance-in', null],
            ['7 gün içinde ödenecek', $summary['weekPayable'], 'text-ink', 'gecikenler dahil'],
            ['7 gün içinde tahsil edilecek', $summary['weekReceivable'], 'text-ink', 'gecikenler dahil'],
        ] as [$label, $value, $color, $hint])
            <div class="border border-line rounded-lg bg-surface px-4 py-3">
                <div class="text-[12px] text-ink-muted">{{ $label }}@if ($hint) <span class="text-[11px]">({{ $hint }})</span>@endif</div>
                <div class="mt-1 text-[18px] sm:text-[22px] font-medium tabular-nums {{ $value > 0 ? $color : 'text-ink-muted' }}">{{ $value > 0 ? Money::format($value) : '—' }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex flex-wrap gap-1">
            @foreach (['overdue' => 'Geciken', 'week' => '7 gün', 'month' => '30 gün', 'all' => 'Tümü'] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')" @class(['px-3 py-1.5 rounded-md text-[13px] border', 'bg-panel-900 border-panel-900 text-white' => $tab === $key, 'border-line text-ink-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="direction" class="w-full sm:w-52 border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
            <option value="">Tümü</option>
            <option value="payable">Ödenecek</option>
            <option value="receivable">Tahsil edilecek</option>
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Vade</th>
                    <th class="px-3 sm:px-5 py-3 font-medium">Açıklama</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Yön</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Tutar</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Kalan</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Durum</th>
                    <th class="px-3 sm:px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($obligations as $obligation)
                    @php
                        $isOpen = $obligation->status->isOpen();
                        $dueDate = $obligation->due_on->toDateString();
                        $todayDate = $today->toDateString();
                        $overdue = $isOpen && $dueDate < $todayDate;
                        $daysLate = $overdue ? (int) abs(CarbonImmutable::createFromFormat('Y-m-d', $dueDate, $today->timezone)->startOfDay()->diffInDays($today->startOfDay())) : 0;
                        $remaining = $obligation->amount - $paid[$obligation->id];
                        $isPayable = $obligation->direction === ObligationDirection::Payable;
                    @endphp
                    <tr wire:key="obligation-{{ $obligation->id }}" @class(['text-ink-muted' => $obligation->status === ObligationStatus::Cancelled])>
                        <td class="px-3 sm:px-5 py-3 whitespace-nowrap align-top">
                            <div @class(['tabular-nums', 'text-finance-out font-medium' => $overdue])>{{ $obligation->due_on->format('d.m.Y') }}</div>
                            @if ($overdue)
                                <div class="text-[12px] text-finance-out">{{ $daysLate }} gün gecikti</div>
                            @elseif ($isOpen && $dueDate === $todayDate)
                                <div class="text-[12px] text-ink-muted">Bugün</div>
                            @endif
                        </td>
                        <td class="px-3 sm:px-5 py-3 align-top">
                            <div @class(['font-medium', 'text-ink' => $obligation->status !== ObligationStatus::Cancelled, 'line-through' => $obligation->status === ObligationStatus::Cancelled])>{{ $obligation->description }}</div>
                            <div class="text-[12px] text-ink-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                                @if ($obligation->document_no)
                                    <span>{{ $obligation->document_no }}</span>
                                @endif
                                @if ($obligation->party)
                                    <a href="{{ route('finance.parties.show', $obligation->party) }}" wire:navigate class="hover:underline hover:text-ink">{{ $obligation->party->name }}</a>
                                @endif
                                @if ($obligation->category)
                                    <span class="inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $obligation->category->name }}</span>
                                @endif
                                <span class="sm:hidden {{ $isPayable ? 'text-finance-out' : 'text-finance-in' }}">{{ $obligation->direction->label() }}</span>
                                @if ($obligation->status !== ObligationStatus::Open)
                                    <span class="sm:hidden">{{ $obligation->status->label() }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top {{ $isPayable ? 'text-finance-out' : 'text-finance-in' }}">{{ $obligation->direction->label() }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums align-top">{{ Money::format($obligation->amount) }}</td>
                        <td @class(['px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium align-top', 'text-finance-out' => $isOpen && $isPayable, 'text-finance-in' => $isOpen && ! $isPayable, 'text-ink-muted' => ! $isOpen])>{{ $obligation->status === ObligationStatus::Cancelled ? '—' : Money::format($remaining) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top">
                            <span @class(['inline-flex px-2 py-0.5 rounded text-[12px]', 'bg-brand-100 text-brand-600' => $obligation->status === ObligationStatus::Paid, 'bg-line text-ink-muted' => $obligation->status !== ObligationStatus::Paid])>{{ $obligation->status->label() }}</span>
                        </td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap align-top">
                            @if ($isOpen)
                                @can('finance.create')
                                    <button wire:click="startPay({{ $obligation->id }})" @class(['rounded-md px-3 py-1.5 text-[13px] font-medium text-white hover:opacity-90', 'bg-finance-out' => $isPayable, 'bg-finance-in' => ! $isPayable])>{{ $isPayable ? 'Öde' : 'Tahsil et' }}</button>
                                @endcan
                                @can('finance.update')
                                    @if ($paid[$obligation->id] === 0)
                                        <div class="mt-1">
                                            @unless ($obligation->payments_exists)
                                                <button wire:click="openForm({{ $obligation->id }})" class="mr-3 text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                                            @endunless
                                            <button wire:click="startCancel({{ $obligation->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">İptal</button>
                                        </div>
                                    @endif
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-ink-muted text-[13px]">
                            {{ match ($tab) {
                                'overdue' => 'Geciken kayıt yok.',
                                'month' => 'Önümüzdeki 30 günde vadesi gelen kayıt yok.',
                                'all' => 'Henüz kayıt yok. Ödenecek veya tahsil edilecek bir tutar ekle.',
                                default => 'Önümüzdeki 7 günde vadesi gelen kayıt yok.',
                            } }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $obligations->links() }}</div>

    <x-modal :show="$showForm" :title="$editingId === null ? 'Yeni Kayıt' : 'Kaydı Düzenle'" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yön</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    @foreach (ObligationDirection::cases() as $option)
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" wire:model.live="formDirection" value="{{ $option->value }}" @disabled($editingId) class="peer sr-only">
                            <span class="block text-center border border-line rounded-md px-3 py-2 text-[14px] peer-checked:border-brand-500 peer-checked:bg-brand-100 peer-checked:text-brand-600 peer-disabled:opacity-60">{{ $option->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('formDirection') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tutar</label>
                    <input type="text" inputmode="decimal" wire:model="amount" placeholder="0,00" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('amount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Vade</label>
                    <input type="date" wire:model="dueOn" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    <p class="text-[12px] text-ink-muted mt-1">Boş bırakırsan carinin varsayılan vadesi kullanılır.</p>
                    @error('dueOn') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Cari</label>
                    <select wire:model="partyId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">—</option>
                        @foreach ($parties as $party)
                            <option value="{{ $party->id }}">{{ $party->name }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('finance.parties') }}" wire:navigate class="text-[12px] text-brand-600 hover:underline">Cari ekle →</a>
                    @error('partyId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Kategori</label>
                    <select wire:model="categoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">—</option>
                        @foreach ($categories->where('direction', ObligationDirection::tryFrom($formDirection)?->categoryDirection()) as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                <input type="text" wire:model="description" placeholder="Örn. Fatura F-77, Ekim sigorta hakedişi" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('description') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Belge no</label>
                    <input type="text" wire:model="documentNo" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('documentNo') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Şube</label>
                    <select wire:model="branchId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">—</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branchId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$payingObligation !== null" :title="$payingObligation?->direction->paymentLabel()" on-close="cancelPay">
        @if ($payingObligation)
            <div class="mb-4 rounded-md border border-line px-4 py-3">
                <div class="text-[14px] font-medium text-ink">{{ $payingObligation->description }}</div>
                <div class="text-[13px] text-ink-muted mt-0.5">Kalan: <span class="tabular-nums font-medium text-ink">{{ Money::format(app(FinanceBalanceService::class)->remaining($payingObligation)) }}</span></div>
            </div>
            @if ($accounts->isEmpty())
                <p class="text-[13px] text-ink-muted">Önce bir kasa veya banka hesabı tanımla. <a href="{{ route('finance.accounts') }}" wire:navigate class="text-brand-600 hover:underline">Hesaplar →</a></p>
            @else
                <form wire:submit="confirmPay" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">Hesap</label>
                            <select wire:model="payAccountId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                                <option value="">Seç</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error('payAccountId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">Tutar</label>
                            <input type="text" inputmode="decimal" wire:model="payAmount" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                            @error('payAmount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">Tarih</label>
                            <input type="date" wire:model="payDate" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            @error('payDate') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                            <input type="text" wire:model="payDescription" placeholder="Boşsa kaydın açıklaması kullanılır" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            @error('payDescription') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">{{ $payingObligation->direction === ObligationDirection::Payable ? 'Öde' : 'Tahsil et' }}</button>
                        <button type="button" wire:click="cancelPay" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
                    </div>
                </form>
            @endif
        @endif
    </x-modal>

    <x-modal :show="$cancellingId !== null" title="Kaydı iptal et" on-close="abortCancel">
        <form wire:submit="confirmCancel" class="space-y-4">
            <p class="text-[13px] text-ink-muted">Kayıt silinmez; iptal edilir ve listede 'İptal' olarak kalır. Ödemesi olan kayıt iptal edilemez.</p>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                <input type="text" wire:model="cancelReason" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('cancelReason') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-finance-out text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:opacity-90">İptal et</button>
                <button type="button" wire:click="abortCancel" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
