<?php

namespace App\Domain\Access\Concerns;

use App\Domain\Access\Support\Module;
use App\Models\User;

trait AuthorizesModule
{
    abstract protected function module(): Module;

    public function viewAny(User $user): bool
    {
        return $user->canModule($this->module(), 'read');
    }

    public function create(User $user): bool
    {
        return $user->canModule($this->module(), 'write');
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->canModule($this->module(), 'write');
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->canModule($this->module(), 'delete');
    }
}
