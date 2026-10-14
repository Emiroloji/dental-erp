<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Services\FinanceAccountService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    /** Domain hata alanı → bu formdaki özellik. */
    private const FORM_FIELDS = ['opening_date' => 'openingDate'];

    public bool $showForm = false;

    public string $name = '';

    public string $type = 'cash';

    public string $iban = '';

    public string $openingBalance = '0';

    public string $openingDate = '';

    public function openForm(): void
    {
        Gate::authorize('finance.create');

        $this->openingDate = FinanceCalendar::today()->toDateString();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('name', 'type', 'iban', 'openingBalance', 'openingDate');
        $this->resetValidation();
    }

    public function save(FinanceAccountService $accounts): void
    {
        Gate::authorize('finance.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'iban' => ['nullable', 'string', 'max:40'],
            'openingBalance' => ['required', 'string'],
            'openingDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $openingBalance = Money::parse($validated['openingBalance']);
        } catch (\InvalidArgumentException $e) {
            $this->addError('openingBalance', $e->getMessage());

            return;
        }

        try {
            $accounts->create([
                'name' => $validated['name'],
                'type' => AccountType::from($validated['type']),
                'iban' => $validated['iban'],
                'opening_balance' => $openingBalance,
                'opening_date' => CarbonImmutable::parse($validated['openingDate']),
            ]);
        } catch (FinanceRuleException $e) {
            $this->addError(self::FORM_FIELDS[$e->field] ?? 'openingDate', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Hesap oluşturuldu.');
    }

    public function deactivate(int $accountId, FinanceAccountService $accounts): void
    {
        Gate::authorize('finance.update');

        $accounts->deactivate(FinanceAccount::findOrFail($accountId));

        session()->flash('status', 'Hesap pasife alındı; geçmiş hareketleri korunur.');
    }

    public function activate(int $accountId, FinanceAccountService $accounts): void
    {
        Gate::authorize('finance.update');

        $accounts->activate(FinanceAccount::findOrFail($accountId));

        session()->flash('status', 'Hesap yeniden aktifleştirildi.');
    }

    public function with(FinanceBalanceService $balances): array
    {
        $accounts = FinanceAccount::orderByRaw("status = 'active' desc")->orderBy('name')->get();
        $balanceMap = $balances->balances($accounts);

        return [
            'accounts' => $accounts,
            'balances' => $balanceMap,
            'total' => $accounts->where('status', 'active')->sum(fn ($account) => $balanceMap[$account->id]),
            'types' => AccountType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kasa, banka ve POS hesapların. Bakiye, açılış bakiyesi ve girilen hareketlerden hesaplanır.</p>
        </div>
        @can('finance.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Hesap
            </button>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-6 flex items-baseline gap-3">
        <span class="text-[13px] text-ink-muted">Toplam nakit (aktif hesaplar)</span>
        <span @class(['text-[22px] font-medium tabular-nums', 'text-ink' => $total >= 0, 'text-finance-out' => $total < 0])>{{ \App\Domain\Finance\Support\Money::format($total) }}</span>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Hesap</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Tür</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Açılış</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Bakiye</th>
                    <th class="px-3 sm:px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($accounts as $account)
                    <tr wire:key="account-{{ $account->id }}" @class(['text-ink-muted' => ! $account->isActive()])>
                        <td class="px-3 sm:px-5 py-3">
                            <a href="{{ route('finance.accounts.show', $account) }}" wire:navigate class="font-medium hover:underline">{{ $account->name }}</a>
                            @if ($account->iban)
                                <div class="text-[12px] text-ink-muted font-mono">{{ $account->iban }}</div>
                            @endif
                            <div class="sm:hidden text-[12px] text-ink-muted">
                                {{ $account->type->label() }}
                                @unless ($account->isActive())
                                    · Pasif
                                @endunless
                            </div>
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap">
                            {{ $account->type->label() }}
                            @unless ($account->isActive())
                                <span class="ml-1 inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                            @endunless
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap text-ink-muted">{{ $account->opening_date->format('d.m.Y') }}</td>
                        <td @class(['px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-out' => $balances[$account->id] < 0])>
                            {{ \App\Domain\Finance\Support\Money::format($balances[$account->id]) }}
                        </td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap">
                            @can('finance.update')
                                @if ($account->isActive())
                                    <button wire:click="deactivate({{ $account->id }})" wire:confirm="Bu hesap pasife alınsın mı? Yeni hareket yazılamaz, geçmişi korunur; bakiyesi artık Toplam nakit'e sayılmaz." class="text-[13px] text-ink-muted hover:text-ink hover:underline">Pasife al</button>
                                @else
                                    <button wire:click="activate({{ $account->id }})" class="text-[13px] text-brand-600 hover:underline">Aktifleştir</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-ink-muted text-[13px]">Henüz hesap yok. Önce bir kasa veya banka hesabı tanımla.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" title="Yeni Hesap" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" placeholder="Örn. Merkez Kasa, Ziraat TL" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Tür</label>
                <select wire:model.live="type" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($type === 'bank')
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">IBAN (isteğe bağlı)</label>
                    <input type="text" wire:model="iban" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono">
                    @error('iban') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açılış bakiyesi (₺)</label>
                    <input type="text" inputmode="decimal" wire:model="openingBalance" placeholder="10.000,00" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('openingBalance') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    <p class="text-[12px] text-ink-muted mt-1">Ek hesaptaysa eksi yaz (örn. -2.500).</p>
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açılış tarihi</label>
                    <input type="date" wire:model="openingDate" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('openingDate') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-[12px] text-ink-muted">Açılış bakiyesi ve tarihi sonradan değiştirilemez; fark çıkarsa mutabakatla düzeltilir.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
