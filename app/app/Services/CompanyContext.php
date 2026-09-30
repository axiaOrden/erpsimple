<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use Illuminate\Session\Store;

/**
 * Resolves the company a request operates in. Superadmins may switch
 * companies (session-backed); everyone else is pinned to their own.
 */
class CompanyContext
{
    public function __construct(private readonly Store $session) {}

    public function companyId(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $user->isSuperadmin()
            ? ($this->session->get('company_context') ?? $user->company_id)
            : $user->company_id;
    }

    public function company(): ?CompanyMaster
    {
        $id = $this->companyId();

        return $id === null ? null : CompanyMaster::find($id);
    }

    /** Company ids visible to the user (superadmin = every active company). */
    public function visibleCompanyIds(AppUser $user): array
    {
        if ($user->isSuperadmin()) {
            return CompanyMaster::where('active', true)->pluck('company_id')->all();
        }

        return [$user->company_id];
    }
}
