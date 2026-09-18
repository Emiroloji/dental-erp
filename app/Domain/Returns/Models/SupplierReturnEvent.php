<?php

namespace App\Domain\Returns\Models;

use App\Domain\Returns\Support\ReturnStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İade durum geçmişi ("iade geçmişi", proje.md Bölüm 10) — append-only.
 */
class SupplierReturnEvent extends Model
{
    const UPDATED_AT = null;

    protected $table = 'return_events';

    protected $fillable = [
        'return_id',
        'status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'status' => ReturnStatus::class,
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
