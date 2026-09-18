<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'warehouse_id',
        'status',
        'expected_delivery_date',
        'ordered_at',
        'note',
        'requested_by',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatus::class,
        'expected_delivery_date' => 'date',
        'ordered_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchaseOrderEvent::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function number(): string
    {
        return 'SA-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function total(): float
    {
        return (float) $this->lines->sum(fn (PurchaseOrderLine $line) => (float) $line->quantity * (float) $line->unit_price);
    }

    /**
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     */
    public function scopeInBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn('warehouse_id', Warehouse::query()->select('id')->whereIn('branch_id', $branchIds));
        }
    }
}
