<?php

namespace App\Domain\Stock\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'lot_id',
        'warehouse_id',
        'quantity',
        'actor_id',
        'reason',
        'related_entity_type',
        'related_entity_id',
    ];

    protected $casts = [
        'type' => StockMovementType::class,
        'quantity' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function relatedEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_entity_type', 'related_entity_id');
    }
}
