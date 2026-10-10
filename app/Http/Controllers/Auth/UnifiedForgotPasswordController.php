<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CompanyAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Single entry point for the /forgot-password page's "send me a reset link"
// form, mirroring Auth\UnifiedLoginController — Company Admin and staff/sub-
// users now share one form. Same admin-first precedence as login: if the
// email belongs to a CompanyAdmin, they get that reset link (still built by
// AdminForgotPasswordController, still pointing at /admin/reset-password —
// unchanged); otherwise falls back to the staff/User flow untouched. Neither
// sub-controller's logic is duplicated, just routed to.
class UnifiedForgotPasswordController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $isAdmin = CompanyAdmin::where('email', $request->email)->exists();

        return $isAdmin
            ? (new AdminForgotPasswordController())->sendResetLink($request)
            : (new ForgotPasswordController())->sendResetLink($request);
    }
}
