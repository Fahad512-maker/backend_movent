<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Resources\AdminResource;
use App\Models\CompanyAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    // Returns null when the email/password don't match any CompanyAdmin row
    // at all (caller should try the next account type). Returns an array once
    // a row IS matched by credentials — either a blocking error (already-
    // matched account that fails a gate) or a success payload. Shared by
    // login() and Auth\UnifiedLoginController so the gates below exist in
    // exactly one place.
    public function tryCredentials(string $email, string $password): ?array
    {
        $admin = CompanyAdmin::where('email', $email)->first();

        if (!$admin || !Hash::check($password, $admin->password)) {
            return null;
        }

        // Deactivated by the Super Admin — no token is issued, and unlike the
        // payment gate below there is nothing this admin can do about it
        // themselves. Every authenticated request is refused the same way too
        // (Http\Middleware\EnsureTenantActive), so a token minted before the
        // switch was flipped is no use either.
        if (!$admin->is_active) {
            return [
                'success' => false,
                'status'  => 403,
                'message' => CompanyAdmin::DEACTIVATED_MESSAGE,
                'errors'  => ['error_code' => CompanyAdmin::DEACTIVATED_ERROR_CODE],
            ];
        }

        // A signup that chose the paid path (start_type='paid' in
        // PublicController::register()) starts as 'pending_payment' — a
        // Sanctum token is issued at registration itself so the user can
        // immediately reach the /payment page, but if they abandon that and
        // come back to log in later without ever paying, block them here.
        // 'trial' (genuine free-trial signup) and 'active' (paid) both pass.
        // access_override wins here too — see EnsureSubscriptionActive's
        // matching note for why this specific status needs its own check.
        if (!$admin->access_override && $admin->subscription_status === 'pending_payment') {
            return [
                'success' => false, 'status' => 402,
                'message' => 'Please complete your payment to activate your account.',
                'errors'  => ['error_code' => 'payment_required'],
            ];
        }

        $admin->update(['last_login_at' => now()]);

        $token = $admin->createToken('admin-token', ['role:admin'])->plainTextToken;

        return [
            'success' => true,
            'data'    => [
                'type'  => 'admin',
                'token' => $token,
                'admin' => new AdminResource($admin->load('companies.modules', 'package')),
            ],
        ];
    }

    public function login(AdminLoginRequest $request): JsonResponse
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
        return ApiResponse::success(
            new AdminResource($request->user()->load('companies.modules', 'package'))
        );
    }
}
