<?php

namespace App\Support;

use App\Models\CompanyAdmin;
use App\Models\CompanyUserAssignment;
use App\Models\User;
use Illuminate\Http\Request;

// Resolves which company a User (staff) request is acting on, and confirms
// it's genuinely backed by an ACTIVE CompanyUserAssignment. `users.company_id`
// is a NOT NULL column that can outlive the assignment backing it (e.g. a
// user whose last company was just unassigned/suspended still has *some*
// stale value sitting there) — never trust it on its own. Shared by
// CheckCompanyModule and RequireActiveCompany so every User-side route
// resolves/gates "no active company" the same way.
class ActiveCompany
{
    public static function resolve(Request $request, User $user): ?int
    {
        $requested = (int) $request->header('X-Active-Company-Id');

        if ($requested && self::isActiveAssignment($user->id, $requested)) {
            return $requested;
        }

        $fallbackId = (int) $user->company_id;
        if ($fallbackId && self::isActiveAssignment($user->id, $fallbackId)) {
            return $fallbackId;
        }

        return null;
    }

    /**
     * Which of a Company Admin's companies this request covers — the admin
     * counterpart of resolve(). Same rules as Api\Admin\Concerns\
     * ScopesToActiveCompany::activeCompanyIds(): the header is only ever
     * intersected with the companies the admin owns, the literal 'all' (the
     * Company Selector's "All Companies") means every owned company, and
     * anything else falls back to the first owned company.
     *
     * @return array<int, int>
     */
    public static function adminCompanyIds(Request $request, CompanyAdmin $admin): array
    {
        $owned  = $admin->companies()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $header = $request->header('X-Active-Company-Id');

        if ($header === 'all') {
            return $owned;
        }

        $requested = (int) $header;
        if ($requested && in_array($requested, $owned, true)) {
            return [$requested];
        }

        return isset($owned[0]) ? [$owned[0]] : [];
    }

    private static function isActiveAssignment(int $userId, int $companyId): bool
    {
        return CompanyUserAssignment::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();
    }
}
