<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Exceptions\StaffRuleException;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\PlanLimitService;
use App\Models\User;
use App\Support\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffService
{
    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * @param  array{name: string, email: string, password: string, branch_id: int}  $attributes
     * @param  array<string, array{read: bool, write: bool, delete: bool}>  $modulePermissions
     * @param  array<int>  $branchIds
     */
    public function createStaff(
        array $attributes,
        array $modulePermissions,
        PermissionScope $scope,
        array $branchIds = [],
    ): User {
        return DB::transaction(function () use ($attributes, $modulePermissions, $scope, $branchIds) {
            // Paket limiti (Faz 3): aktif kullanıcı sayısı; organizasyon satırı kilitlenir.
            $this->limits->ensureCanAddUser(Organization::whereKey(auth()->user()->organization_id)->lockForUpdate()->firstOrFail());

            $user = User::create([
                'organization_id' => auth()->user()->organization_id,
                'branch_id' => $attributes['branch_id'],
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                'role' => User::ROLE_STAFF,
                'status' => 'active',
            ]);

            foreach ($modulePermissions as $module => $abilities) {
                if (! $abilities['read'] && ! $abilities['write'] && ! $abilities['delete']) {
                    continue;
                }

                $permission = Permission::create([
                    'user_id' => $user->id,
                    'module' => $module,
                    'can_read' => $abilities['read'],
                    'can_write' => $abilities['write'],
                    'can_delete' => $abilities['delete'],
                    'scope' => $scope->value,
                ]);

                if ($scope === PermissionScope::SelectedBranches) {
                    $permission->branches()->sync($branchIds);
                }
            }

            return $user;
        });
    }

    /**
     * Pasif personel sisteme giriş yapamaz (kurallar.md Bölüm 4); açık oturumu
     * da bir sonraki istekte kapatılır (EnsureTenantAccess). Kayıt silinmez,
     * geçmiş hareketlerdeki "işlemi yapan" bilgisi korunur.
     */
    public function deactivate(User $staff): User
    {
        if ($staff->is(auth()->user())) {
            throw new StaffRuleException('Kendi hesabınızı pasifleştiremezsiniz.');
        }

        if ($staff->role !== User::ROLE_STAFF) {
            throw new StaffRuleException('Yalnızca personel hesapları buradan pasifleştirilebilir.');
        }

        $staff->update(['status' => 'passive']);

        return $staff;
    }

    public function activate(User $staff): User
    {
        if ($staff->isActive()) {
            return $staff;
        }

        if ($staff->role !== User::ROLE_STAFF) {
            throw new StaffRuleException('Yalnızca personel hesapları buradan aktifleştirilebilir.');
        }

        return DB::transaction(function () use ($staff) {
            // Aktif kullanıcı sayısı paket limitine dahildir (Faz 3).
            $this->limits->ensureCanAddUser(Organization::whereKey($staff->organization_id)->lockForUpdate()->firstOrFail());

            $staff->update(['status' => 'active']);

            return $staff;
        });
    }

    /**
     * Ana Klinik Sahibi devri (proje.md Bölüm 4). Sahiplik, aynı organizasyonun
     * aktif bir personeline geçer; mevcut sahip şifresiyle onaylar. Eski sahip
     * erişimini kaybetmez: tüm modüllerde tam yetkili, "Tüm şubeler" kapsamlı
     * personel olur — yeni sahip bu yetkileri dilediği gibi daraltabilir.
     * Organizasyonda her an bir aktif Admin kalır (kurallar.md Bölüm 4).
     */
    public function transferOwnership(User $owner, User $newOwner, string $password): User
    {
        if (! $owner->isAdmin()) {
            throw new StaffRuleException('Sahipliği yalnızca Ana Klinik Sahibi devredebilir.');
        }

        if (! Hash::check($password, $owner->password)) {
            throw new StaffRuleException('Şifre doğrulanamadı.');
        }

        if ($newOwner->is($owner) || $newOwner->organization_id !== $owner->organization_id
            || $newOwner->role !== User::ROLE_STAFF || ! $newOwner->isActive()) {
            throw new StaffRuleException('Sahiplik yalnızca organizasyonun aktif bir personeline devredilebilir.');
        }

        DB::transaction(function () use ($owner, $newOwner) {
            // Admin tüm yetkileri rolüyle alır; eski kutucuklar anlamsızlaşır.
            // Tek tek silinir ki yetki değişikliği denetim kaydına düşsün (kurallar.md Bölüm 4).
            $newOwner->permissions()->get()->each->delete();
            $newOwner->update(['role' => User::ROLE_ADMIN]);

            $owner->update(['role' => User::ROLE_STAFF]);

            foreach (Module::cases() as $module) {
                Permission::create([
                    'user_id' => $owner->id,
                    'module' => $module->value,
                    'can_read' => true,
                    'can_write' => true,
                    'can_delete' => true,
                    'scope' => PermissionScope::All->value,
                ]);
            }
        });

        $newOwner->notify(new WorkflowNotification(
            'ownership',
            'Ana Klinik Sahibi oldunuz',
            "{$owner->name} organizasyonun sahipliğini size devretti. Artık tüm modüllerde tam yetkilisiniz.",
            route('staff.index'),
            WorkflowNotification::LEVEL_GOOD,
        ));

        return $newOwner->refresh();
    }
}
