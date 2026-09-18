<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sipariş durum geçmişi — append-only.
 */
class PurchaseOrderEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'purchase_order_id',
        'status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatus::class,
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
