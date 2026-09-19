<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\CountDifferenceReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Support\SerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $fillable = [
        'stock_count_id',
        'stock_lot_id',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'counted_serials',
        'reason',
        'note',
        'stock_movement_id',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:2',
        'counted_quantity' => 'decimal:2',
        'counted_serials' => 'array',
        'reason' => CountDifferenceReason::class,
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /**
     * Sayılan − sistem. Henüz sayılmadıysa null.
     */
    public function difference(): ?float
    {
        return $this->isCounted() ? (float) $this->counted_quantity - (float) $this->system_quantity : null;
    }

    /**
     * Miktar farkı ya da — seri takipli lotta — miktar aynı olsa bile sayılan
     * seriler ile stoktaki serilerin farklı olması (biri kayıp, biri bulunan).
     */
    public function hasDifference(): bool
    {
        return $this->isCounted() && (abs($this->difference()) > 0.0001 || $this->hasSerialDifference());
    }

    public function hasSerialDifference(): bool
    {
        if ($this->counted_serials === null) {
            return false;
        }

        $inStock = StockSerial::withoutGlobalScopes()
            ->where('lot_id', $this->stock_lot_id)
            ->where('status', SerialStatus::InStock->value)
            ->pluck('serial_no')
            ->all();

        return array_diff($inStock, $this->counted_serials) !== [] || array_diff($this->counted_serials, $inStock) !== [];
    }
}
