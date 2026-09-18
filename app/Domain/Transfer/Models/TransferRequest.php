<?php

namespace App\Domain\Transfer\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TransferRequest extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'quantity',
        'status',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'status' => TransferStatus::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransferRequestEvent::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'relatedEntity', 'related_entity_type', 'related_entity_id');
    }

    /**
     * Kaynağı veya hedefi verilen şubelerden birinde olan transferler.
     *
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     */
    public function scopeInBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $warehouseIds = Warehouse::query()->select('id')->whereIn('branch_id', $branchIds);

        $query->where(fn ($query) => $query
            ->whereIn('from_warehouse_id', $warehouseIds)
            ->orWhereIn('to_warehouse_id', $warehouseIds));
    }
}
