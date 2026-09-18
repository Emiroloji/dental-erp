<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class PurchasingPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::Purchasing;
    }
}
