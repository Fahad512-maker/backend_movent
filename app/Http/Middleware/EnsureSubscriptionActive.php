<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Blocks every admin route EXCEPT the ones this route group deliberately
// excludes (subscription-payment/*, logout, me — see routes/api.php) while
// subscription_status is 'pending_payment', 'trial_expired', 'suspended', or
// 'cancelled'. AdminAuthController::login() already refuses to issue a token
// in most of these states for a normal login, but a token can still reach
// here via the resume-payment flow (PublicController::resumePayment()), which
// exists specifically so a blocked admin can still complete their payment —
// this middleware is what keeps that token from being usable for anything
// else in the meantime. 'grace_period' is deliberately NOT blocked here — it
// still allows full access while it counts down (a warning banner, not a
// hard block); see CompanyAdmin::isBlockedFromAccess().
class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        // access_override (CompanyAdmin::isBlockedFromAccess()) must win here
        // too — this check runs BEFORE isBlockedFromAccess() below and would
        // otherwise block a pending_payment admin Super Admin has manually
        // let back in, same as any other blocked status.
        if ($admin && !$admin->access_override && $admin->subscription_status === 'pending_payment') {
            return ApiResponse::error(
                'Please complete your payment to activate your account.',
                402,
                ['error_code' => 'payment_required']
            );
        }

        if ($admin && $admin->isBlockedFromAccess()) {
            return ApiResponse::error(
                $admin->subscription_status === 'trial_expired'
                    ? 'Your free trial has expired. Please activate a subscription to continue using Movent CRM.'
                    : 'Your account has been suspended. Please activate a subscription to continue using Movent CRM.',
                402,
                ['error_code' => 'subscription_required', 'subscription_status' => $admin->subscription_status]
            );
        }

        return $next($request);
    }
}
