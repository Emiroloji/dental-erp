<?php

namespace App\Domain\Organization\Concerns;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            if ($organizationId = auth()->user()?->organization_id) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $organizationId);
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id && $organizationId = auth()->user()?->organization_id) {
                $model->organization_id = $organizationId;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
