<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Support\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'plan',
        'max_branches',
        'max_users',
        'max_storage_mb',
    ];

    protected $casts = [
        'status' => OrganizationStatus::class,
        'plan' => Plan::class,
        'max_branches' => 'integer',
        'max_users' => 'integer',
        'max_storage_mb' => 'integer',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isReadOnly(): bool
    {
        return ! $this->status->allowsWrites();
    }

    /**
     * Geçerli limitler: organizasyona özel değer (Platform Sahibi girer) yoksa
     * paketin varsayılanı. null = sınırsız.
     *
     * @return array{branches: ?int, users: ?int, storage_mb: ?int}
     */
    public function limits(): array
    {
        return [
            'branches' => $this->max_branches ?? $this->plan->maxBranches(),
            'users' => $this->max_users ?? $this->plan->maxUsers(),
            'storage_mb' => $this->max_storage_mb ?? $this->plan->maxStorageMb(),
        ];
    }

    /**
     * Organizasyon kaydındaki değişiklikler (paket, durum, limit) o
     * organizasyonun denetim kaydına yazılır — Ana Klinik Sahibi de görür.
     */
    protected function auditOrganizationId(): ?int
    {
        return $this->id;
    }
}
