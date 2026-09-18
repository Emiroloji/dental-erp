<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Access\Support\Module;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;

/**
 * Sayım bir stok işidir: Stok Giriş/Çıkış okuma ile görülür, yazma ile
 * başlatılır/girilir/onaya gönderilir (depo kapsamda olmalı). Onay ve geri
 * gönderme yalnızca Admin'dir (proje.md Bölüm 10: "Admin onaylar").
 */
class StockCountPermissions
{
    public function canView(User $user, StockCount $count): bool
    {
        return $user->canModule(Module::StockMovement, 'read') && $this->inScope($user, $count->warehouse);
    }

    public function canCount(User $user, Warehouse $warehouse): bool
    {
        return $user->canModule(Module::StockMovement, 'write') && $this->inScope($user, $warehouse);
    }

    public function canApprove(User $user): bool
    {
        return $user->isAdmin();
    }

    private function inScope(User $user, Warehouse $warehouse): bool
    {
        $accessible = $user->accessibleBranchIds(Module::StockMovement);

        return $accessible === null || in_array($warehouse->branch_id, $accessible, true);
    }
}
