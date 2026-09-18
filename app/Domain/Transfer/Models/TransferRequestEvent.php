<?php

namespace App\Domain\Transfer\Models;

use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transfer durum geçmişi — append-only.
 */
class TransferRequestEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'transfer_request_id',
        'status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'status' => TransferStatus::class,
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
