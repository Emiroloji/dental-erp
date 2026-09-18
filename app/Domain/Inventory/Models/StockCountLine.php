<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\CountDifferenceReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
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
        'reason',
        'note',
        'stock_movement_id',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:2',
        'counted_quantity' => 'decimal:2',
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

    public function hasDifference(): bool
    {
        return $this->isCounted() && abs($this->difference()) > 0.0001;
    }
}
