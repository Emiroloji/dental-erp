<?php

namespace App\Domain\Access\Policies;

use App\Domain\Access\Concerns\AuthorizesModule;
use App\Domain\Access\Support\Module;

class SettingsPolicy
{
    use AuthorizesModule;

    protected function module(): Module
    {
        return Module::SystemSettings;
    }
}
