<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\CompanyAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Super Admin's platform-level kill switch for an entire tenant
// (CompanyAdmin.is_active) — distinct from subscription lifecycle
// (EnsureSubscriptionActive/EnsureCompanySubscriptionActive, which the tenant
// can resolve itself by paying) and from a single Company's manual
// suspension (EnsureCompanyNotSuspended, scoped to one Company under a
// multi-company admin). is_active has no self-service remedy — only Super
// Admin can flip it back — so a blocked session here is logged out outright
// rather than parked on a "go fix this" screen (see lib/axios.ts and
// clientAxios.ts's 'company_admin_inactive' handling).
//
// Covers both guards the same way EnsureCompanyNotSuspended does:
//   - Staff/client (auth:sanctum — a client portal login IS a User row,
//     role_type='client', see Api\Client\AuthController::login()) — checked
//     via $user->company->admin->is_active.
//   - Company Admin's own guard (auth:admin) — checked against itself, so an
//     already-logged-in admin session dies on its very next request too, not
//     just at their next login attempt.
//
// Token rows for every affected session are also deleted outright the
// moment Super Admin flips this off (see
// Api\SuperAdmin\CompanyAdminController::toggleStatus()) — this middleware
// is the defense-in-depth backstop for whatever request lands in the gap
// before that deletion completes, not the only enforcement.
class EnsureCompanyAdminActive
{
    private const MESSAGE = 'Your company account is currently inactive. Please contact your administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $admin = $user->company?->admin;

            if ($admin && !$admin->is_active) {
                return $this->blocked();
            }
        }

        $admin = $request->user('admin');

        if ($admin instanceof CompanyAdmin && !$admin->is_active) {
            return $this->blocked();
        }

        return $next($request);
    }

    private function blocked(): Response
    {
        return ApiResponse::error(self::MESSAGE, 403, ['error_code' => 'company_admin_inactive']);
    }
}
