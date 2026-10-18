<?php

use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsRemovalService;
use App\Domain\Uts\Support\RemovalReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hasta detayı (Aşama 43): kimlik (maskeli) ve hastaya kullanılan ÜTS
 * takipli ürünler ("implant kartı"). Hasta yazma yetkisiyle bir ürün hastadan
 * çıkarılır; kullanım ÜTS'ye bildirildiyse çıkarma da bildirilir (Aşama 55).
 * Başka organizasyonun hastası 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $patientId;

    public ?int $removingId = null;

    public string $removalReason = 'DOKU_UYUMSUZLUGU';

    public string $removalNote = '';

    public string $removalDate = '';

    public function mount(int $patient): void
    {
        $this->patientId = Patient::findOrFail($patient)->id;
    }

    public function openRemoval(int $usageId): void
    {
        Gate::authorize('patients.update');

        $this->removingId = PatientUsage::where('patient_id', $this->patientId)->findOrFail($usageId)->id;
        $this->reset(['removalReason', 'removalNote']);
        $this->removalDate = today()->toDateString();
        $this->resetValidation();
    }

    public function closeRemoval(): void
    {
        $this->removingId = null;
    }

    public function remove(UtsRemovalService $removals): void
    {
        Gate::authorize('patients.update');

        $this->validate([
            'removalReason' => ['required', Rule::in(array_keys(RemovalReport::REASONS))],
            'removalNote' => [Rule::requiredIf($this->removalReason === 'DIGER'), 'nullable', 'string', 'max:50'],
            'removalDate' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'removalNote.required' => '"Diğer" gerekçesi için açıklama yazın.',
            'removalDate.before_or_equal' => 'Çıkarma tarihi ileri bir tarih olamaz.',
        ]);

        $usage = PatientUsage::where('patient_id', $this->patientId)->findOrFail($this->removingId);

        try {
            $errors = $removals->remove($usage, $this->removalReason, $this->removalNote, Carbon::parse($this->removalDate));
        } catch (UtsException $e) {
            $this->addError('removalDate', $e->getMessage());

            return;
        }

        $this->removingId = null;
        session()->flash('status', 'Ürün hastadan çıkarıldı.');

        if ($errors !== []) {
            session()->flash('warning', 'Çıkarma kaydedildi ama ÜTS\'ye gönderilemedi: '.implode(' ', $errors).' ÜTS → Gönderim Geçmişi\'nden tekrar gönderebilirsiniz.');
        }
    }

    public function with(): array
    {
        return [
            'patient' => Patient::findOrFail($this->patientId),
            'reasons' => RemovalReport::REASONS,
            'removing' => $this->removingId ? PatientUsage::with(['product', 'serial', 'lot'])->find($this->removingId) : null,
            'usages' => PatientUsage::where('patient_id', $this->patientId)
                ->with(['product', 'lot', 'serial', 'movement.warehouse.branch', 'utsNotifications'])
                ->orderByDesc('used_on')
                ->orderByDesc('id')
                ->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('patients.index') }}" class="text-[13px] text-ink-muted hover:text-ink">Hastalar</a>
        <h1 class="text-[22px] font-medium tracking-tight text-ink mt-1">{{ $patient->fullName() }}</h1>
        <p class="text-[14px] text-ink-muted mt-1">
            {{ $patient->identity_type->label() }}@if ($patient->identity_type->needsNumber()): {{ $patient->maskedIdentity() }}@endif
            @if ($patient->birth_year) <span class="ml-3">Doğum yılı {{ $patient->birth_year }}</span> @endif
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-md bg-status-warn-bg border border-status-warn/20 text-status-warn text-[13px] px-4 py-3">{{ session('warning') }}</div>
    @endif

    <section class="relative border border-line rounded-lg bg-surface overflow-x-auto">
        <h2 class="px-5 py-4 border-b border-line text-[15px] font-medium text-ink">Kullanılan ürünler</h2>
        @if ($usages->isEmpty())
            <p class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu hastaya henüz ÜTS takipli ürün kullanılmadı. Kullanım, Stok Çıkışı'nda "Klinik içi kullanım" nedeniyle hasta seçilerek kaydedilir.</p>
        @else
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Tarih</th>
                        <th class="px-5 py-3 font-medium">Ürün</th>
                        <th class="px-5 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-5 py-3 font-medium text-right">Miktar</th>
                        <th class="px-5 py-3 font-medium">Şube / Depo</th>
                        <th class="px-5 py-3 font-medium">ÜTS</th>
                        <th class="px-5 py-3"><span class="sr-only">İşlem</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($usages as $usage)
                        <tr wire:key="usage-{{ $usage->id }}" @class(['opacity-60' => $usage->cancelled_at])>
                            <td class="px-5 py-3 whitespace-nowrap">{{ $usage->used_on->format('d.m.Y') }}</td>
                            <td class="px-5 py-3">
                                {{ $usage->product->name }}
                                @if ($usage->cancelled_at) <div class="text-[12px] text-ink-muted">Stok çıkışı iptal edildi</div> @endif
                                @if ($usage->removed_on) <div class="text-[12px] text-status-warn">Çıkarıldı {{ $usage->removed_on->format('d.m.Y') }}, {{ mb_strtolower($usage->removalLabel()) }}@if ($usage->removal_note): {{ $usage->removal_note }}@endif</div> @endif
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap font-mono text-[13px]">{{ $usage->lot->lot_no ?? '—' }}@if ($usage->serial)<div class="text-ink-muted">{{ $usage->serial->serial_no }}</div>@endif</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $usage->quantity, maxPrecision: 2) }}</td>
                            <td class="px-5 py-3 text-ink-muted">{{ $usage->movement->warehouse->branch->name }} — {{ $usage->movement->warehouse->name }}</td>
                            <td class="px-5 py-3">
                                <span @class([
                                    'whitespace-nowrap inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                    'bg-status-good-bg text-status-good' => $usage->utsStatus() === 'sent',
                                    'bg-red-50 text-status-critical' => $usage->utsStatus() === 'failed',
                                    'bg-line text-ink-muted' => in_array($usage->utsStatus(), ['none', 'cancelled'], true),
                                ])>{{ $usage->utsStatusLabel() }}</span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                @can('patients.update')
                                    @if (! $usage->removed_on && ! $usage->cancelled_at)
                                        <button type="button" wire:click="openRemoval({{ $usage->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Vücuttan çıkar</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <x-modal :show="$removing !== null" title="Hastanın vücudundan çıkar" on-close="closeRemoval">
        @if ($removing)
            <form wire:submit="remove" class="space-y-4">
                <p class="text-[14px] text-ink">
                    <span class="font-medium">{{ $removing->product->name }}</span>
                    <span class="text-ink-muted font-mono text-[13px] ml-1">{{ $removing->serial?->serial_no ?? 'Lot '.$removing->lot->lot_no }}</span>,
                    takılma {{ $removing->used_on->format('d.m.Y') }}
                </p>
                <p class="text-[13px] text-ink-muted">Kullanımı ÜTS'ye bildirilmiş üründe hastanın vücudundan çıkarma bildirimi de gönderilir; ÜTS'den iptal edilebilir.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="removal-reason" class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                        <select id="removal-reason" wire:model.live="removalReason" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            @foreach ($reasons as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="removal-date" class="block text-[13px] text-ink-muted mb-1.5">Çıkarma tarihi</label>
                        <input id="removal-date" type="date" wire:model="removalDate" min="{{ $removing->used_on->toDateString() }}" max="{{ today()->toDateString() }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                        @error('removalDate') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    @if ($removalReason === 'DIGER')
                        <div class="sm:col-span-2">
                            <label for="removal-note" class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                            <input id="removal-note" type="text" maxlength="50" wire:model="removalNote" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            @error('removalNote') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">Çıkar</button>
                    <button type="button" wire:click="closeRemoval" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
