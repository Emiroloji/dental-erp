<?php

use App\Domain\Patients\Models\Patient;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hasta detayı (Aşama 43): kimlik (maskeli) ve hastaya kullanılan ÜTS
 * takipli ürünler. Başka organizasyonun hastası 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $patientId;

    public function mount(int $patient): void
    {
        $this->patientId = Patient::findOrFail($patient)->id;
    }

    public function with(): array
    {
        return [
            'patient' => Patient::findOrFail($this->patientId),
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

    <section class="border border-line rounded-lg bg-surface">
        <h2 class="px-5 py-4 border-b border-line text-[15px] font-medium text-ink">Kullanılan ürünler</h2>
        <p class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu hastaya henüz ÜTS takipli ürün kullanılmadı.</p>
    </section>
</div>
