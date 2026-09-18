<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Support\Module;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Bildirim alıcılarını seçer (kurallar.md Bölüm 5): sabit bir "Şube
 * Yöneticisi" rolü yoktur; ilgili modülde yetkisi olan ve şubesi kapsamında
 * kalan personel ile Ana Klinik Sahibi (Admin) bilgilendirilir. Pasif
 * kullanıcılar ve işlemi yapan kişinin kendisi hiçbir zaman bildirim almaz.
 */
class NotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function admins(int $organizationId): Collection
    {
        return User::where('organization_id', $organizationId)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', 'active')
            ->get();
    }

    /**
     * Modülde verilen yetkiye (read/write) sahip ve şubesi kapsamında olan personel.
     *
     * @return Collection<int, User>
     */
    public function staffWith(int $organizationId, Module $module, string $ability, int $branchId): Collection
    {
        return User::where('organization_id', $organizationId)
            ->where('role', User::ROLE_STAFF)
            ->where('status', 'active')
            ->whereHas('permissions', fn ($query) => $query->where('module', $module->value)->where($ability === 'write' ? 'can_write' : 'can_read', true))
            ->with('permissions.branches')
            ->get()
            ->filter(function (User $user) use ($module, $branchId) {
                $accessible = $user->accessibleBranchIds($module);

                return $accessible === null || in_array($branchId, $accessible, true);
            })
            ->values();
    }

    /**
     * @param  iterable<int, User|null>  $recipients
     * @return int Gönderilen bildirim sayısı
     */
    public function send(iterable $recipients, Notification $notification, ?User $actor = null): int
    {
        $sent = 0;

        collect($recipients)
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actor?->id || $user->status !== 'active')
            ->each(function (User $user) use ($notification, &$sent) {
                $user->notify($notification);
                $sent++;
            });

        return $sent;
    }
}
