<?php

namespace App\Providers;

use App\Domain\Access\Policies\ReportPolicy;
use App\Domain\Access\Policies\SettingsPolicy;
use App\Domain\Access\Policies\StaffPolicy;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Policies\CategoryPolicy;
use App\Domain\Catalog\Policies\ProductPolicy;
use App\Domain\Catalog\Policies\SupplierPolicy;
use App\Domain\Stock\Policies\StockPolicy;
use App\Domain\Transfer\Policies\TransferPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    private const MODULE_POLICIES = [
        Module::ProductManagement->value => ProductPolicy::class,
        Module::CategoryManagement->value => CategoryPolicy::class,
        Module::SupplierManagement->value => SupplierPolicy::class,
        Module::StockMovement->value => StockPolicy::class,
        Module::Transfer->value => TransferPolicy::class,
        Module::StaffManagement->value => StaffPolicy::class,
        Module::Reports->value => ReportPolicy::class,
        Module::SystemSettings->value => SettingsPolicy::class,
    ];

    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        foreach (self::MODULE_POLICIES as $module => $policy) {
            Gate::define("{$module}.viewAny", [$policy, 'viewAny']);
            Gate::define("{$module}.create", [$policy, 'create']);
            Gate::define("{$module}.update", [$policy, 'update']);
            Gate::define("{$module}.delete", [$policy, 'delete']);
        }

        // Denetim kayıtları bir yetki kutucuğu değildir: yalnızca Admin görür
        // (Gate::before üzerinden), personele hiçbir modül yetkisiyle açılmaz.
        Gate::define('audit_logs.viewAny', fn (User $user) => false);
    }
}
