<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Models\Company;

// Mirrors CheckCompanyModule middleware's identical header-read pattern for
// the User/staff side (X-Active-Company-Id, set automatically by
// frontend/lib/axios.ts's request interceptor once setActiveCompany() is
// called — see frontend/lib/auth.ts and frontend/components/admin/
// CompanySelector.tsx). Never trusts the header alone: always intersected
// with companyIds() so a tampered/foreign id can never leak another
// tenant's data. Falls back to the admin's first company when the header is
// absent or names a company this admin doesn't own — same "companyIds()[0]
// as default" convention already used by ClientController::index()'s
// seat-info panel.
//
// Every consuming controller already defines its own private companyIds()
// (copy-pasted per-controller throughout this codebase, not centralized) —
// this trait relies on that method existing on the composing class.
trait ScopesToActiveCompany
{
    protected function activeCompanyId(): int
    {
        $owned = $this->operationalCompanyIds();
        $requested = (int) request()->header('X-Active-Company-Id');

        return ($requested && in_array($requested, $owned, true)) ? $requested : ($owned[0] ?? 0);
    }

    // "All Companies" support — the header carries the literal string "all"
    // (never a numeric 0, which already means "nothing valid resolved" in
    // activeCompanyId() above) when the dropdown's All Companies option is
    // selected. Every other value resolves to exactly one company, same as
    // activeCompanyId(), just wrapped in an array so callers can use
    // whereIn() uniformly regardless of which mode is active.
    protected function activeCompanyIds(): array
    {
        $owned  = $this->operationalCompanyIds();
        $header = request()->header('X-Active-Company-Id');

        if ($header === 'all') {
            return $owned;
        }

        $requested = (int) $header;
        if ($requested && in_array($requested, $owned, true)) {
            return [$requested];
        }

        return isset($owned[0]) ? [$owned[0]] : [];
    }

    // Excludes a suspended (Company Admin's own manual suspension) or
    // deactivated (Super Admin's toggle) company — from "All Companies"
    // aggregate list/report endpoints (which must not mix a suspended
    // company's leads/employees/invoices/etc. in with the rest), AND from
    // every "which company should this NEW record be created under" picker
    // validation (e.g. Brand/Employee/Lead/Project/Client/User store()),
    // where a request explicitly names a target company_id rather than
    // going through activeCompanyId(). Protected (not private) so every
    // consuming controller can call it directly in place of companyIds()
    // for that latter case. Company::isOperational() is the single source
    // of truth for both flags.
    protected function operationalCompanyIds(): array
    {
        $owned = $this->companyIds();

        return Company::whereIn('id', $owned)->operational()->pluck('id')->all();
    }
}
