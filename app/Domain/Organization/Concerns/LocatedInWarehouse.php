<?php

namespace App\Domain\Organization\Concerns;

use App\Domain\Organization\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/**
 * warehouse_id taşıyan modeller (lot, hareket) için şube kapsamı filtresi.
 * Kullanıcının erişebildiği şubeler User::accessibleBranchIds() ile alınır.
 */
trait LocatedInWarehouse
{
    /**
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     */
    public function scopeInBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $query->whereIn(
            $query->getModel()->qualifyColumn('warehouse_id'),
            Warehouse::query()->select('id')->whereIn('branch_id', $branchIds),
        );
    }
}
