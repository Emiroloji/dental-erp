<?php

namespace App\Domain\Reporting\Exports;

use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * @implements FromCollection<int, array{product: Product, quantity: float, value: float, level: StockLevel}>
 * @implements WithMapping<array{product: Product, quantity: float, value: float, level: StockLevel}>
 */
class StockReportExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array{product: Product, quantity: float, value: float, level: StockLevel}>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Ürün', 'Kod', 'Kategori', 'Tedarikçi', 'Mevcut Stok', 'Birim Maliyet', 'Toplam Değer', 'Seviye'];
    }

    /**
     * @param  array{product: Product, quantity: float, value: float, level: StockLevel}  $row
     * @return array<int, mixed>
     */
    public function map(mixed $row): array
    {
        return [
            $row['product']->name,
            $row['product']->code,
            $row['product']->category?->name,
            $row['product']->supplier?->name,
            $row['quantity'],
            $row['product']->purchase_price,
            $row['value'],
            $row['level']->label(),
        ];
    }
}
