<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockMovementService
{
    public function __construct(private readonly DashboardMetricsService $dashboardMetrics) {}

    /**
     * @param  array{lot_no?: ?string, expiry_date?: ?string, unit_cost?: ?float}  $lotAttributes
     */
    public function in(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        array $lotAttributes = [],
        ?User $actor = null,
        ?string $reason = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Giriş miktarı sıfırdan büyük olmalıdır.');
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $lotAttributes, $actor, $reason) {
            $lot = $this->resolveOrCreateLot($product, $warehouse, $lotAttributes);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $lot->update(['quantity' => $lot->quantity + $quantity]);

            return $this->recordMovement(StockMovementType::In, $lot, $quantity, $actor, $reason);
        });
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function out(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?StockLot $lot = null,
        ?User $actor = null,
        ?string $reason = null,
    ): Collection {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Çıkış miktarı sıfırdan büyük olmalıdır.');
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $lot, $actor, $reason) {
            if ($lot) {
                return collect([$this->withdrawFromLot($lot, $quantity, $actor, $reason)]);
            }

            return $this->withdrawFefo($product, $warehouse, $quantity, $actor, $reason);
        });
    }

    public function adjust(StockLot $lot, float $countedQuantity, string $reason, ?User $actor = null): StockMovement
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Stok düzeltmesi bir neden olmadan yapılamaz.');
        }

        if ($countedQuantity < 0) {
            throw new InvalidArgumentException('Sayılan miktar negatif olamaz.');
        }

        return DB::transaction(function () use ($lot, $countedQuantity, $reason, $actor) {
            $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $delta = $countedQuantity - (float) $locked->quantity;

            $locked->update(['quantity' => $countedQuantity]);

            return $this->recordMovement(StockMovementType::CountAdjust, $locked, $delta, $actor, $reason);
        });
    }

    public function cancel(StockMovement $movement, ?User $actor = null): StockMovement
    {
        return DB::transaction(function () use ($movement, $actor) {
            $lot = StockLot::whereKey($movement->lot_id)->lockForUpdate()->firstOrFail();

            $reverseDelta = -1 * (float) $movement->quantity;
            $newQuantity = (float) $lot->quantity + $reverseDelta;

            if ($newQuantity < 0) {
                throw new InsufficientStockException('Bu hareket iptal edilirse stok negatife düşer.');
            }

            $lot->update(['quantity' => $newQuantity]);

            return $this->recordMovement(
                StockMovementType::Cancel,
                $lot,
                $reverseDelta,
                $actor,
                "İptal: #{$movement->id} numaralı hareket",
                relatedEntityType: StockMovement::class,
                relatedEntityId: $movement->id,
            );
        });
    }

    private function withdrawFromLot(StockLot $lot, float $quantity, ?User $actor, ?string $reason): StockMovement
    {
        $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

        if ((float) $locked->quantity < $quantity) {
            throw new InsufficientStockException("Lot #{$locked->id} için yeterli stok yok.");
        }

        $locked->update(['quantity' => $locked->quantity - $quantity]);

        return $this->recordMovement(StockMovementType::Out, $locked, -1 * $quantity, $actor, $reason);
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function withdrawFefo(Product $product, Warehouse $warehouse, float $quantity, ?User $actor, ?string $reason): Collection
    {
        $lots = StockLot::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = $lots->sum('quantity');

        if ($available < $quantity) {
            throw new InsufficientStockException("Yeterli stok yok: mevcut {$available}, talep edilen {$quantity}.");
        }

        $remaining = $quantity;
        $movements = collect();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->quantity, $remaining);
            $lot->update(['quantity' => $lot->quantity - $take]);
            $movements->push($this->recordMovement(StockMovementType::Out, $lot, -1 * $take, $actor, $reason));

            $remaining -= $take;
        }

        return $movements;
    }

    /**
     * @param  array{lot_no?: ?string, expiry_date?: ?string, unit_cost?: ?float}  $attributes
     */
    private function resolveOrCreateLot(Product $product, Warehouse $warehouse, array $attributes): StockLot
    {
        if (filled($attributes['lot_no'] ?? null)) {
            $existing = StockLot::where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('lot_no', $attributes['lot_no'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'lot_no' => $attributes['lot_no'] ?? null,
            'expiry_date' => $attributes['expiry_date'] ?? null,
            'unit_cost' => $attributes['unit_cost'] ?? 0,
            'quantity' => 0,
        ]);
    }

    private function recordMovement(
        StockMovementType $type,
        StockLot $lot,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'type' => $type->value,
            'lot_id' => $lot->id,
            'warehouse_id' => $lot->warehouse_id,
            'quantity' => $quantity,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id' => $relatedEntityId,
        ]);

        // mimari.md Bölüm 6: her stok hareketinde ilgili dashboard cache anahtarı
        // geçersiz kılınır. Bu, in()/out()/adjust()/cancel()'in hepsinin geçtiği
        // tek nokta olduğu için burada — dört ayrı yerde tekrar etmeye gerek yok.
        $this->dashboardMetrics->forget($lot->product->organization_id);

        return $movement;
    }
}
