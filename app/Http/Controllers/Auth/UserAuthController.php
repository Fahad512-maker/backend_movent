<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UserLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\CompanyAdmin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserAuthController extends Controller
{
    // Returns null when the email/password don't match any User row at all
    // (caller should try the next account type). Returns an array once a row
    // IS matched by credentials — either a blocking error (already-matched
    // account that fails a gate) or a success payload. Shared by login() and
    // Auth\UnifiedLoginController so the gates below exist in exactly one place.
    public function tryCredentials(string $email, string $password): ?array
    {
        $user = User::with(['company.admin', 'permissions', 'companyAssignments.company', 'userCompanyPermissions'])
            ->where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        // Client accounts have their own dedicated portal login (Api\Client\
        // AuthController, /client/login) — letting them through here as well
        // would silently authenticate them with zero company_assignments
        // (clients never have any), landing on a staff dashboard that reads
        // as "no modules assigned" instead of the client portal they meant to use.
        if ($user->role_type === 'client') {
            return [
                'success' => false, 'status' => 403,
                'message' => 'This is a Client account. Please sign in from the Client Portal.',
                'errors'  => ['error_code' => 'client_account'],
            ];
        }

        if (!$user->is_active) {
            return ['success' => false, 'status' => 403, 'message' => 'Your account has been deactivated'];
        }

        if (!$user->company || !$user->company->is_active) {
            return ['success' => false, 'status' => 403, 'message' => 'Your company account is inactive'];
        }

        $admin = $user->company->admin;

        // THE TENANT OWNER IS SWITCHED OFF, SO THE WHOLE TENANT IS.
        //
        // Checked BEFORE the subscription gate below: a deactivated tenant
        // outranks whatever its subscription is doing, and unlike a lapsed
        // subscription nobody here can resolve it — not this user, not even
        // their Company Admin, who is locked out on the same switch
        // (Auth\AdminAuthController::tryCredentials()). Only the Super Admin
        // can reverse it. Every authenticated request is refused the same way
        // too (Http\Middleware\EnsureTenantActive).
        if ($admin && !$admin->is_active) {
            return [
                'success' => false,
                'status'  => 403,
                'message' => CompanyAdmin::TENANT_DEACTIVATED_MESSAGE,
                'errors'  => ['error_code' => CompanyAdmin::TENANT_DEACTIVATED_ERROR_CODE],
            ];
        }

        if ($admin && $admin->isBlockedFromAccess()) {
            return [
                'success' => false, 'status' => 402,
                'message' => $admin->subscription_status === 'trial_expired'
                    ? 'Your free trial has expired. Please ask your Company Admin to activate a subscription to continue using Movent CRM.'
                    : 'Your company account has been suspended. Please ask your Company Admin to activate a subscription to continue using Movent CRM.',
                'errors'  => ['error_code' => 'subscription_required', 'subscription_status' => $admin->subscription_status],
            ];
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('user-token', ['role:user'])->plainTextToken;

        return [
            'success' => true,
            'data'    => ['type' => 'user', 'token' => $token, 'user' => new UserResource($user)],
        ];
    }

    public function login(UserLoginRequest $request): JsonResponse
    {
        $result = $this->tryCredentials($request->email, $request->password);

        if (!$result) {
            return ApiResponse::error('Invalid credentials', 401);
        }
        if (!$result['success']) {
            return ApiResponse::error($result['message'], $result['status'], $result['errors'] ?? []);
        }

        return ApiResponse::success($result['data'], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['company.admin', 'company.modules', 'permissions', 'companyAssignments.company', 'userCompanyPermissions']);

        return ApiResponse::success(new UserResource($user));
    }
}
