@props(['product'])

{{-- İlaç/medikal ürün işaretleri (Aşama 26): listelerde ürün adının yanında. --}}
@if ($product->cold_chain || $product->is_controlled || $product->tracks_serials)
    <span class="inline-flex flex-wrap gap-1 ml-1 align-middle">
        @if ($product->cold_chain)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-brand-100 text-brand-600" title="Soğuk zincir: {{ $product->storageRangeLabel() }}">❄ {{ $product->storageRangeLabel() }}</span>
        @endif
        @if ($product->is_controlled)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-status-critical-bg text-status-critical" title="Kontrollü ürün: her çıkışta açıklama zorunlu">Kontrollü</span>
        @endif
        @if ($product->tracks_serials)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-line text-ink-muted" title="Birim bazında seri numarası takibi">Seri takipli</span>
        @endif
    </span>
@endif
