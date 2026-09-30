<?php

namespace App\Policies;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data (products, employees). COMPANY_ADMIN manages only their own
 * company's rows; SUPERADMIN manages everything. Every write checks the
 * target resource's company — the actor's role alone is never sufficient.
 * Customers and app users have their own policies.
 */
class MasterDataPolicy
{
    public function viewAny(AppUser $user): bool
    {
        return $user->isSuperadmin() || $user->isCompanyAdmin();
    }

    public function view(AppUser $user, Model $model): bool
    {
        return $user->isSuperadmin() || $this->sameCompany($user, $model);
    }

    public function create(AppUser $user): bool
    {
        return $user->isSuperadmin() || $user->isCompanyAdmin();
    }

    public function update(AppUser $user, Model $model): bool
    {
        return $user->isSuperadmin() || ($user->isCompanyAdmin() && $this->sameCompany($user, $model));
    }

    /**
     * Soft-deactivation (never hard delete) for transactional integrity.
     */
    public function delete(AppUser $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    private function sameCompany(AppUser $user, Model $model): bool
    {
        $modelCompanyId = $model->company_id ?? ($model instanceof EmployeeMaster || $model instanceof ProductMaster
            ? $model->getAttribute('company_id')
            : null);

        if ($modelCompanyId === null && $model instanceof CompanyMaster) {
            $modelCompanyId = $model->company_id;
        }

        return $modelCompanyId !== null && $modelCompanyId === $user->company_id;
    }
}
