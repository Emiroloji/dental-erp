@props(['options', 'dates' => true])

@php
    $select = 'border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
@endphp

<div class="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
    @if ($dates)
        <label class="flex flex-col gap-1 text-[12px] text-ink-muted">
            Başlangıç
            <input type="date" wire:model.live="from" class="{{ $select }}">
        </label>
        <label class="flex flex-col gap-1 text-[12px] text-ink-muted">
            Bitiş
            <input type="date" wire:model.live="to" class="{{ $select }}">
        </label>
    @endif
    <select wire:model.live="branchId" aria-label="Şube" class="{{ $select }} self-end">
        <option value="">Tüm Şubeler</option>
        @foreach ($options['branches'] as $branch)
            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
        @endforeach
    </select>
    <select wire:model.live="warehouseId" aria-label="Depo" class="{{ $select }} self-end">
        <option value="">Tüm Depolar</option>
        @foreach ($options['warehouses'] as $warehouse)
            <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
        @endforeach
    </select>
    <select wire:model.live="categoryId" aria-label="Kategori" class="{{ $select }}">
        <option value="">Tüm Kategoriler</option>
        @foreach ($options['categories'] as $category)
            <option value="{{ $category->id }}">{{ $category->name }}</option>
        @endforeach
    </select>
    <select wire:model.live="supplierId" aria-label="Tedarikçi" class="{{ $select }}">
        <option value="">Tüm Tedarikçiler</option>
        @foreach ($options['suppliers'] as $supplier)
            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
        @endforeach
    </select>
    {{ $slot }}
    <button type="button" wire:click="clearFilters" class="text-[13px] text-ink-muted hover:text-ink hover:underline justify-self-start self-center">Filtreleri temizle</button>
</div>
