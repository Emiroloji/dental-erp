<?php

use App\Domain\Patients\Exceptions\PatientException;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Services\PatientService;
use App\Domain\Patients\Support\IdentityType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hastalar (Aşama 43): ÜTS Kullanım bildirimi için ad, soyad ve kimlik.
 * Listede kimlik numarası maskelidir; tam numara yalnızca düzenleme
 * formunda, hasta yazma yetkisiyle görünür. Arama ad/soyadda geçen ya da
 * kimlik numarasıyla tam eşleşen hastaları bulur.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $identity_type = 'tc';

    public string $identity_no = '';

    public string $birth_year = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        Gate::authorize('patients.create');

        $this->showForm = true;
    }

    public function edit(int $patientId): void
    {
        Gate::authorize('patients.update');

        $patient = Patient::findOrFail($patientId);

        $this->editingId = $patient->id;
        $this->first_name = $patient->first_name;
        $this->last_name = $patient->last_name;
        $this->identity_type = $patient->identity_type->value;
        $this->identity_no = (string) $patient->identity_no;
        $this->birth_year = (string) $patient->birth_year;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'first_name', 'last_name', 'identity_type', 'identity_no', 'birth_year']);
        $this->resetValidation();
    }

    public function save(PatientService $patients): void
    {
        Gate::authorize($this->editingId ? 'patients.update' : 'patients.create');

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'identity_type' => ['required', Rule::enum(IdentityType::class)],
            'identity_no' => ['nullable', 'string', 'max:20'],
            'birth_year' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
        ]);
        $validated['birth_year'] = filled($validated['birth_year']) ? (int) $validated['birth_year'] : null;

        try {
            $this->editingId
                ? $patients->update(Patient::findOrFail($this->editingId), $validated)
                : $patients->create($validated);
        } catch (PatientException $e) {
            $this->addError($e->field, $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Hasta kaydedildi.');
    }

    public function with(): array
    {
        return [
            'patients' => Patient::search($this->search)->orderBy('last_name')->orderBy('first_name')->paginate(25),
            'identityTypes' => IdentityType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Hastalar</h1>
            <p class="text-[14px] text-ink-muted mt-1">ÜTS kullanım bildirimi için ad, soyad ve kimlik. Kimlik numaraları şifreli saklanır, listede maskeli görünür.</p>
        </div>
        @can('patients.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Hasta
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ad, soyad ya da tam kimlik numarası" aria-label="Hasta ara" class="border border-line rounded-md px-3 py-2 text-[14px] w-full max-w-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
    </div>

    <section class="relative border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Hasta</th>
                    <th class="px-5 py-3 font-medium">Kimlik</th>
                    <th class="px-5 py-3 font-medium">Doğum yılı</th>
                    <th class="px-5 py-3 font-medium"><span class="sr-only">İşlem</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($patients as $patient)
                    <tr wire:key="patient-{{ $patient->id }}">
                        <td class="px-5 py-3"><a href="{{ route('patients.show', $patient->id) }}" class="text-ink hover:underline">{{ $patient->fullName() }}</a></td>
                        <td class="px-5 py-3 text-ink-muted whitespace-nowrap">
                            {{ $patient->maskedIdentity() }}
                            @if ($patient->identity_type->needsNumber()) <span class="text-[12px]">· {{ $patient->identity_type->label() }}</span> @endif
                        </td>
                        <td class="px-5 py-3 text-ink-muted">{{ $patient->birth_year ?? '—' }}</td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @can('patients.update')
                                <button wire:click="edit({{ $patient->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-ink-muted text-[13px]">
                            {{ trim($search) !== '' ? 'Aramayla eşleşen hasta yok. Kimlik numarasıyla aramada numaranın tamamını yazın.' : 'Henüz hasta eklenmedi. Hastaya ÜTS takipli ürün kullanıldığında Stok Çıkışı ekranından da eklenebilir.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $patients->links() }}</div>

    <x-modal :show="$showForm" :title="$editingId ? 'Hastayı Düzenle' : 'Yeni Hasta'" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            @include('pages.patients.partials.form-fields')
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kaydet</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
