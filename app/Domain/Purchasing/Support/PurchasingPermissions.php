<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;

/**
 * Satın Alma modülü: talep açma/düzenleme, sipariş verme, teslim alma ve
 * iptal için yazma yetkisi + teslim deposunun şubesi kullanıcının kapsamında.
 * Onay/red yalnızca Admin'dir (proje.md Bölüm 9 — kullanıcı kararı).
 */
class PurchasingPermissions
{
    public function canView(User $user, PurchaseOrder $order): bool
    {
        return $user->canModule(Module::Purchasing, 'read') && $this->inScope($user, $order->warehouse);
    }

    public function canManage(User $user, Warehouse $warehouse): bool
    {
        return $user->canModule(Module::Purchasing, 'write') && $this->inScope($user, $warehouse);
    }

    public function canApprove(User $user): bool
    {
        return $user->isAdmin();
    }

    private function inScope(User $user, Warehouse $warehouse): bool
    {
        $accessible = $user->accessibleBranchIds(Module::Purchasing);

        return $accessible === null || in_array($warehouse->branch_id, $accessible, true);
    }
}
