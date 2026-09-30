<?php

namespace App\Policies;

use App\Models\AppUser;

/**
 * Application users: a COMPANY_ADMIN manages users of their own company;
 * a SUPERADMIN manages everyone (and is the only one who can create
 * superadmins or cross-company users).
 */
class AppUserPolicy
{
    public function viewAny(AppUser $user): bool
    {
        return $user->isSuperadmin() || $user->isCompanyAdmin();
    }

    public function view(AppUser $actor, AppUser $target): bool
    {
        return $actor->isSuperadmin() || $actor->company_id === $target->company_id;
    }

    public function create(AppUser $actor): bool
    {
        return $actor->isSuperadmin() || $actor->isCompanyAdmin();
    }

    public function update(AppUser $actor, AppUser $target): bool
    {
        if ($actor->isSuperadmin()) {
            return true;
        }

        // Admins manage only their company's users, and cannot touch superadmins.
        return $actor->isCompanyAdmin()
            && $actor->company_id === $target->company_id
            && ! $target->isSuperadmin();
    }

    public function delete(AppUser $actor, AppUser $target): bool
    {
        if ($actor->isSuperadmin()) {
            return $actor->getKey() !== $target->getKey(); // can't deactivate yourself
        }

        return $actor->isCompanyAdmin()
            && $actor->company_id === $target->company_id
            && ! $target->isSuperadmin();
    }
}
