<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockMovementService
{
    public function __construct(private readonly DashboardMetricsService $dashboardMetrics) {}

    /**
     * @param  array{lot_no?: ?string, expiry_date?: ?string, unit_cost?: ?float}  $lotAttributes
     * @param  Model|null  $related  Girişi doğuran kayıt (ör. satın alma teslim alımı)
     */
    public function in(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        array $lotAttributes = [],
        ?User $actor = null,
        ?string $reason = null,
        ?Model $related = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Giriş miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($warehouse);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $lotAttributes, $actor, $reason, $related) {
            $lot = $this->resolveOrCreateLot($product, $warehouse, $lotAttributes);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $lot->update(['quantity' => $lot->quantity + $quantity]);

            return $this->recordMovement(
                StockMovementType::In, $lot, $quantity, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(),
            );
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
        ?StockOutReason $reasonCode = null,
    ): Collection {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Çıkış miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($warehouse);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $lot, $actor, $reason, $reasonCode) {
            if ($lot) {
                return collect([$this->withdrawFromLot($lot, $quantity, $actor, $reason, $reasonCode)]);
            }

            return $this->withdrawFefo($product, $warehouse, $quantity, $actor, $reason, $reasonCode);
        });
    }

    /**
     * Transfer gönderimi (kurallar.md Bölüm 1: "Gönderildi" anında kaynaktan
     * düşülür). Lotlar FEFO ile seçilir; her hareket transfer kaydına bağlanır.
     *
     * @return Collection<int, StockMovement>
     */
    public function transferOut(Product $product, Warehouse $warehouse, float $quantity, Model $transfer, ?User $actor = null): Collection
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Transfer miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($warehouse);

        return DB::transaction(fn () => $this->withdrawFefo(
            $product, $warehouse, $quantity, $actor, "Transfer #{$transfer->getKey()} gönderimi", null,
            StockMovementType::TransferOut, $transfer,
        ));
    }

    /**
     * Transfer teslim alımı: gönderilen lotun numarası, SKT'si ve alış fiyatı
     * hedef depoda aynen korunur — lot takibi transferde kopmaz.
     */
    public function transferIn(StockMovement $shipped, Warehouse $warehouse, Model $transfer, ?User $actor = null): StockMovement
    {
        $this->ensureOperational($warehouse);

        return DB::transaction(function () use ($shipped, $warehouse, $transfer, $actor) {
            $sourceLot = $shipped->lot;
            $quantity = abs((float) $shipped->quantity);

            $lot = $this->resolveOrCreateLot($sourceLot->product, $warehouse, [
                'lot_no' => $sourceLot->lot_no,
                'expiry_date' => $sourceLot->expiry_date?->toDateString(),
                'unit_cost' => (float) $sourceLot->unit_cost,
            ]);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $lot->update(['quantity' => $lot->quantity + $quantity]);

            return $this->recordMovement(
                StockMovementType::TransferIn, $lot, $quantity, $actor, "Transfer #{$transfer->getKey()} teslim alımı",
                relatedEntityType: $transfer->getMorphClass(), relatedEntityId: $transfer->getKey(),
            );
        });
    }

    /**
     * Tedarikçiye iade (proje.md Bölüm 10: "ayrı bir hareket türü"). İade
     * belirli bir lottan yapılır — FEFO uygulanmaz; SKT'si geçmiş lot da iade
     * edilebilir. Hareket iade kaydına bağlıdır; tedarikçi reddederse cancel()
     * ile ters kayıt yazılır.
     */
    public function returnOut(StockLot $lot, float $quantity, Model $return, ?User $actor = null, ?string $reason = null): StockMovement
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('İade miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($lot->warehouse);

        return DB::transaction(fn () => $this->withdrawFromLot(
            $lot, $quantity, $actor, $reason, StockOutReason::ReturnToSupplier,
            StockMovementType::ReturnMovement, $return,
        ));
    }

    /**
     * @param  Model|null  $related  Düzeltmeyi doğuran kayıt (ör. onaylanan stok sayımı)
     */
    public function adjust(StockLot $lot, float $countedQuantity, string $reason, ?User $actor = null, ?Model $related = null): StockMovement
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Stok düzeltmesi bir neden olmadan yapılamaz.');
        }

        if ($countedQuantity < 0) {
            throw new InvalidArgumentException('Sayılan miktar negatif olamaz.');
        }

        $this->ensureOperational($lot->warehouse);

        return DB::transaction(function () use ($lot, $countedQuantity, $reason, $actor, $related) {
            $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $delta = $countedQuantity - (float) $locked->quantity;

            $locked->update(['quantity' => $countedQuantity]);

            return $this->recordMovement(
                StockMovementType::CountAdjust, $locked, $delta, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(),
            );
        });
    }

    public function cancel(StockMovement $movement, ?User $actor = null): StockMovement
    {
        $this->ensureOperational($movement->warehouse);

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

    /**
     * kurallar.md Bölüm 4: pasif depo veya pasif şubedeki bir depo üzerinde
     * hiçbir stok işlemi (giriş, çıkış, düzeltme, iptal) yapılamaz.
     */
    private function ensureOperational(Warehouse $warehouse): void
    {
        if (! $warehouse->isOperational()) {
            throw new InactiveLocationException("\"{$warehouse->name}\" deposu veya bağlı olduğu şube pasif; bu depoda stok işlemi yapılamaz.");
        }
    }

    private function withdrawFromLot(
        StockLot $lot,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?StockOutReason $reasonCode,
        StockMovementType $type = StockMovementType::Out,
        ?Model $related = null,
    ): StockMovement {
        $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

        if ((float) $locked->quantity < $quantity) {
            throw new InsufficientStockException("Lot #{$locked->id} için yeterli stok yok.");
        }

        $locked->update(['quantity' => $locked->quantity - $quantity]);

        return $this->recordMovement(
            $type, $locked, -1 * $quantity, $actor, $reason,
            relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(), reasonCode: $reasonCode,
        );
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function withdrawFefo(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?StockOutReason $reasonCode,
        StockMovementType $type = StockMovementType::Out,
        ?Model $related = null,
    ): Collection {
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
            $movements->push($this->recordMovement(
                $type, $lot, -1 * $take, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(), reasonCode: $reasonCode,
            ));

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
        ?StockOutReason $reasonCode = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'type' => $type->value,
            'lot_id' => $lot->id,
            'warehouse_id' => $lot->warehouse_id,
            'quantity' => $quantity,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'reason_code' => $reasonCode,
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
