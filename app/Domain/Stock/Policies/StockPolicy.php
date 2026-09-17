<?php

namespace App\Domain\Stock\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class StockPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::StockMovement;
    }
}
