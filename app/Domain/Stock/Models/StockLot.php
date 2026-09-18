<?php

namespace App\Domain\Stock\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Concerns\LocatedInWarehouse;
use App\Domain\Organization\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    use LocatedInWarehouse;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'lot_no',
        'expiry_date',
        'unit_cost',
        'quantity',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'unit_cost' => 'decimal:2',
        'quantity' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'lot_id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }
}
