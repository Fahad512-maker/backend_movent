<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CompanyAdmin;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// Unified Google OAuth entry point for the /login page — Company Admin and
// staff/sub-users now share one Google button. Checks CompanyAdmin first
// (via AdminGoogleAuthController::tryAdmin(), same gates as its own
// standalone flow), then falls back to the staff/User check below. Never
// touches Super Admin (no Google login exists for that tier).
class GoogleAuthController extends Controller
{
    private const EXCHANGE_TTL_SECONDS = 60;

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return $this->redirectWithError('oauth_failed');
        }

        return $this->handleResolvedGoogleUser($googleUser, $request);
    }

    // Isolated from callback() so it can be exercised in tests with a hand-built
    // Socialite user object, without a real Google consent round trip.
    public function handleResolvedGoogleUser(SocialiteUser $googleUser, Request $request): RedirectResponse
    {
        $emailVerified = $googleUser->user['email_verified'] ?? false;
        if (!$emailVerified) {
            return $this->redirectWithError('email_not_verified');
        }

        $adminResult = (new AdminGoogleAuthController())->tryAdmin($googleUser, $request);
        if ($adminResult) {
            if (!$adminResult['success']) {
                return $this->redirectWithError($adminResult['errorCode']);
            }
            $code = Str::random(64);
            Cache::put("google_login_exchange:{$code}", $adminResult['data'], self::EXCHANGE_TTL_SECONDS);
            return redirect()->away(FrontendUrl::to('login?google_exchange=' . $code));
        }

        // Same eager-loads as UserAuthController::login() so the resulting
        // UserResource is identical in shape to a password login.
        $user = User::with(['company.admin', 'permissions', 'companyAssignments.company', 'userCompanyPermissions'])
            ->where('email', $googleUser->getEmail())->first();

        // Mirrors UserAuthController::login()'s exact gates — never auto-create.
        if (!$user) {
            return $this->redirectWithError('not_registered');
        }

        // Client accounts have their own dedicated portal login — see the
        // matching guard in UserAuthController::login() for why.
        if ($user->role_type === 'client') {
            return $this->redirectWithError('client_account');
        }

        if (!$user->is_active) {
            return $this->redirectWithError('inactive_account');
        }

        if (!$user->company || !$user->company->is_active) {
            return $this->redirectWithError('inactive_company');
        }

        // Mirrors UserAuthController::tryCredentials()'s gates, in the same
        // order: the tenant owner being switched off by the Super Admin
        // outranks the subscription check below, because nobody inside this
        // tenant can resolve it.
        if ($user->company->admin && !$user->company->admin->is_active) {
            return $this->redirectWithError(CompanyAdmin::TENANT_DEACTIVATED_ERROR_CODE);
        }

        if ($user->company->admin && $user->company->admin->isBlockedFromAccess()) {
            return $this->redirectWithError('subscription_required');
        }

        $wasJustLinked = empty($user->google_id);
        if ($wasJustLinked) {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('user-token', ['role:user'])->plainTextToken;

        SystemAuditLog::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'google_login',
            'module_key'  => 'auth',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => [
                'email'        => $user->email,
                'google_id'    => $googleUser->getId(),
                'linked_now'   => $wasJustLinked,
                'login_method' => 'google_oauth',
            ],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        $code = Str::random(64);
        Cache::put("google_login_exchange:{$code}", [
            'type'  => 'user',
            'token' => $token,
            'user'  => (new UserResource($user))->resolve(),
        ], self::EXCHANGE_TTL_SECONDS);

        return redirect()->away(FrontendUrl::to('login?google_exchange=' . $code));
    }

    public function exchange(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $payload = Cache::pull("google_login_exchange:{$request->code}");

        if (!$payload) {
            return ApiResponse::error('This login link has expired or already been used. Please try again.', 410);
        }

        return ApiResponse::success($payload, 'Login successful');
    }

    private function redirectWithError(string $code): RedirectResponse
    {
        return redirect()->away(FrontendUrl::to('login?google_error=' . $code));
    }
}
