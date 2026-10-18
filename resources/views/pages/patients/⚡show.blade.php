<?php

use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hasta detayı (Aşama 43): kimlik (maskeli) ve hastaya kullanılan ÜTS
 * takipli ürünler ("implant kartı"). Başka organizasyonun hastası 404 döner.
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
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($usages as $usage)
                        <tr wire:key="usage-{{ $usage->id }}" @class(['opacity-60' => $usage->cancelled_at])>
                            <td class="px-5 py-3 whitespace-nowrap">{{ $usage->used_on->format('d.m.Y') }}</td>
                            <td class="px-5 py-3">
                                {{ $usage->product->name }}
                                @if ($usage->cancelled_at) <div class="text-[12px] text-ink-muted">Stok çıkışı iptal edildi</div> @endif
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
