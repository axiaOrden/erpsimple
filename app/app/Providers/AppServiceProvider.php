<?php

namespace App\Providers;

use App\Models\AppUser;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Policies\AppUserPolicy;
use App\Policies\CustomerMasterPolicy;
use App\Policies\MasterDataPolicy;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class, function ($app) {
            return new CompanyContext($app['session.store']);
        });
    }

    public function boot(): void
    {
        // Policy mappings (non-standard policy class names).
        Gate::policy(ProductMaster::class, MasterDataPolicy::class);
        Gate::policy(EmployeeMaster::class, MasterDataPolicy::class);
        Gate::policy(CustomerMaster::class, CustomerMasterPolicy::class);
        Gate::policy(AppUser::class, AppUserPolicy::class);
        Gate::policy(CustomerFjp::class, MasterDataPolicy::class);

        // SUPERADMIN: full application administration and company switching.
        Gate::define('switch-company', fn (AppUser $user) => $user->isSuperadmin());

        Gate::define('administer-application', fn (AppUser $user) => $user->isSuperadmin());

        // Master data management within the resolved company context.
        Gate::define('manage-master-data', function (AppUser $user) {
            return $user->isSuperadmin() || $user->isCompanyAdmin();
        });

        // Field operations (attendance, orders, counts) for sales employees.
        Gate::define('manage-own-operations', function (AppUser $user) {
            return $user->isSalesEmployee() || $user->isSuperadmin() || $user->isCompanyAdmin();
        });
    }
}
