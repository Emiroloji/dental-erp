<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class CategoryPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::CategoryManagement;
    }
}
