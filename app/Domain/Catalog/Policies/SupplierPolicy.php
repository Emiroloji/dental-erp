<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class SupplierPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::SupplierManagement;
    }
}
