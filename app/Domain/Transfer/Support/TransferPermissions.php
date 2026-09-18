<?php

namespace App\Domain\Transfer\Support;

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Transfer\Models\TransferRequest;
use App\Models\User;

/**
 * proje.md Bölüm 8: talep, hedef (ihtiyacı olan) depo tarafından açılır ve
 * teslim alınır; onay/red, hazırlama ve gönderim kaynak (stoğu veren) depo
 * tarafındadır. "Taraf" = Transfer modülünde yazma yetkisi + deponun şubesi
 * kullanıcının kapsamında. Talep eden kendi talebini onaylayamaz (Admin hariç).
 */
class TransferPermissions
{
    public function canView(User $user, TransferRequest $transfer): bool
    {
        if (! $user->canModule(Module::Transfer, 'read')) {
            return false;
        }

        $accessible = $user->accessibleBranchIds(Module::Transfer);

        return $accessible === null
            || in_array($transfer->fromWarehouse->branch_id, $accessible, true)
            || in_array($transfer->toWarehouse->branch_id, $accessible, true);
    }

    public function canRequestInto(User $user, Warehouse $destination): bool
    {
        return $this->onSide($user, $destination);
    }

    public function can(User $user, string $action, TransferRequest $transfer): bool
    {
        return match ($action) {
            'approve', 'reject' => $this->onSide($user, $transfer->fromWarehouse)
                && ($user->isAdmin() || $transfer->requested_by !== $user->id),
            'prepare', 'ship' => $this->onSide($user, $transfer->fromWarehouse),
            'receive' => $this->onSide($user, $transfer->toWarehouse),
            'cancel' => $this->onSide($user, $transfer->fromWarehouse) || $this->onSide($user, $transfer->toWarehouse),
            default => false,
        };
    }

    private function onSide(User $user, Warehouse $warehouse): bool
    {
        if (! $user->canModule(Module::Transfer, 'write')) {
            return false;
        }

        $accessible = $user->accessibleBranchIds(Module::Transfer);

        return $accessible === null || in_array($warehouse->branch_id, $accessible, true);
    }
}
