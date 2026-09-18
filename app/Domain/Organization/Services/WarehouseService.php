<?php

namespace App\Domain\Organization\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Exceptions\LocationRuleException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    /**
     * @param  array{name: string, description?: ?string}  $attributes
     * @param  array<int, int>  $managerIds
     */
    public function create(Branch $branch, array $attributes, array $managerIds = []): Warehouse
    {
        if ($branch->status !== 'active') {
            throw new LocationRuleException('Pasif bir şubeye depo eklenemez.');
        }

        return DB::transaction(function () use ($branch, $attributes, $managerIds) {
            $warehouse = Warehouse::create([
                'branch_id' => $branch->id,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                // Şubenin ilk deposu kendiliğinden varsayılan olur.
                'is_default' => ! $branch->warehouses()->exists(),
                'status' => 'active',
            ]);

            $this->assignManagers($warehouse, $managerIds);

            return $warehouse;
        });
    }

    /**
     * @param  array{name: string, description?: ?string}  $attributes
     * @param  array<int, int>  $managerIds
     */
    public function update(Warehouse $warehouse, array $attributes, array $managerIds = []): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $attributes, $managerIds) {
            $warehouse->update([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
            ]);

            $this->assignManagers($warehouse, $managerIds);

            return $warehouse;
        });
    }

    public function makeDefault(Warehouse $warehouse): Warehouse
    {
        if (! $warehouse->isOperational()) {
            throw new LocationRuleException('Pasif bir depo varsayılan yapılamaz.');
        }

        return DB::transaction(function () use ($warehouse) {
            Warehouse::where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->id)
                ->where('is_default', true)
                ->get()
                ->each(fn (Warehouse $other) => $other->update(['is_default' => false]));

            $warehouse->update(['is_default' => true]);

            return $warehouse;
        });
    }

    /**
     * Pasif depoda stok işlemi yapılamaz (kurallar.md Bölüm 4); bu yüzden stoğu
     * olan depo ve şubenin varsayılan deposu pasifleştirilemez.
     */
    public function deactivate(Warehouse $warehouse): Warehouse
    {
        if ($warehouse->is_default) {
            throw new LocationRuleException("\"{$warehouse->name}\" şubenin varsayılan deposu; önce başka bir depoyu varsayılan yapın.");
        }

        if (StockLot::where('warehouse_id', $warehouse->id)->where('quantity', '>', 0)->exists()) {
            throw new LocationRuleException("\"{$warehouse->name}\" deposunda stok var; önce stoğu başka bir depoya aktarın veya sıfırlayın.");
        }

        $warehouse->update(['status' => 'passive']);

        return $warehouse;
    }

    public function activate(Warehouse $warehouse): Warehouse
    {
        $warehouse->update(['status' => 'active']);

        return $warehouse;
    }

    /**
     * Depo sorumlusu, deponun şubesinde stok erişimi olan aktif bir kullanıcı
     * olmalıdır — aksi halde sorumlu olduğu depoyu hiçbir ekranda göremezdi.
     */
    public function canManage(User $user, Warehouse|Branch $location): bool
    {
        $branch = $location instanceof Branch ? $location : $location->branch()->withoutGlobalScopes()->firstOrFail();
        $accessible = $user->accessibleBranchIds(Module::StockMovement);

        return $user->isActive()
            && $user->organization_id === $branch->organization_id
            && ($accessible === null || in_array($branch->id, $accessible, true));
    }

    /**
     * @param  array<int, int>  $managerIds
     */
    private function assignManagers(Warehouse $warehouse, array $managerIds): void
    {
        $managers = User::with('permissions.branches')->findMany($managerIds);

        foreach ($managers as $manager) {
            if (! $this->canManage($manager, $warehouse)) {
                throw new LocationRuleException("{$manager->name}, bu deponun şubesinde stok yetkisine sahip değil; sorumlu atanamaz.");
            }
        }

        $before = $warehouse->managers()->orderBy('name')->pluck('name')->all();

        $warehouse->managers()->sync($managers->modelKeys());

        $after = $warehouse->managers()->orderBy('name')->pluck('name')->all();

        if ($before !== $after) {
            $warehouse->recordAuditChange(['managers' => $before], ['managers' => $after]);
        }
    }
}
