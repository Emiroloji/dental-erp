<?php

namespace App\Domain\Patients\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

/**
 * Hastalar organizasyon genelidir (şube kapsamı yoktur). Kimlik bilgisi
 * içerdiği için okuma yetkisi ayrı verilir.
 */
class PatientPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::Patients;
    }
}
