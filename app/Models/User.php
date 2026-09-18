<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'organization_id', 'branch_id', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    public const ROLE_PLATFORM_OWNER = 'platform_owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_STAFF = 'staff';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPlatformOwner(): bool
    {
        return $this->role === self::ROLE_PLATFORM_OWNER;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Organizasyonu Platform Sahibi tarafından salt-okunur (veya pasif) yapılmış
     * bir kullanıcı hiçbir kayıt ekleyemez/değiştiremez — Admin dahil.
     */
    public function inReadOnlyOrganization(): bool
    {
        return $this->organization_id !== null && (bool) $this->organization?->isReadOnly();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function managedWarehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user')->withTimestamps();
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /**
     * Verilen modüllerde erişebildiği şubeler (birden fazla modül verilirse
     * birleşimi). null = kısıt yok (Admin veya "Tüm şubeler" kapsamı);
     * boş dizi = hiçbir şube.
     *
     * @return array<int, int>|null
     */
    public function accessibleBranchIds(Module ...$modules): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        $branchIds = [];

        foreach ($modules as $module) {
            $permission = $this->permissions->firstWhere('module', $module);

            $branchIds = [...$branchIds, ...match ($permission?->scope) {
                null => [],
                PermissionScope::All => [null],
                PermissionScope::OwnBranch => $this->branch_id ? [$this->branch_id] : [],
                PermissionScope::SelectedBranches => $permission->branches->modelKeys(),
            }];
        }

        if (in_array(null, $branchIds, true)) {
            return null;
        }

        $branchIds = array_values(array_unique($branchIds));
        sort($branchIds);

        return $branchIds;
    }

    public function canModule(Module $module, string $ability): bool
    {
        if ($ability !== 'read' && $this->inReadOnlyOrganization()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return $this->permissions
            ->firstWhere('module', $module)
            ?->allows($ability) ?? false;
    }
}
