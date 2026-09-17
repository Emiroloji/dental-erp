<?php

namespace App\Domain\Transfer\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class TransferPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::Transfer;
    }
}
