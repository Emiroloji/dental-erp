@props(['product', 'field' => 'serialsText', 'value' => '', 'compact' => false])

{{-- Seri takipli üründe girilen/okutulan seri numaraları (Aşama 26). Miktar,
     seri sayısından hesaplanır. --}}
@if ($product?->tracks_serials)
    @php
        try {
            $count = count(\App\Domain\Stock\Services\SerialRegistry::parseList($value));
        } catch (\App\Domain\Stock\Exceptions\SerialException) {
            $count = null;
        }
    @endphp
    <div @class(['rounded-md border border-line bg-canvas px-3 py-2.5 text-[13px]', $compact ? '' : 'sm:col-span-2'])>
        <label class="block text-ink-muted mb-1.5">Seri numaraları — her satıra bir seri (okuyucuyla art arda okutabilirsiniz)</label>
        <textarea wire:model.live.debounce.400ms="{{ $field }}" rows="3" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono bg-surface"></textarea>
        <p class="mt-1 text-ink-muted">{{ $count === null ? 'Tekrar eden seri var.' : "{$count} seri = {$count} {$product->base_unit}" }}</p>
        @error($field) <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
    </div>
@endif
