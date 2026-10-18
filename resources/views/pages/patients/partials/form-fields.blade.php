{{-- Hasta formu alanları: Hastalar ekranı ve Stok Çıkışı'ndaki "Yeni hasta" ortak kullanır. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label for="patient-first-name" class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
        <input id="patient-first-name" type="text" wire:model="first_name" maxlength="50" autocomplete="off" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        @error('first_name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
    </div>
    <div>
        <label for="patient-last-name" class="block text-[13px] text-ink-muted mb-1.5">Soyad</label>
        <input id="patient-last-name" type="text" wire:model="last_name" maxlength="50" autocomplete="off" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        @error('last_name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
    </div>
    <div>
        <label for="patient-identity-type" class="block text-[13px] text-ink-muted mb-1.5">Kimlik türü</label>
        <select id="patient-identity-type" wire:model.live="identity_type" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            @foreach (\App\Domain\Patients\Support\IdentityType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    @if ($identity_type !== 'unknown')
        <div>
            <label for="patient-identity-no" class="block text-[13px] text-ink-muted mb-1.5">Kimlik numarası</label>
            <input id="patient-identity-no" type="text" wire:model="identity_no" maxlength="20" autocomplete="off" inputmode="{{ $identity_type === 'passport' ? 'text' : 'numeric' }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            @error('identity_no') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
        </div>
    @endif
    <div>
        <label for="patient-birth-year" class="block text-[13px] text-ink-muted mb-1.5">Doğum yılı <span class="text-ink-muted">(isteğe bağlı)</span></label>
        <input id="patient-birth-year" type="number" wire:model="birth_year" min="1900" max="{{ now()->year }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        @error('birth_year') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
    </div>
</div>
