<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Support\StockCountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sayım durum geçmişi — append-only.
 */
class StockCountEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'stock_count_id',
        'status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'status' => StockCountStatus::class,
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
