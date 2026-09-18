<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'status',
        'note',
        'started_by',
    ];

    protected $casts = [
        'status' => StockCountStatus::class,
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StockCountEvent::class);
    }

    public function number(): string
    {
        return 'SY-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
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
