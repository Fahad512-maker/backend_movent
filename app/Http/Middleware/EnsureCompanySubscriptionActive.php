<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Staff/sub-user counterpart to EnsureSubscriptionActive (which only guards
// the auth:admin/CompanyAdmin guard) — closes the gap where a suspended
// tenant's staff kept full API access indefinitely because nothing ever
// re-checked subscription status after login. Per the Trial/Suspension
// policy: "If company is suspended, all company users are suspended from
// CRM access."
//
// Must run AFTER 'sync.active.company' in the route group (see routes/api.php)
// — that middleware already resolves $user->company_id to the request's
// ACTIVE company (not just the user's static primary one), which matters for
// a genuinely multi-tenant staff member: only the tenant they're currently
// working under should ever gate this request, never some other company
// they're separately assigned to.
class EnsureCompanySubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $admin = $user->company?->admin;

            if ($admin && $admin->isBlockedFromAccess()) {
                return ApiResponse::error(
                    $admin->subscription_status === 'trial_expired'
                        ? 'Your free trial has expired. Please ask your Company Admin to activate a subscription to continue using Movent CRM.'
                        : 'Your company account has been suspended. Please ask your Company Admin to activate a subscription to continue using Movent CRM.',
                    402,
                    ['error_code' => 'subscription_required', 'subscription_status' => $admin->subscription_status]
                );
            }
        }

        return $next($request);
    }
}
