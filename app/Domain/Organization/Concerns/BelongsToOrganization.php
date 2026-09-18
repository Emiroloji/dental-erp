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
            $user = auth()->user();

            if ($user === null) {
                // Oturum yok (zamanlanmış işler, kuyruk, seeder): sorgular zaten
                // organizasyonu kendileri filtreler.
                return;
            }

            if ($user->organization_id === null) {
                // Organizasyonu olmayan oturum (Platform Sahibi) hiçbir tenant
                // verisini göremez (kurallar.md Bölüm 4) — varsayılan kapalı.
                // Platform domain'i ihtiyaç duyduğu sayımları kapsamı açıkça
                // kaldırarak ve organization_id vererek yapar.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('organization_id'), $user->organization_id);
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
