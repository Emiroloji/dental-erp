<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use Illuminate\Support\Carbon;

/**
 * Gelişmiş rapor filtreleri (fazlar-adimlar.md Aşama 15 / proje.md Bölüm 11):
 * tarih aralığı, şube, depo, kategori, tedarikçi. Tüm rapor servisleri aynı
 * filtre nesnesini kullanır; böylece aynı filtreyle farklı raporlar tutarlı
 * sonuç verir.
 *
 * Şube kapsamı: seçilen şube, görüntüleyenin erişebildiği şubelerle
 * kesiştirilir — kapsam dışı bir şube seçilirse (ör. elle gönderilen id)
 * rapor boş döner, veri sızmaz.
 */
final class ReportFilters
{
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $branchId = null,
        public readonly ?int $warehouseId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $supplierId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $int = fn (string $key) => filled($input[$key] ?? null) ? (int) $input[$key] : null;
        $date = fn (string $key) => filled($input[$key] ?? null) ? Carbon::parse($input[$key]) : null;

        return new self(
            from: $date('from')?->startOfDay(),
            to: $date('to')?->endOfDay(),
            branchId: $int('branch_id'),
            warehouseId: $int('warehouse_id'),
            categoryId: $int('category_id'),
            supplierId: $int('supplier_id'),
        );
    }

    /**
     * Raporun uygulanacağı şubeler. null = kısıt yok (Admin, şube seçmedi).
     *
     * @param  array<int, int>|null  $accessible  Görüntüleyenin erişebildiği şubeler (null = hepsi)
     * @return array<int, int>|null
     */
    public function branchScope(?array $accessible): ?array
    {
        if ($this->branchId === null) {
            return $accessible;
        }

        return ($accessible === null || in_array($this->branchId, $accessible, true)) ? [$this->branchId] : [];
    }

    /**
     * PDF/Excel başlığında gösterilecek okunur filtre özeti.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $parts[] = 'Tarih: '.($this->from?->format('d.m.Y') ?? '…').' – '.($this->to?->format('d.m.Y') ?? '…');
        }

        if ($this->branchId) {
            $parts[] = 'Şube: '.(Branch::find($this->branchId)?->name ?? '—');
        }

        if ($this->warehouseId) {
            $parts[] = 'Depo: '.(Warehouse::whereHas('branch')->find($this->warehouseId)?->name ?? '—');
        }

        if ($this->categoryId) {
            $parts[] = 'Kategori: '.(Category::find($this->categoryId)?->name ?? '—');
        }

        if ($this->supplierId) {
            $parts[] = 'Tedarikçi: '.(Supplier::find($this->supplierId)?->name ?? '—');
        }

        return $parts === [] ? 'Filtre yok' : implode(' · ', $parts);
    }
}
