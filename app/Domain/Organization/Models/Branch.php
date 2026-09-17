<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
    ];

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
