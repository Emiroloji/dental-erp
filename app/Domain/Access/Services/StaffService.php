<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\PermissionScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffService
{
    /**
     * @param  array{name: string, email: string, password: string, branch_id: int}  $attributes
     * @param  array<string, array{read: bool, write: bool, delete: bool}>  $modulePermissions
     * @param  array<int>  $branchIds
     */
    public function createStaff(
        array $attributes,
        array $modulePermissions,
        PermissionScope $scope,
        array $branchIds = [],
    ): User {
        return DB::transaction(function () use ($attributes, $modulePermissions, $scope, $branchIds) {
            $user = User::create([
                'organization_id' => auth()->user()->organization_id,
                'branch_id' => $attributes['branch_id'],
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                'role' => User::ROLE_STAFF,
                'status' => 'active',
            ]);

            foreach ($modulePermissions as $module => $abilities) {
                if (! $abilities['read'] && ! $abilities['write'] && ! $abilities['delete']) {
                    continue;
                }

                $permission = Permission::create([
                    'user_id' => $user->id,
                    'module' => $module,
                    'can_read' => $abilities['read'],
                    'can_write' => $abilities['write'],
                    'can_delete' => $abilities['delete'],
                    'scope' => $scope->value,
                ]);

                if ($scope === PermissionScope::SelectedBranches) {
                    $permission->branches()->sync($branchIds);
                }
            }

            return $user;
        });
    }
}
