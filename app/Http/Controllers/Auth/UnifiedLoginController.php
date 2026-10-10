<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Single entry point for the /login page — Company Admin and staff/sub-users
// now share one form. Tries CompanyAdmin credentials first, then User
// credentials, reusing AdminAuthController::tryCredentials()/
// UserAuthController::tryCredentials() so every existing gate (is_active,
// pending_payment, client_account, company active) keeps living in exactly
// one place. Deliberately never touches CompanyAdmin/User table lookups
// itself, and never touches SuperAdmin at all — Super Admin keeps its own
// separate /super-admin/login untouched.
class UnifiedLoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = (new AdminAuthController())->tryCredentials($request->email, $request->password)
            ?? (new UserAuthController())->tryCredentials($request->email, $request->password);

        if (!$result) {
            return ApiResponse::error('Invalid credentials', 401);
        }
        if (!$result['success']) {
            return ApiResponse::error($result['message'], $result['status'], $result['errors'] ?? []);
        }

        return ApiResponse::success($result['data'], 'Login successful');
    }
}
