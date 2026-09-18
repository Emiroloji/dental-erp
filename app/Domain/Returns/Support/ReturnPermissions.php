<?php

namespace App\Domain\Returns\Support;

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Returns\Models\SupplierReturn;
use App\Models\User;

/**
 * İade bir stok hareketidir (kurallar.md Bölüm 2: "giriş/çıkış/transfer/sayım/
 * iade"): Stok Giriş/Çıkış okuma ile görülür; yazma ile talep açılır, kargoya
 * verilir, tedarikçi yanıtı ve kapanış girilir (lotun deposu kapsamda olmalı).
 * Talebi onaylamak/reddetmek yalnızca Admin'dir — Satın Alma ve Stok Sayımı
 * onaylarıyla tutarlı.
 */
class ReturnPermissions
{
    public function canView(User $user, SupplierReturn $return): bool
    {
        return $user->canModule(Module::StockMovement, 'read') && $this->inScope($user, $return->warehouse);
    }

    public function canManage(User $user, Warehouse $warehouse): bool
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
