<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Services\RecurringService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formDirection = 'payable';

    public string $name = '';

    public string $amount = '';

    public string $categoryId = '';

    public string $partyId = '';

    public string $frequency = 'monthly';

    public string $startsOn = '';

    public string $endsOn = '';

    public function openForm(?int $id = null): void
    {
        Gate::authorize($id === null ? 'finance.create' : 'finance.update');

        $this->closeForm();
        $this->startsOn = FinanceCalendar::today()->toDateString();

        if ($id !== null) {
            $recurring = FinanceRecurring::findOrFail($id);
            $this->editingId = $recurring->id;
            $this->formDirection = $recurring->direction->value;
            $this->name = $recurring->name;
            $this->amount = Money::input($recurring->amount);
            $this->categoryId = (string) $recurring->category_id;
            $this->partyId = (string) $recurring->party_id;
            $this->frequency = $recurring->frequency->value;
            $this->startsOn = $recurring->starts_on->toDateString();
            $this->endsOn = (string) $recurring->ends_on?->toDateString();
        }

        $this->showForm = true;
    }

    public function updatedFormDirection(): void
    {
        // Kategori seçenekleri yöne göre süzülür; eski yönün kategorisi kalmasın.
        $this->categoryId = '';
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'formDirection', 'name', 'amount', 'categoryId', 'partyId', 'frequency', 'startsOn', 'endsOn');
        $this->resetValidation();
    }

    public function save(RecurringService $recurrings): void
    {
        Gate::authorize($this->editingId === null ? 'finance.create' : 'finance.update');

        $editing = $this->editingId === null ? null : FinanceRecurring::findOrFail($this->editingId);

        // Düzenlemede yön, sıklık ve ilk vade değişmez: sahte değerler yok sayılır.
        if ($editing !== null) {
            $this->formDirection = $editing->direction->value;
            $this->frequency = $editing->frequency->value;
            $this->startsOn = $editing->starts_on->toDateString();
        }

        $this->validate([
            'formDirection' => ['required', Rule::enum(ObligationDirection::class)],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'categoryId' => ['required', 'integer'],
            'partyId' => ['nullable', 'integer'],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['name' => 'ad', 'amount' => 'tutar', 'categoryId' => 'kategori', 'startsOn' => 'ilk vade', 'endsOn' => 'bitiş']);

        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $common = [
            'name' => $this->name,
            'amount' => $amount,
            'category' => FinanceCategory::findOrFail($this->categoryId),
            'party' => $this->partyId !== '' ? FinanceParty::findOrFail($this->partyId) : null,
            'ends_on' => $this->endsOn !== '' ? CarbonImmutable::parse($this->endsOn) : null,
        ];

        try {
            $editing === null
                ? $recurrings->create([
                    ...$common,
                    'direction' => ObligationDirection::from($this->formDirection),
                    'frequency' => RecurringFrequency::from($this->frequency),
                    'starts_on' => CarbonImmutable::parse($this->startsOn),
                ])
                : $recurrings->update($editing, $common);
        } catch (FinanceRuleException $e) {
            $message = mb_strtolower($e->getMessage());
            $field = match (true) {
                str_contains($message, 'kategori') => 'categoryId',
                str_contains($message, 'cari') => 'partyId',
                str_contains($message, 'bitiş') => 'endsOn',
                default => 'name',
            };
            $this->addError($field, $e->getMessage());

            return;
        }

        $message = $this->editingId === null ? 'Tekrarlayan kalem eklendi; ödeme kayıtları her gün 35 gün ilerisi için üretilir.' : 'Tekrarlayan kalem güncellendi; değişiklik henüz üretilmemiş dönemlere uygulanır.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function deactivate(int $id, RecurringService $recurrings): void
    {
        Gate::authorize('finance.update');

        $recurrings->deactivate(FinanceRecurring::findOrFail($id));
        session()->flash('status', 'Durduruldu; üretilmiş kayıtlar yerinde kalır.');
    }

    public function activate(int $id, RecurringService $recurrings): void
    {
        Gate::authorize('finance.update');

        $recurrings->activate(FinanceRecurring::findOrFail($id));
        session()->flash('status', 'Yeniden başlatıldı; kaçırılan dönemler geriye dönük üretilmez.');
    }

    public function with(): array
    {
        return [
            'recurrings' => FinanceRecurring::with(['category', 'party'])->orderByRaw("status = 'active' desc")->orderBy('next_due_on')->get(),
            'categories' => FinanceCategory::where('status', 'active')
                ->where('direction', ObligationDirection::tryFrom($this->formDirection)?->categoryDirection())
                ->orderBy('name')->get(),
            // Düzenlenen kalemin cari pasife alınmış olsa da seçili kalır; sessizce silinmesin.
            'parties' => FinanceParty::where(fn ($query) => $query->where('status', 'active')->orWhere('id', (int) $this->partyId))->orderBy('name')->get(),
            'frequencies' => RecurringFrequency::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1 max-w-2xl">Kira, maaş, abonelik gibi düzenli kalemler. Sistem her gün önümüzdeki 35 günün ödeme kayıtlarını Ödemeler ve Tahsilatlar'a ekler.</p>
        </div>
        @can('finance.create')
            <div class="mt-4 sm:mt-0">
                <button wire:click="openForm" class="w-full sm:w-auto inline-flex justify-center items-center gap-1.5 rounded-md px-4 py-2 text-[14px] font-medium text-white bg-panel-900 hover:bg-panel-800">+ Yeni Tekrarlayan Kalem</button>
            </div>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @php
        $weekdays = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        // Ayın günü + iyelik eki (5'i, 2'si, 3'ü ...).
        $suffix = [1 => 'i', 'si', 'ü', 'ü', 'i', 'sı', 'si', 'i', 'u', 'u', 'i', 'si', 'ü', 'ü', 'i', 'sı', 'si', 'i', 'u', 'si', 'i', 'si', 'ü', 'ü', 'i', 'sı', 'si', 'i', 'u', 'u', 'i'];
        $todayDate = FinanceCalendar::today()->toDateString();
    @endphp

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Ad</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Sıklık</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Tutar</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Sonraki vade</th>
                    <th class="px-3 sm:px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($recurrings as $recurring)
                    @php
                        $isPayable = $recurring->direction === ObligationDirection::Payable;
                        $ended = $recurring->ends_on !== null && $recurring->next_due_on->toDateString() > $recurring->ends_on->toDateString();
                        $active = $recurring->isActive() && ! $ended;
                        $schedule = match ($recurring->frequency) {
                            RecurringFrequency::Weekly => 'her '.$weekdays[$recurring->day - 1],
                            RecurringFrequency::Monthly => "her ayın {$recurring->day}'".$suffix[$recurring->day],
                            RecurringFrequency::Yearly => "her yıl {$recurring->starts_on->day} ".$months[$recurring->starts_on->month],
                        };
                        $next = $ended ? 'Bitti' : (! $recurring->isActive() ? 'Durduruldu' : $recurring->next_due_on->format('d.m.Y'));
                    @endphp
                    <tr wire:key="recurring-{{ $recurring->id }}" @class(['text-ink-muted' => ! $active])>
                        <td class="px-3 sm:px-5 py-3 align-top">
                            <div class="font-medium {{ $active ? 'text-ink' : '' }}">{{ $recurring->name }}</div>
                            <div class="text-[12px] text-ink-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                                @if ($recurring->party)
                                    <a href="{{ route('finance.parties.show', $recurring->party) }}" wire:navigate class="hover:underline hover:text-ink">{{ $recurring->party->name }}</a>
                                @endif
                                @if ($recurring->category)
                                    <span class="inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $recurring->category->name }}</span>
                                @endif
                                <span class="sm:hidden">{{ $recurring->frequency->label() }}, {{ $schedule }}</span>
                                <span class="sm:hidden {{ $active ? '' : 'font-medium' }}">· {{ $active ? 'sonraki '.$next : $next }}</span>
                            </div>
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 align-top">
                            <div>{{ $recurring->frequency->label() }}</div>
                            <div class="text-[12px] text-ink-muted">{{ $schedule }}</div>
                        </td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium align-top {{ $active ? ($isPayable ? 'text-finance-out' : 'text-finance-in') : '' }}">{{ Money::format($recurring->amount) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap tabular-nums align-top">
                            {{ $next }}
                            @if ($recurring->ends_on && ! $ended)
                                <div class="text-[12px] text-ink-muted">{{ $recurring->ends_on->format('d.m.Y') }} tarihine kadar</div>
                            @endif
                        </td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap align-top">
                            @can('finance.update')
                                <button wire:click="openForm({{ $recurring->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                                @if ($recurring->isActive() && ! $ended)
                                    <button wire:click="deactivate({{ $recurring->id }})" wire:confirm="Bu kalem durdurulsun mu? Yeni ödeme kaydı üretilmez; üretilmiş kayıtlar yerinde kalır." class="ml-3 text-[13px] text-ink-muted hover:text-ink hover:underline">Durdur</button>
                                @elseif (! $recurring->isActive() && ! $ended)
                                    <button wire:click="activate({{ $recurring->id }})" class="ml-3 text-[13px] text-brand-600 hover:underline">Yeniden başlat</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-ink-muted text-[13px]">Henüz tekrarlayan kalem yok. Kira ve maaş gibi her ay ödediklerini ekle; unutma riski kalmasın.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" :title="$editingId === null ? 'Yeni Tekrarlayan Kalem' : 'Kalemi Düzenle'" on-close="closeForm">
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
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                    <input type="text" wire:model="name" placeholder="Örn. Kira, Maaş, Yazılım aboneliği" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tutar</label>
                    <input type="text" inputmode="decimal" wire:model="amount" placeholder="0,00" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('amount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Kategori</label>
                    <select wire:model="categoryId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">Seç</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Cari (isteğe bağlı)</label>
                    <select wire:model="partyId" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                        <option value="">—</option>
                        @foreach ($parties as $party)
                            <option value="{{ $party->id }}">{{ $party->name }}</option>
                        @endforeach
                    </select>
                    @error('partyId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Sıklık</label>
                    @if ($editingId === null)
                        <select wire:model="frequency" class="w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                            @foreach ($frequencies as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        @error('frequency') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    @else
                        <div class="border border-line rounded-md px-3 py-2 text-[14px] bg-canvas text-ink-muted">{{ RecurringFrequency::from($frequency)->label() }}</div>
                    @endif
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">İlk vade</label>
                    @if ($editingId === null)
                        <input type="date" wire:model="startsOn" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                        @error('startsOn') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    @else
                        <div class="border border-line rounded-md px-3 py-2 text-[14px] bg-canvas text-ink-muted tabular-nums">{{ CarbonImmutable::parse($startsOn)->format('d.m.Y') }}</div>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[13px] text-ink-muted mb-1.5">Bitiş (isteğe bağlı)</label>
                    <input type="date" wire:model="endsOn" class="w-full sm:w-1/2 border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('endsOn') <span class="block text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            @if ($editingId === null)
                <p class="text-[12px] text-ink-muted">İlk vade 35 günden eskiyse, daha eski dönemler üretilmez; bugüne yakın ilk dönemden başlanır.</p>
            @else
                <p class="text-[12px] text-ink-muted">Yön, sıklık ve ilk vade sonradan değişmez. Gerekirse bu kalemi durdurup yenisini ekle.</p>
            @endif
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
