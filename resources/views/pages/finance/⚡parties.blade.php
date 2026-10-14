<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\PartyKind;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    /** Domain hata alanı → bu formdaki özellik. */
    private const FORM_FIELDS = ['name' => 'name'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $kind = 'customer';

    public string $phone = '';

    public string $email = '';

    public string $taxNumber = '';

    public string $defaultTermDays = '';

    public string $note = '';

    public string $search = '';

    public string $filterKind = '';

    public function openForm(?int $partyId = null): void
    {
        Gate::authorize($partyId === null ? 'finance.create' : 'finance.update');

        $this->closeForm();

        if ($partyId !== null) {
            $party = FinanceParty::findOrFail($partyId);
            $this->editingId = $party->id;
            $this->name = $party->name;
            $this->kind = $party->kind->value;
            $this->phone = (string) $party->phone;
            $this->email = (string) $party->email;
            $this->taxNumber = (string) $party->tax_number;
            $this->defaultTermDays = $party->default_term_days === null ? '' : (string) $party->default_term_days;
            $this->note = (string) $party->note;
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'name', 'kind', 'phone', 'email', 'taxNumber', 'defaultTermDays', 'note');
        $this->resetValidation();
    }

    public function save(FinancePartyService $parties): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(PartyKind::class)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'taxNumber' => ['nullable', 'string', 'max:20'],
            'defaultTermDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'ad', 'defaultTermDays' => 'vade (gün)', 'taxNumber' => 'vergi no']);

        $data = [
            'name' => $validated['name'],
            'kind' => PartyKind::from($validated['kind']),
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'tax_number' => $validated['taxNumber'],
            'default_term_days' => $validated['defaultTermDays'] === '' || $validated['defaultTermDays'] === null ? null : (int) $validated['defaultTermDays'],
            'note' => $validated['note'],
        ];

        try {
            $this->editingId === null
                ? $parties->create($data)
                : $parties->update(FinanceParty::findOrFail($this->editingId), $data);
        } catch (FinanceRuleException $e) {
            $this->addError(self::FORM_FIELDS[$e->field] ?? 'name', $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Cari oluşturuldu.' : 'Cari güncellendi.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function deactivate(int $partyId, FinancePartyService $parties): void
    {
        Gate::authorize('finance.update');

        $parties->deactivate(FinanceParty::findOrFail($partyId));
        session()->flash('status', 'Cari pasife alındı; açık kayıtları ve geçmişi korunur.');
    }

    public function activate(int $partyId, FinancePartyService $parties): void
    {
        Gate::authorize('finance.update');

        $parties->activate(FinanceParty::findOrFail($partyId));
        session()->flash('status', 'Cari aktifleştirildi.');
    }

    public function with(FinanceBalanceService $balances): array
    {
        $parties = FinanceParty::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->whereLike('name', '%'.trim($this->search).'%'))
            ->when(PartyKind::tryFrom($this->filterKind), fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByRaw("status = 'active' desc")
            ->orderBy('name')
            ->get();

        return [
            'parties' => $parties,
            'balances' => $balances->partyBalances($parties),
            'kinds' => PartyKind::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kurumlar, sigortalar ve tedarikçiler. Bakiye, açık ödeme ve tahsilat kayıtlarından hesaplanır. Hasta cari olarak açılmaz.</p>
        </div>
        @can('finance.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Cari
            </button>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari ara" class="w-full sm:w-72 border border-line rounded-md px-3 py-2 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        <select wire:model.live="filterKind" class="w-full sm:w-52 border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
            <option value="">Tüm türler</option>
            @foreach ($kinds as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Cari</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Telefon</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Alacak</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Borç</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Net</th>
                    <th class="px-3 sm:px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($parties as $party)
                    @php($balance = $balances[$party->id])
                    <tr wire:key="party-{{ $party->id }}" @class(['text-ink-muted' => ! $party->isActive()])>
                        <td class="px-3 sm:px-5 py-3">
                            <a href="{{ route('finance.parties.show', $party) }}" wire:navigate class="font-medium text-ink hover:underline">{{ $party->name }}</a>
                            <div class="text-[12px] text-ink-muted">
                                {{ $party->kind->label() }}
                                @unless ($party->isActive())
                                    <span class="ml-1 inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                                @endunless
                            </div>
                            @if ($party->phone)
                                <div class="sm:hidden text-[12px] text-ink-muted">{{ $party->phone }}</div>
                            @endif
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap text-ink-muted">{{ $party->phone ?: '—' }}</td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums {{ $balance['receivable'] > 0 ? 'text-finance-in' : 'text-ink-muted' }}">{{ $balance['receivable'] > 0 ? Money::format($balance['receivable']) : '—' }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums {{ $balance['payable'] > 0 ? 'text-finance-out' : 'text-ink-muted' }}">{{ $balance['payable'] > 0 ? Money::format($balance['payable']) : '—' }}</td>
                        <td @class(['px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-in' => $balance['net'] > 0, 'text-finance-out' => $balance['net'] < 0])>{{ Money::format($balance['net']) }}</td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap">
                            @can('finance.update')
                                <button wire:click="openForm({{ $party->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                                @if ($party->isActive())
                                    <button wire:click="deactivate({{ $party->id }})" wire:confirm="Bu cari pasife alınsın mı? Yeni kayıtta seçilemez; açık kayıtları ve geçmişi korunur." class="ml-3 text-[13px] text-ink-muted hover:text-ink hover:underline">Pasife al</button>
                                @else
                                    <button wire:click="activate({{ $party->id }})" class="ml-3 text-[13px] text-brand-600 hover:underline">Aktifleştir</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-ink-muted text-[13px]">Henüz cari yok. Anlaşmalı kurumlarını ve tedarikçilerini ekle.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" :title="$editingId === null ? 'Yeni Cari' : 'Cariyi Düzenle'" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" placeholder="Örn. ABC Sigorta, Dental Depo" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Tür</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    @foreach ($kinds as $option)
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" wire:model="kind" value="{{ $option->value }}" class="peer sr-only">
                            <span class="block text-center border border-line rounded-md px-3 py-2 text-[14px] peer-checked:border-brand-500 peer-checked:bg-brand-100 peer-checked:text-brand-600">{{ $option->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('kind') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                    <input type="text" wire:model="phone" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('phone') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                    <input type="email" wire:model="email" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Vergi no</label>
                    <input type="text" wire:model="taxNumber" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('taxNumber') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Varsayılan vade (gün)</label>
                    <input type="text" inputmode="numeric" wire:model="defaultTermDays" placeholder="30" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('defaultTermDays') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-[12px] text-ink-muted -mt-2">Fatura girerken vade tarihi boş bırakılırsa bugünden bu kadar gün sonrası alınır.</p>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Not</label>
                <textarea wire:model="note" rows="3" class="w-full border border-line rounded-md px-3 py-2 text-[14px]"></textarea>
                @error('note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
