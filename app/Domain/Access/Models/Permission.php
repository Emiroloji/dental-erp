<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'module',
        'can_read',
        'can_write',
        'can_delete',
        'scope',
    ];

    protected $casts = [
        'can_read' => 'boolean',
        'can_write' => 'boolean',
        'can_delete' => 'boolean',
        'module' => Module::class,
        'scope' => PermissionScope::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'permission_branch');
    }

    protected function auditOrganizationId(): ?int
    {
        return $this->user()->value('organization_id');
    }

    public function allows(string $ability): bool
    {
        return match ($ability) {
            'read' => $this->can_read,
            'write' => $this->can_write,
            'delete' => $this->can_delete,
            default => false,
        };
    }
}
