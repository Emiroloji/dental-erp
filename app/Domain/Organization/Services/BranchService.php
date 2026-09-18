<?php

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Exceptions\LocationRuleException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Stock\Models\StockLot;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * proje.md Bölüm 6: her yeni şubeye otomatik bir "Varsayılan Depo" açılır;
     * basit kurulumlar bunu fark etmeden tek depo gibi kullanır.
     *
     * @param  array{name: string, address?: ?string, phone?: ?string}  $attributes
     */
    public function create(array $attributes): Branch
    {
        return DB::transaction(function () use ($attributes) {
            // Paket limiti (Faz 3): organizasyon satırı kilitlenir, eşzamanlı iki
            // şube açılışı limiti birlikte aşamaz.
            $this->limits->ensureCanAddBranch($this->lockedOrganization());

            $branch = Branch::create([
                'name' => $attributes['name'],
                'address' => $attributes['address'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'status' => 'active',
            ]);

            Warehouse::create([
                'branch_id' => $branch->id,
                'name' => 'Varsayılan Depo',
                'is_default' => true,
                'status' => 'active',
            ]);

            return $branch;
        });
    }

    /**
     * @param  array{name: string, address?: ?string, phone?: ?string}  $attributes
     */
    public function update(Branch $branch, array $attributes): Branch
    {
        $branch->update([
            'name' => $attributes['name'],
            'address' => $attributes['address'] ?? null,
            'phone' => $attributes['phone'] ?? null,
        ]);

        return $branch;
    }

    /**
     * Pasif şubede hiçbir stok işlemi yapılamaz (kurallar.md Bölüm 4); bu yüzden
     * içinde stok duran bir şube pasifleştirilemez — stok erişilemez hale gelirdi.
     */
    public function deactivate(Branch $branch): Branch
    {
        $otherActiveBranches = Branch::where('status', 'active')->whereKeyNot($branch->id)->exists();

        if (! $otherActiveBranches) {
            throw new LocationRuleException('En az bir aktif şube bulunmalıdır; son aktif şube pasifleştirilemez.');
        }

        $hasStock = StockLot::inBranches([$branch->id])->where('quantity', '>', 0)->exists();

        if ($hasStock) {
            throw new LocationRuleException("\"{$branch->name}\" şubesinde stok var; önce stoğu başka bir depoya aktarın veya sıfırlayın.");
        }

        $branch->update(['status' => 'passive']);

        return $branch;
    }

    public function activate(Branch $branch): Branch
    {
        if ($branch->status === 'active') {
            return $branch;
        }

        return DB::transaction(function () use ($branch) {
            $this->limits->ensureCanAddBranch($this->lockedOrganization());

            $branch->update(['status' => 'active']);

            return $branch;
        });
    }

    private function lockedOrganization(): Organization
    {
        return Organization::whereKey(auth()->user()->organization_id)->lockForUpdate()->firstOrFail();
    }
}
