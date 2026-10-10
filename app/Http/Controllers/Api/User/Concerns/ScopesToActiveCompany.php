<?php

namespace App\Http\Controllers\Api\User\Concerns;

use App\Models\CompanyUserAssignment;

// Mirrors CheckCompanyModule middleware's identical User-branch logic (and
// Api\Admin\Concerns\ScopesToActiveCompany's equivalent for the Admin side):
// resolves which company a request should actually be scoped to from the
// X-Active-Company-Id header, validated against an ACTIVE
// CompanyUserAssignment row, falling back to the user's static company_id
// column only if THAT itself still resolves to an active assignment. Never
// trusts either value alone. Routes that reach a controller using this
// trait are already gated by `module:*` middleware (CheckCompanyModule),
// which validates the exact same header first — so this just re-derives
// the value the middleware already proved valid.
//
// Every consuming controller must define its own private user() accessor
// (the existing convention in this codebase — see Api\User\LeadController).
trait ScopesToActiveCompany
{
    protected function activeCompanyId(): int
    {
        $user = $this->user();
        $requested = (int) request()->header('X-Active-Company-Id');

        if ($requested && CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $requested)
            ->where('status', 'active')
            ->exists()) {
            return $requested;
        }

        $fallback = (int) $user->company_id;
        if ($fallback && CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $fallback)
            ->where('status', 'active')
            ->exists()) {
            return $fallback;
        }

        return 0;
    }
}
