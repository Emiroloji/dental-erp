<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     */
    public function scopeInBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn($this->qualifyColumn('branch_id'), $branchIds);
        }
    }

    /**
     * Yeni stok işlemine açık depolar: kendisi ve şubesi aktif.
     */
    public function scopeOperational(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), 'active')
            ->whereHas('branch', fn ($branch) => $branch->where('status', 'active'));
    }

    public function isOperational(): bool
    {
        return $this->status === 'active'
            && $this->branch()->withoutGlobalScopes()->where('status', 'active')->exists();
    }

    protected function auditOrganizationId(): ?int
    {
        return $this->branch()->withoutGlobalScopes()->value('organization_id');
    }
}
