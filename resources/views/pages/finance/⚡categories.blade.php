<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Services\FinanceCategoryService;
use App\Domain\Finance\Support\CategoryDirection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $direction = 'expense';

    public function openForm(): void
    {
        Gate::authorize('finance.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('name', 'direction');
        $this->resetValidation();
    }

    public function save(FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::enum(CategoryDirection::class)],
        ]);

        try {
            $categories->create($validated['name'], CategoryDirection::from($validated['direction']));
        } catch (FinanceRuleException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Kategori oluşturuldu.');
    }

    public function addSuggested(FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.create');

        $added = $categories->addSuggested();

        session()->flash('status', $added > 0 ? "{$added} önerilen kategori eklendi." : 'Önerilen kategorilerin hepsi zaten var.');
    }

    public function deactivate(int $categoryId, FinanceCategoryService $categories): void
    {
        Gate::authorize('finance.update');

        $categories->deactivate(FinanceCategory::findOrFail($categoryId));

        session()->flash('status', 'Kategori pasife alındı; geçmiş hareketlerde görünmeye devam eder.');
    }

    public function with(): array
    {
        $all = FinanceCategory::orderByRaw("status = 'active' desc")->orderBy('name')->get();

        return [
            'groups' => [
                CategoryDirection::Income->label() => $all->where('direction', CategoryDirection::Income),
                CategoryDirection::Expense->label() => $all->where('direction', CategoryDirection::Expense),
            ],
            'isEmpty' => $all->isEmpty(),
            'directions' => CategoryDirection::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1">Gelir ve gider kategorileri. Raporlar bu kategorilere göre toplanır.</p>
        </div>
        @can('finance.create')
            <div class="mt-4 sm:mt-0 flex items-center gap-2">
                <button wire:click="addSuggested" class="inline-flex items-center border border-line bg-surface rounded-md px-4 py-2 text-[14px] text-ink hover:bg-canvas transition-colors">Önerilenleri ekle</button>
                <button wire:click="openForm" class="inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    Yeni Kategori
                </button>
            </div>
        @endcan
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if ($isEmpty)
        <div class="border border-dashed border-line rounded-lg bg-surface px-6 py-10 text-center">
            <p class="text-[14px] text-ink">Henüz kategori yok.</p>
            <p class="text-[13px] text-ink-muted mt-1">Kira, Maaş, Malzeme, Hasılat gibi önerilen kategorilerle başlayabilir, sonra kendi kategorilerini ekleyebilirsin.</p>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach ($groups as $label => $categories)
                <section class="border border-line rounded-lg bg-surface">
                    <h2 class="px-5 py-3 border-b border-line text-[13px] font-medium text-ink-muted">{{ $label }} kategorileri</h2>
                    <ul class="divide-y divide-line">
                        @forelse ($categories as $category)
                            <li wire:key="category-{{ $category->id }}" class="px-5 py-3 flex items-center justify-between text-[14px]">
                                <span @class(['text-ink-muted' => ! $category->isActive()])>
                                    {{ $category->name }}
                                    @unless ($category->isActive())
                                        <span class="ml-1 inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                                    @endunless
                                </span>
                                @can('finance.update')
                                    @if ($category->isActive())
                                        <button wire:click="deactivate({{ $category->id }})" wire:confirm="Bu kategori pasife alınsın mı?" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Pasife al</button>
                                    @endif
                                @endcan
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-[13px] text-ink-muted">Bu türde kategori yok.</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    <x-modal :show="$showForm" title="Yeni Kategori" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Tür</label>
                <div class="flex gap-2">
                    @foreach ($directions as $option)
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" wire:model="direction" value="{{ $option->value }}" class="peer sr-only">
                            <span class="block text-center border border-line rounded-md px-3 py-2 text-[14px] peer-checked:border-brand-500 peer-checked:bg-brand-100 peer-checked:text-brand-600">{{ $option->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
