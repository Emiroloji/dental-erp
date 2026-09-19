<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Assistant\Support\AssistantReport;
use App\Domain\Assistant\Support\QueryIntent;
use App\Domain\Forecasting\Services\ConsumptionForecastService;
use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Forecasting\Support\ProductForecast;
use App\Domain\Reporting\Services\MovementReportService;
use App\Domain\Reporting\Services\PurchaseReportService;
use App\Domain\Reporting\Services\StockReportService;
use App\Domain\Reporting\Services\UsageReportService;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Support\StockLevel;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Doğrulanmış sorguyu sistemin KENDİ raporlarıyla çalıştırır. Rakamların
 * tamamı buradan gelir; yapay zekâ hiçbir rakam görmez veya üretmez.
 * Tenant kapsamı ve Raporlar modülündeki şube yetkisi ekranlarla aynıdır.
 *
 * @phpstan-type Answer array{title: string, summary: array<int, string>, headings: array<int, string>, rows: array<int, array<int, mixed>>, total: int, url: string}
 */
class IntentExecutor
{
    public function __construct(
        private readonly StockReportService $stock,
        private readonly MovementReportService $movements,
        private readonly UsageReportService $usage,
        private readonly PurchaseReportService $purchasing,
        private readonly ConsumptionForecastService $forecasts,
    ) {}

    /**
     * @return Answer
     */
    public function run(QueryIntent $intent, User $user): array
    {
        $answer = match ($intent->report) {
            AssistantReport::Stock => $this->stock($intent, $user),
            AssistantReport::Movements => $this->movements($intent, $user),
            AssistantReport::Usage => $this->usage($intent, $user),
            AssistantReport::Purchasing => $this->purchasing($intent, $user),
            AssistantReport::Forecast => $this->forecast($intent, $user),
        };

        return ['title' => $intent->report->label(), ...$answer, 'url' => $this->reportUrl($intent)];
    }

    private function stock(QueryIntent $intent, User $user): array
    {
        $filters = $intent->filters();
        $severity = [StockLevel::Critical->value => 0, StockLevel::Low->value => 1, StockLevel::Normal->value => 2];

        $rows = $this->stock->rows(
            $this->stock->query([
                'category_id' => $intent->categoryId,
                'supplier_id' => $intent->supplierId,
                'search' => $intent->search,
            ])->get(),
            $intent->warehouseId,
            $filters->branchScope($user->accessibleBranchIds(Module::Reports)),
        )->sortBy([
            fn ($a, $b) => $severity[$a['level']->value] <=> $severity[$b['level']->value],
            fn ($a, $b) => $a['quantity'] <=> $b['quantity'],
        ])->values();

        return [
            'summary' => [
                "{$rows->count()} ürün",
                'Toplam stok değeri: '.Number::format($rows->sum('value'), precision: 2).' ₺',
                "Kritik: {$rows->where('level', StockLevel::Critical)->count()} · Düşük: {$rows->where('level', StockLevel::Low)->count()}",
            ],
            'headings' => ['Ürün', 'Kategori', 'Miktar', 'Birim', 'Değer (₺)', 'Seviye'],
            'rows' => $rows->take($intent->limit)->map(fn (array $row) => [
                $row['product']->name,
                $row['product']->category?->name ?? '—',
                $this->number($row['quantity']),
                $row['product']->base_unit,
                $this->number($row['value']),
                $row['level']->label(),
            ])->all(),
            'total' => $rows->count(),
        ];
    }

    private function movements(QueryIntent $intent, User $user): array
    {
        $query = fn () => $this->movements
            ->query($user->organization_id, $intent->filters(), $user->accessibleBranchIds(Module::Reports), $intent->movementType?->value)
            ->when($intent->search, fn ($query, $search) => $this->searchProducts($query, $search));

        $totals = $query()->toBase()->select([])->selectRaw('COUNT(*) as movement_count, SUM(stock_movements.quantity) as net')->first();

        return [
            'summary' => [
                $this->period($intent),
                ((int) $totals->movement_count).' hareket'.($intent->movementType ? " ({$intent->movementType->label()})" : ''),
                'Net değişim: '.$this->number((float) $totals->net),
            ],
            'headings' => ['Tarih', 'Tip', 'Ürün', 'Depo', 'Miktar', 'Personel'],
            'rows' => $this->movements->withDetails($query())->limit($intent->limit)->get()->map(fn (StockMovement $movement) => [
                $movement->created_at->format('d.m.Y H:i'),
                $movement->type->label(),
                $movement->lot->product->name,
                "{$movement->warehouse->branch->name} · {$movement->warehouse->name}",
                $this->number((float) $movement->quantity),
                $movement->actor?->name ?? '—',
            ])->all(),
            'total' => (int) $totals->movement_count,
        ];
    }

