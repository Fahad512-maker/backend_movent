<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\CompanyAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Refuses every authenticated request belonging to a tenant whose owner the
// Super Admin has switched off — company_admins.is_active, flipped by
// Api\SuperAdmin\CompanyAdminController::toggleStatus().
//
// BOTH GUARDS, ONE RULE. Deactivation takes down the whole tenant, not just
// the person who owns it: the Company Admin on `auth:admin`, and every staff
// member of their companies on `auth:sanctum`. Keeping both in one middleware
// is what stops the two halves from drifting into different answers — a
// company whose owner is switched off but whose staff keep working is the
// exact gap this closes.
//
// WHY THIS EXISTS AS WELL AS THE LOGIN GATES. Auth\AdminAuthController and
// Auth\UserAuthController both refuse to issue a token while the owner is
// inactive, but that only governs NEW logins. Anyone already signed in when
// the switch was flipped is holding a perfectly good Sanctum token.
// toggleStatus() revokes those on the way down, which ends those sessions on
// their very next request; this middleware is what makes the rule structural
// rather than dependent on one controller remembering to do the revoking.
//
// WHICH tenant gates a staff request: $user->company is the request's ACTIVE
// company by the time this runs, not the user's static primary one — this
// middleware is registered AFTER 'sync.active.company' in the staff group
// (see routes/api.php), the same ordering requirement
// EnsureCompanySubscriptionActive documents. So a staff member assigned to
// two tenants is only ever blocked on the one actually switched off.
//
// Deliberately placed BEFORE the subscription/suspension gates in both route
// groups: a deactivated tenant outranks whatever its subscription is doing,
// and unlike those states it has no in-app resolution — only the Super Admin
// can reverse it, which is why the response carries its own error_code and
// the frontend ends the session on it instead of just toasting (see
// frontend/lib/axios.ts).
//
// Only the admin `logout` opts out (see routes/api.php), so a client still
// holding a token can hand it back and have the row actually deleted rather
// than leaving it dangling until the tenant is reactivated.
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof CompanyAdmin && !$admin->is_active) {
            return ApiResponse::error(
                CompanyAdmin::DEACTIVATED_MESSAGE,
                403,
                ['error_code' => CompanyAdmin::DEACTIVATED_ERROR_CODE]
            );
        }

        $user = $request->user();

        // The owner of the company this request is actually being made
        // under. Same resolution EnsureCompanySubscriptionActive uses, so
        // "whose tenant is this" is answered identically by both gates.
        if ($user instanceof User && $user->company?->admin && !$user->company->admin->is_active) {
            return ApiResponse::error(
                CompanyAdmin::TENANT_DEACTIVATED_MESSAGE,
                403,
                ['error_code' => CompanyAdmin::TENANT_DEACTIVATED_ERROR_CODE]
            );
        }

        return $next($request);
    }
}
