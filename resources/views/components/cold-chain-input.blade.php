@props(['product', 'temperature' => '', 'field' => 'temperature', 'noteField' => 'temperature_note', 'compact' => false])

{{-- Soğuk zincir ürününde girişte ölçülen sıcaklık (Aşama 26). Aralık dışı
     değerde gerekçe alanı açılır; gerekçe yoksa giriş reddedilir. --}}
@if ($product?->cold_chain)
    @php
        $value = is_numeric($temperature) ? (float) $temperature : null;
        $outOfRange = $value !== null && ! $product->temperatureInRange($value);
    @endphp
    <div @class(['rounded-md border px-3 py-2.5 text-[13px]', 'border-status-critical/40 bg-status-critical-bg' => $outOfRange, 'border-brand-500/30 bg-brand-100/40' => ! $outOfRange, $compact ? '' : 'sm:col-span-2'])>
        <label class="block text-ink-muted mb-1.5">❄ Ölçülen sıcaklık (°C) — saklama aralığı {{ $product->storageRangeLabel() }}</label>
        <input type="number" step="0.1" wire:model.live.debounce.400ms="{{ $field }}" class="w-32 border border-line rounded-md px-3 py-2 text-[14px] tabular-nums bg-surface">
        @error($field) <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
        @if ($outOfRange)
            <p class="mt-2 text-status-critical">Ölçüm saklama aralığının dışında. Ürün kabul edilmeyecekse girişi yapmayın; kabul edilecekse gerekçe yazın (Ana Klinik Sahibi'ne bildirilir).</p>
            <input type="text" wire:model="{{ $noteField }}" placeholder="Kabul gerekçesi" class="mt-1.5 w-full border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
            @error($noteField) <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
        @endif
    </div>
@endif
