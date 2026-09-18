<?php

namespace App\Domain\Platform\Services;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Notifications\OrganizationAccountCreated;
use App\Domain\Platform\Support\Plan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform Sahibi'nin organizasyon yönetimi (proje.md Bölüm 2 ve 12, mimari.md
 * Bölüm 9). Yalnızca platform seviyesi veriye dokunur: organizasyon kaydı,
 * paket/limit, durum ve Ana Klinik Sahibi hesabı. Hiçbir klinik/stok verisini
 * okumaz veya değiştirmez (kurallar.md Bölüm 4).
 */
class OrganizationAdminService
{
    /**
     * Onboarding (proje.md Bölüm 2): organizasyon + "Merkez Şube" ve onun
     * "Varsayılan Depo"su + geçici şifreli Ana Klinik Sahibi hesabı. Admin'e
     * giriş bilgileri e-postayla gider; ilk girişte şifresini değiştirir.
     *
     * @param  array{name: string, contact_email?: ?string, contact_phone?: ?string, address?: ?string, plan: Plan, max_branches?: ?int, max_users?: ?int, max_storage_mb?: ?int, admin_name: string, admin_email: string}  $data
     * @return array{organization: Organization, admin: User, password: string}
     */
    public function create(array $data, User $actor): array
    {
        $this->ensurePlatformOwner($actor);

        $password = $this->temporaryPassword();

        [$organization, $admin] = DB::transaction(function () use ($data, $password) {
            $organization = Organization::create([
                'name' => $data['name'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => OrganizationStatus::Active,
                'plan' => $data['plan'],
                'max_branches' => $data['max_branches'] ?? null,
                'max_users' => $data['max_users'] ?? null,
                'max_storage_mb' => $data['max_storage_mb'] ?? null,
            ]);

            $branch = Branch::create(['organization_id' => $organization->id, 'name' => 'Merkez Şube', 'status' => 'active']);
            Warehouse::create(['branch_id' => $branch->id, 'name' => 'Varsayılan Depo', 'is_default' => true, 'status' => 'active']);

            $admin = User::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($password),
                'must_change_password' => true,
                'role' => User::ROLE_ADMIN,
                'status' => 'active',
            ]);

            return [$organization, $admin];
        });

        $admin->notify(new OrganizationAccountCreated($organization->name, $password));

        return ['organization' => $organization, 'admin' => $admin, 'password' => $password];
    }

    /**
     * @param  array{name: string, contact_email?: ?string, contact_phone?: ?string, address?: ?string}  $data
     */
    public function updateProfile(Organization $organization, array $data, User $actor): Organization
    {
        $this->ensurePlatformOwner($actor);

        $organization->update([
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        return $organization;
    }

    /**
     * Paketi ve (isteğe bağlı) organizasyona özel limitleri uygular. Boş
     * bırakılan limit paketin varsayılanına döner. Mevcut kayıtlar hiçbir zaman
     * silinmez/pasife alınmaz; aşım varsa yalnızca yeni ekleme engellenir.
     *
     * @param  array{max_branches?: ?int, max_users?: ?int, max_storage_mb?: ?int}  $overrides
     */
    public function applyPlan(Organization $organization, Plan $plan, array $overrides, User $actor): Organization
    {
        $this->ensurePlatformOwner($actor);

        $organization->update([
            'plan' => $plan,
            'max_branches' => $overrides['max_branches'] ?? null,
            'max_users' => $overrides['max_users'] ?? null,
            'max_storage_mb' => $overrides['max_storage_mb'] ?? null,
        ]);

        return $organization;
    }

    /**
     * Aktif / salt-okunur / pasif — her zaman Platform Sahibi'nin manuel
     * kararı; otomatik (ör. ödeme gecikmesi) bir geçiş yoktur.
     */
    public function setStatus(Organization $organization, OrganizationStatus $status, User $actor): Organization
    {
        $this->ensurePlatformOwner($actor);

        $organization->update(['status' => $status]);

        return $organization;
    }

    /**
     * Ana Klinik Sahibi girişini kaybettiyse yeni bir geçici şifre üretilir ve
     * e-postayla gönderilir; ilk girişte yine değiştirilmesi istenir.
     */
    public function resetAdminPassword(User $admin, User $actor): string
    {
        $this->ensurePlatformOwner($actor);

        if (! $admin->isAdmin()) {
            throw new AuthorizationException('Platform Sahibi yalnızca Ana Klinik Sahibi hesaplarının şifresini yenileyebilir.');
        }

        $password = $this->temporaryPassword();
        $admin->update(['password' => Hash::make($password), 'must_change_password' => true]);
        $admin->notify(new OrganizationAccountCreated($admin->organization->name, $password, isReset: true));

        return $password;
    }

    private function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    private function ensurePlatformOwner(User $actor): void
    {
        if (! $actor->isPlatformOwner()) {
            throw new AuthorizationException('Bu işlem yalnızca Platform Sahibi içindir.');
        }
    }
}
