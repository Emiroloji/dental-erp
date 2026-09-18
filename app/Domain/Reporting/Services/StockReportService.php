<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Reporting\Exports\StockReportExport;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockLevelService;
use App\Domain\Stock\Support\StockLevel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * fazlar-adimlar.md Aşama 7: "Basit rapor ekranı" — ürün, stok ve maliyet
 * raporlarını (proje.md Bölüm 11) tek bir ekranda birleştirir. Satın alma ve
 * detaylı kullanım raporları Purchasing domain'i kurulmadan (Aşama 12)
 * anlamlı değil, bu yüzden kapsam dışında bırakıldı.
 */
class StockReportService
{
    public function __construct(private readonly StockLevelService $levels) {}

    /**
     * Filtrelenmiş temel ürün sorgusu — ekran bunu sayfalar (mimari.md Bölüm 6:
     * "sayfalama her yerde zorunlu"), dışa aktarım ise tamamını (->get()) alır.
     *
     * @param  array{category_id?: int|string|null, supplier_id?: int|string|null, search?: ?string}  $filters
     * @return Builder<Product>
     */
    public function query(array $filters): Builder
    {
        return Product::query()
            ->with(['category', 'supplier'])
            ->where('status', 'active')
            ->when($filters['category_id'] ?? null, fn ($query, $value) => $query->where('category_id', $value))
            ->when($filters['supplier_id'] ?? null, fn ($query, $value) => $query->where('supplier_id', $value))
            ->when($filters['search'] ?? null, function ($query, $value) {
                $query->where(function ($query) use ($value) {
                    $query->whereLike('name', "%{$value}%")->orWhereLike('code', "%{$value}%");
                });
            })
            ->orderBy('name');
    }

    /**
     * Bir ürün koleksiyonunu (sayfalanmış ya da tam) rapor satırlarına çevirir:
     * mevcut miktar, toplam değer ve uyarı seviyesi eklenir.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>|null  $branchIds  Kullanıcının erişebildiği şubeler (null = kısıt yok)
     * @return Collection<int, array{product: Product, quantity: float, value: float, level: StockLevel}>
     */
    public function rows(Collection $products, int|string|null $warehouseId = null, ?array $branchIds = null): Collection
    {
        return $products->map(function (Product $product) use ($warehouseId, $branchIds) {
            $lots = StockLot::where('product_id', $product->id)
                ->inBranches($branchIds)
                ->where('quantity', '>', 0)
                ->when($warehouseId, fn ($query, $value) => $query->where('warehouse_id', $value))
                ->get();

            // Seviye ürünün tüm depolardaki toplam stoğuna göre hesaplanır (bkz.
            // StockLevelService) — depo filtresi yalnızca gösterilen miktarı ve
            // değeri daraltır, ürünün genel uyarı seviyesini değiştirmez. Depo
            // filtresiyle hiç stoğu olmayan bir ürün 0 miktarla listelenir; bu,
            // "bu depoda hiç yok" bilgisini taşıdığı için satır gizlenmez —
            // aksi halde sayfa üstü sayfalama sayıları tutmazdı.
            $level = $this->levels->assess($product)['level'];

            return [
                'product' => $product,
                'quantity' => (float) $lots->sum('quantity'),
                'value' => (float) $lots->sum(fn (StockLot $lot) => $lot->quantity * $lot->unit_cost),
                'level' => $level,
            ];
        });
    }

    /**
     * @param  array{category_id?: int|string|null, supplier_id?: int|string|null, warehouse_id?: int|string|null, search?: ?string}  $filters
     * @param  array<int, int>|null  $branchIds
     */
    public function exportExcel(array $filters, ?array $branchIds = null): BinaryFileResponse
    {
        $rows = $this->rows($this->query($filters)->get(), $filters['warehouse_id'] ?? null, $branchIds);

        return Excel::download(new StockReportExport($rows), 'stok-raporu.xlsx');
    }

    /**
     * @param  array{category_id?: int|string|null, supplier_id?: int|string|null, warehouse_id?: int|string|null, search?: ?string}  $filters
     * @param  array<int, int>|null  $branchIds
     */
    public function exportPdf(array $filters, ?array $branchIds = null): Response
    {
        $rows = $this->rows($this->query($filters)->get(), $filters['warehouse_id'] ?? null, $branchIds);

        return Pdf::loadView('reports.stock-pdf', ['rows' => $rows])
            ->setPaper('a4', 'landscape')
            ->download('stok-raporu.pdf');
    }
}
