<?php

namespace App\Policies;

use App\Models\AppUser;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers are GLOBAL (shared across companies). Company admins can view and
 * manage assignments; only a SUPERADMIN creates/edits/deactivates customers
 * themselves, since the record spans companies.
 */
class CustomerMasterPolicy extends MasterDataPolicy
{
    public function view(AppUser $user, Model $model): bool
    {
        return $user->isSuperadmin() || $user->isCompanyAdmin();
    }

    public function create(AppUser $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(AppUser $user, Model $model): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(AppUser $user, Model $model): bool
    {
        return $user->isSuperadmin();
    }
}
