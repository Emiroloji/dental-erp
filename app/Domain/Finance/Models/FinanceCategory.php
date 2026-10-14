<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'direction',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'direction' => CategoryDirection::class,
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
