<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\Money;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public ?string $formType = null;

    public string $accountId = '';

    public string $toAccountId = '';

    public string $amount = '';

    public string $date = '';

    public string $categoryId = '';

    public string $branchId = '';

    public string $partyId = '';

    public string $description = '';

    public string $documentNo = '';

    public string $commission = '';

    public string $commissionCategoryId = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterAccount = '';

    public string $filterType = '';

    public string $filterCategory = '';

    public ?int $reversingId = null;

    public string $reverseReason = '';

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function openForm(string $type): void
    {
        Gate::authorize('finance.create');

        abort_unless(in_array($type, ['income', 'expense', 'transfer'], true), 404);

        $this->resetForm();
        $this->formType = $type;
        $this->date = FinanceCalendar::today()->toDateString();
    }

    public function closeForm(): void
    {
        $this->formType = null;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('accountId', 'toAccountId', 'amount', 'date', 'categoryId', 'branchId', 'partyId', 'description', 'documentNo', 'commission', 'commissionCategoryId');
        $this->resetValidation();
    }

    public function save(LedgerService $ledger, FinanceBalanceService $balances): void
    {
        Gate::authorize('finance.create');

        $isTransfer = $this->formType === 'transfer';

        $this->validate([
            'formType' => ['required', 'in:income,expense,transfer'],
            'accountId' => ['required', 'integer'],
            'toAccountId' => [$isTransfer ? 'required' : 'nullable', 'integer', 'different:accountId'],
            'amount' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'categoryId' => [$isTransfer ? 'nullable' : 'required', 'integer'],
            'branchId' => ['nullable', 'integer'],
            'partyId' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
            'documentNo' => ['nullable', 'string', 'max:100'],
            'commission' => ['nullable', 'string'],
            'commissionCategoryId' => ['nullable', 'integer'],
        ], [], [
            'accountId' => 'hesap', 'toAccountId' => 'hedef hesap', 'amount' => 'tutar',
            'date' => 'tarih', 'categoryId' => 'kategori',
        ]);

        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        try {
            $commission = $isTransfer && trim($this->commission) !== '' ? Money::parse($this->commission) : 0;
        } catch (\InvalidArgumentException $e) {
            $this->addError('commission', $e->getMessage());

            return;
        }

        $account = FinanceAccount::findOrFail($this->accountId);
        $date = CarbonImmutable::parse($this->date);
        $actor = auth()->user();

        try {
            if ($isTransfer) {
                $target = FinanceAccount::findOrFail($this->toAccountId);
                $ledger->transfer(
                    $account, $target, $amount, $date, $actor, $this->description,
                    $commission, $this->commissionCategoryId !== '' ? FinanceCategory::findOrFail($this->commissionCategoryId) : null,
                );
                $touched = [$account, $target];
            } else {
                $category = FinanceCategory::findOrFail($this->categoryId);
                $branch = $this->branchId !== '' ? Branch::where('organization_id', $actor->organization_id)->findOrFail($this->branchId) : null;
                $party = $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null;
                if ($this->formType === 'income') {
                    $ledger->income($account, $amount, $date, $category, $actor, $this->description, $branch, $this->documentNo, $party);
                } else {
                    $ledger->expense($account, $amount, $date, $category, $actor, $this->description, $branch, $this->documentNo, $party);
                }
                $touched = [$account];
            }
        } catch (FinanceRuleException $e) {
            $field = match (true) {
                str_contains($e->getMessage(), 'tarih') => 'date',
                str_contains($e->getMessage(), 'cari') => 'partyId',
                default => 'amount',
            };
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->closeForm();

        $negative = collect($touched)->first(fn (FinanceAccount $touchedAccount) => $balances->balance($touchedAccount) < 0);
        session()->flash('status', 'Hareket kaydedildi.');

        if ($negative) {
            session()->flash('warning', "{$negative->name} hesabının bakiyesi eksiye düştü (".Money::format($balances->balance($negative)).').');
        }
    }

    public function startReverse(int $entryId): void
    {
        Gate::authorize('finance.update');

        $this->reversingId = $entryId;
        $this->reverseReason = '';
        $this->resetValidation();
    }

    public function cancelReverse(): void
    {
        $this->reversingId = null;
        $this->reverseReason = '';
    }

    public function confirmReverse(LedgerService $ledger): void
    {
        Gate::authorize('finance.update');

        $entry = FinanceEntry::findOrFail($this->reversingId);

        try {
            $count = $ledger->reverse($entry, $this->reverseReason, auth()->user())->count();
        } catch (FinanceRuleException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->cancelReverse();
        session()->flash('status', $count > 1 ? "Transfer iptal edildi ({$count} kayıt ters kayıtla kapatıldı)." : 'Hareket iptal edildi.');
    }

    public function with(): array
    {
        $isDate = '/^\d{4}-\d{2}-\d{2}$/';

        $entries = FinanceEntry::with(['account', 'category', 'branch', 'actor', 'reversal', 'party'])
            ->when(preg_match($isDate, $this->filterFrom), fn ($query) => $query->whereDate('occurred_on', '>=', $this->filterFrom))
            ->when(preg_match($isDate, $this->filterTo), fn ($query) => $query->whereDate('occurred_on', '<=', $this->filterTo))
            ->when((int) $this->filterAccount > 0, fn ($query) => $query->where('account_id', (int) $this->filterAccount))
            ->when(FinanceEntryType::tryFrom($this->filterType) !== null, fn ($query) => $query->where('type', $this->filterType))
            ->when((int) $this->filterCategory > 0, fn ($query) => $query->where('category_id', (int) $this->filterCategory))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(25);

        $accounts = FinanceAccount::orderBy('name')->get();
        $categories = FinanceCategory::orderBy('name')->get();

        return [
            'entries' => $entries,
            'accounts' => $accounts,
            'activeAccounts' => $accounts->filter->isActive(),
            'categories' => $categories,
            'formCategories' => $categories->filter(fn ($category) => $category->isActive() && $category->direction === ($this->formType === 'income' ? CategoryDirection::Income : CategoryDirection::Expense)),
            'expenseCategories' => $categories->filter(fn ($category) => $category->isActive() && $category->direction === CategoryDirection::Expense),
            'branches' => Branch::where('organization_id', auth()->user()->organization_id)->where('status', 'active')->orderBy('name')->get(),
            'parties' => FinanceParty::where('status', 'active')->orderBy('name')->get(),
            'types' => FinanceEntryType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kasa ve bankadan giren çıkan her para. Kayıtlar silinmez; yanlışsa iptal edilir.</p>
        </div>
        @can('finance.create')
            @if ($activeAccounts->isNotEmpty())
                <div class="mt-4 sm:mt-0 grid grid-cols-3 sm:flex gap-2">
                    <button wire:click="openForm('income')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-in hover:opacity-90">+ Gelir</button>
                    <button wire:click="openForm('expense')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-finance-out hover:opacity-90">− Gider</button>
                    <button wire:click="openForm('transfer')" class="inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium border border-line bg-surface text-ink hover:bg-canvas">⇄ Transfer</button>
                </div>
            @endif
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-md bg-surface border border-finance-out/40 text-finance-out text-[13px] px-4 py-3">{{ session('warning') }}</div>
    @endif

    @if ($accounts->isEmpty())
        <div class="border border-dashed border-line rounded-lg bg-surface px-6 py-10 text-center">
            <p class="text-[14px] text-ink">Önce bir kasa veya banka hesabı tanımla.</p>
            <a href="{{ route('finance.accounts') }}" wire:navigate class="inline-block mt-3 text-[14px] text-brand-600 hover:underline">Hesaplar'a git →</a>
        </div>
    @else
        <div class="mb-4 grid grid-cols-2 lg:grid-cols-5 gap-3">
            <input type="date" wire:model.live="filterFrom" aria-label="Başlangıç" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
            <input type="date" wire:model.live="filterTo" aria-label="Bitiş" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
            <select wire:model.live="filterAccount" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm hesaplar</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterType" class="border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm türler</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCategory" class="col-span-2 lg:col-span-1 border border-line rounded-md px-3 py-2 text-[13px] bg-surface">
                <option value="">Tüm kategoriler</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <section class="border border-line rounded-lg bg-surface overflow-x-auto">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-3 sm:px-5 py-3 font-medium">Tarih</th>
                        <th class="hidden sm:table-cell px-5 py-3 font-medium">Hesap</th>
                        <th class="px-3 sm:px-5 py-3 font-medium">Açıklama</th>
                        <th class="px-3 sm:px-5 py-3 font-medium text-right">Tutar</th>
                        <th class="px-3 sm:px-5 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($entries as $entry)
                        @php($reversed = $entry->reversal !== null)
                        <tr wire:key="entry-{{ $entry->id }}" @class(['text-ink-muted' => $reversed])>
                            <td class="px-3 sm:px-5 py-3 whitespace-nowrap tabular-nums align-top">{{ $entry->occurred_on->format('d.m.Y') }}</td>
                            <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top">{{ $entry->account->name }}</td>
                            <td class="px-3 sm:px-5 py-3">
                                <div class="flex flex-wrap items-center gap-x-2">
                                    <span class="text-[12px] text-ink-muted">{{ $entry->label() }}</span>
                                    <span class="sm:hidden text-[12px] text-ink-muted">· {{ $entry->account->name }}</span>
                                    @if ($entry->category)
                                        <span class="text-[12px] px-1.5 rounded bg-canvas border border-line">{{ $entry->category->name }}</span>
                                    @endif
                                    @if ($entry->branch)
                                        <span class="text-[12px] text-ink-muted">· {{ $entry->branch->name }}</span>
                                    @endif
                                    @if ($reversed)
                                        <span class="text-[12px] px-1.5 rounded bg-line">İptal edildi</span>
                                    @endif
                                </div>
                                @if ($entry->description || $entry->document_no)
                                    <div @class(['text-[13px] mt-0.5', 'line-through' => $reversed])>
                                        {{ $entry->description }}
                                        @if ($entry->document_no) <span class="text-ink-muted">· Belge {{ $entry->document_no }}</span> @endif
                                    </div>
                                @endif
                                @if ($entry->party)
                                    <div class="text-[12px] text-ink-muted mt-0.5">· <a href="{{ route('finance.parties.show', $entry->party) }}" wire:navigate class="hover:underline hover:text-ink">{{ $entry->party->name }}</a></div>
                                @endif
                            </td>
                            <td @class([
                                'px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium align-top',
                                'line-through' => $reversed,
                                'text-finance-in' => ! $reversed && $entry->direction > 0,
                                'text-finance-out' => ! $reversed && $entry->direction < 0,
                            ])>
                                {{ $entry->direction > 0 ? '+' : '−' }}{{ \App\Domain\Finance\Support\Money::format($entry->amount) }}
                            </td>
                            <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap align-top">
                                @can('finance.update')
                                    @if (! $reversed && $entry->type !== \App\Domain\Finance\Support\FinanceEntryType::Reversal)
                                        <button wire:click="startReverse({{ $entry->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">İptal et</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-ink-muted text-[13px]">Bu filtrelerle hareket yok.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <div class="mt-4">{{ $entries->links() }}</div>
    @endif

    <x-modal :show="$formType !== null" :title="match ($formType) { 'income' => 'Gelir Ekle', 'expense' => 'Gider Ekle', 'transfer' => 'Hesaplar Arası Transfer', default => '' }" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">{{ $formType === 'transfer' ? 'Çıkan hesap' : 'Hesap' }}</label>
                    <select wire:model="accountId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">Seç</option>
                        @foreach ($activeAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('accountId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                @if ($formType === 'transfer')
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Giren hesap</label>
                        <select wire:model="toAccountId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($activeAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error('toAccountId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                @else
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Kategori</label>
                        <select wire:model="categoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($formCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        @if ($formCategories->isEmpty())
                            <a href="{{ route('finance.categories') }}" wire:navigate class="text-[12px] text-brand-600 hover:underline">Kategori ekle →</a>
                        @endif
                    </div>
                @endif
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tutar (₺)</label>
                    <input type="text" inputmode="decimal" wire:model="amount" placeholder="1.250,50" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('amount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tarih</label>
                    <input type="date" wire:model="date" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('date') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                @if ($formType === 'transfer')
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Komisyon (isteğe bağlı)</label>
                        <input type="text" inputmode="decimal" wire:model="commission" placeholder="0" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                        <p class="text-[12px] text-ink-muted mt-1">POS'tan bankaya geçişte kesilen tutar; giren hesaptan gider olarak düşer.</p>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Komisyon kategorisi</label>
                        <select wire:model="commissionCategoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">Seç</option>
                            @foreach ($expenseCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Şube (isteğe bağlı)</label>
                        <select wire:model="branchId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">—</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Belge no (isteğe bağlı)</label>
                        <input type="text" wire:model="documentNo" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[13px] text-ink-muted mb-1.5">Cari (isteğe bağlı)</label>
                        <select wire:model="partyId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            <option value="">—</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}">{{ $party->name }}</option>
                            @endforeach
                        </select>
                        @error('partyId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                <input type="text" wire:model="description" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$reversingId !== null" title="Hareketi iptal et" on-close="cancelReverse">
        <form wire:submit="confirmReverse" class="space-y-4">
            <p class="text-[13px] text-ink-muted">Kayıt silinmez; bugünün tarihiyle ters kayıt yazılır. Transferse tüm ayakları birlikte iptal edilir.</p>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                <input type="text" wire:model="reverseReason" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('reverseReason') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">İptal et</button>
                <button type="button" wire:click="cancelReverse" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
