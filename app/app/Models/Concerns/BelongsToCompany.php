<?php

namespace App\Models\Concerns;

use App\Models\AppUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company scoping helpers for company-aware models.
 * Controllers/services call scopeForUser via CompanyContext.
 */
trait BelongsToCompany
{
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    /** Scope to the companies a user may see (superadmin = all). */
    public function scopeForUser(Builder $query, AppUser $user): Builder
    {
        if ($user->isSuperadmin()) {
            return $query;
        }

        return $query->where($this->getTable().'.company_id', $user->company_id);
    }
}
