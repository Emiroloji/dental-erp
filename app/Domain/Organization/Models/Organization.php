<?php

namespace App\Domain\Organization\Models;

use App\Domain\Platform\Support\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'plan',
        'max_branches',
        'max_users',
        'max_storage_mb',
    ];

    protected $casts = [
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
}