    private function usage(QueryIntent $intent, User $user): array
    {
        $rows = $this->usage->query($user->organization_id, $intent->filters(), $user->accessibleBranchIds(Module::Reports))
            ->when($intent->search, fn ($query, $search) => $this->searchProducts($query, $search))
            ->get()
            ->map(fn (object $row) => ['name' => $row->product_name, 'unit' => $row->base_unit, ...$this->usage->normalize($row)])
            ->filter(fn (array $row) => $row['usage_quantity'] > 0 || $row['other_out_quantity'] > 0)
            ->values();

        return [
            'summary' => [
                $this->period($intent),
                'Toplam kullanım maliyeti: '.Number::format($rows->sum('usage_cost'), precision: 2).' ₺',
                "{$rows->where('usage_quantity', '>', 0)->count()} üründe kullanım",
            ],
            'headings' => ['Ürün', 'Birim', 'Kullanım', 'Kullanım Maliyeti (₺)', 'Diğer Çıkışlar'],
            'rows' => $rows->take($intent->limit)->map(fn (array $row) => [
                $row['name'], $row['unit'], $this->number($row['usage_quantity']), $this->number($row['usage_cost']), $this->number($row['other_out_quantity']),
            ])->all(),
            'total' => $rows->count(),
        ];
    }

    private function purchasing(QueryIntent $intent, User $user): array
    {
        $table = $this->purchasing->table($user->organization_id, $intent->filters(), $user->accessibleBranchIds(Module::Reports));
        $totals = $table['totals'];

        return [
            'summary' => [
                $this->period($intent),
                'Sipariş tutarı: '.Number::format((float) $totals[2], precision: 2).' ₺',
                'Açık sipariş: '.Number::format((float) $totals[5], precision: 2).' ₺',
                ...($intent->search ? ['Bu rapor tedarikçi bazındadır; ürün araması uygulanmadı.'] : []),
            ],
            'headings' => $table['headings'],
            'rows' => $table['rows']->take($intent->limit)->map(fn (array $row) => array_map(fn ($value) => is_float($value) ? $this->number($value) : $value, $row))->all(),
            'total' => $table['rows']->count(),
        ];
    }

    private function forecast(QueryIntent $intent, User $user): array
    {
        $all = $this->forecasts->forecast($user->organization_id, $intent->filters(), $user->accessibleBranchIds(Module::Reports), $intent->search);
        $rows = $intent->risk ? $all->filter(fn (ProductForecast $forecast) => $forecast->risk === $intent->risk)->values() : $all;

        return [
            'summary' => [
                "{$rows->count()} ürün".($intent->risk ? " ({$intent->risk->label()})" : ''),
                'Kritik: '.$all->where('risk', ForecastRisk::Critical)->count().' · Yakında: '.$all->where('risk', ForecastRisk::Soon)->count().' · Tükendi: '.$all->where('risk', ForecastRisk::OutOfStock)->count(),
            ],
            'headings' => ['Ürün', 'Kullanılabilir Stok', 'Günlük Tüketim', 'Yeter (gün)', 'Tahmini Tükenme', 'Durum'],
            'rows' => $rows->take($intent->limit)->map(fn (ProductForecast $forecast) => [
                $forecast->product->name,
                $this->number($forecast->usableStock),
                $this->number($forecast->dailyRate),
                $forecast->daysOfCover !== null ? $this->number($forecast->daysOfCover) : '—',
                $forecast->usableStock <= 0 ? 'Tükendi' : ($forecast->stockoutDate?->format('d.m.Y') ?? '—'),
                $forecast->risk->label().($forecast->lowConfidence ? ' (düşük güven)' : ''),
            ])->all(),
            'total' => $rows->count(),
        ];
    }

    private function searchProducts(mixed $query, string $search): mixed
    {
        return $query->where(fn ($query) => $query->whereLike('products.name', "%{$search}%")->orWhereLike('products.code', "%{$search}%"));
    }

    private function period(QueryIntent $intent): string
    {
        return 'Dönem: '.$intent->from?->format('d.m.Y').' – '.$intent->to?->format('d.m.Y');
    }

    private function number(float $value): string
    {
        return Number::format($value, maxPrecision: 2);
    }

    /**
     * Rapor ekranını aynı filtrelerle açan bağlantı (rapor ekranları filtreleri URL'den okur).
     */
    private function reportUrl(QueryIntent $intent): string
    {
        return route($intent->report->routeName(), array_filter([
            'from' => $intent->from?->toDateString(),
            'to' => $intent->to?->toDateString(),
            'branchId' => $intent->branchId,
            'warehouseId' => $intent->warehouseId,
            'categoryId' => $intent->categoryId,
            'supplierId' => $intent->supplierId,
            'search' => in_array($intent->report, [AssistantReport::Stock, AssistantReport::Forecast], true) ? $intent->search : null,
            'type' => $intent->movementType?->value,
            'risk' => $intent->risk?->value,
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
