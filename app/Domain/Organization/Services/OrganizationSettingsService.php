<?php

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\ExpiredLotPolicy;

class OrganizationSettingsService
{
    /**
     * Ayar değişikliği Organization modelinin Auditable davranışıyla denetim
     * kaydına önceki/yeni değerle işlenir (kurallar.md Bölüm 6).
     */
    public function update(Organization $organization, ExpiredLotPolicy $expiredLotPolicy): Organization
    {
        $organization->update([
            'settings' => [...($organization->settings ?? []), 'expired_lot_policy' => $expiredLotPolicy->value],
        ]);

        return $organization;
    }
}
