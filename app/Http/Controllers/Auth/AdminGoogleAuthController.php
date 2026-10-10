<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\CompanyAdmin;
use App\Models\SystemAuditLog;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// Google OAuth login for Company Admin only (App\Models\CompanyAdmin) — a
// separate flow from GoogleAuthController (staff/sub-users), using the same
// OAuth client but a distinct callback URL so the two guards never mix up
// tokens. Existing accounts only: matched by email, never auto-created.
class AdminGoogleAuthController extends Controller
{
    private const EXCHANGE_TTL_SECONDS = 60;

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl(config('services.google.admin_redirect'))
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl(config('services.google.admin_redirect'))
                ->user();
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

        $admin = CompanyAdmin::where('email', $googleUser->getEmail())->first();

        // Mirrors AdminAuthController::login()'s exact gates — never auto-create.
        if (!$admin) {
            return $this->redirectWithError('not_registered');
        }

        // Its own code, not the shared 'inactive_account': only a Company
        // Admin can be switched off by the SUPER Admin, and only they should
        // be told to contact them (see CompanyAdmin::DEACTIVATED_ERROR_CODE).
        if (!$admin->is_active) {
            return $this->redirectWithError(CompanyAdmin::DEACTIVATED_ERROR_CODE);
        }

        // Mirrors AdminAuthController::login()'s payment gate, including its
        // access_override carve-out.
        if (!$admin->access_override && $admin->subscription_status === 'pending_payment') {
            return $this->redirectWithError('payment_required');
        }

        $wasJustLinked = empty($admin->google_id);
        if ($wasJustLinked) {
            $admin->update(['google_id' => $googleUser->getId()]);
        }

        $admin->update(['last_login_at' => now()]);

        $token = $admin->createToken('admin-token', ['role:admin'])->plainTextToken;

        SystemAuditLog::create([
            'company_id'  => $admin->companies()->first()?->id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin isn't a User row
            'action'      => 'google_login',
            'module_key'  => 'auth',
            'entity_type' => 'CompanyAdmin',
            'entity_id'   => $admin->id,
            'new_values'  => [
                'email'        => $admin->email,
                'google_id'    => $googleUser->getId(),
                'linked_now'   => $wasJustLinked,
                'login_method' => 'google_oauth',
            ],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        $code = Str::random(64);
        Cache::put("google_login_exchange:{$code}", [
            'token' => $token,
            'admin' => (new AdminResource($admin->load('companies.modules', 'package')))->resolve(),
        ], self::EXCHANGE_TTL_SECONDS);

        return redirect()->away(FrontendUrl::to('admin/login?google_exchange=' . $code));
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
        return redirect()->away(FrontendUrl::to('admin/login?google_error=' . $code));
    }

    // Resolve half of handleResolvedGoogleUser() above, without redirecting —
    // lets GoogleAuthController (the now-unified Google entry point on
    // /login) check "is this Google email a CompanyAdmin?" first, reusing
    // these exact gates, before falling back to the staff/User check. Returns
    // null when no CompanyAdmin matches this email at all (caller should try
    // the next account type); returns an array once a row IS matched — either
    // a blocking error code or a success payload.
    public function tryAdmin(SocialiteUser $googleUser, Request $request): ?array
    {
        $admin = CompanyAdmin::where('email', $googleUser->getEmail())->first();
        if (!$admin) {
            return null;
        }

        // Its own code — see the note on the same gate in callback() above.
        if (!$admin->is_active) {
            return ['success' => false, 'errorCode' => CompanyAdmin::DEACTIVATED_ERROR_CODE];
        }

        if (!$admin->access_override && $admin->subscription_status === 'pending_payment') {
            return ['success' => false, 'errorCode' => 'payment_required'];
        }

        $wasJustLinked = empty($admin->google_id);
        if ($wasJustLinked) {
            $admin->update(['google_id' => $googleUser->getId()]);
        }

        $admin->update(['last_login_at' => now()]);

        $token = $admin->createToken('admin-token', ['role:admin'])->plainTextToken;

        SystemAuditLog::create([
            'company_id'  => $admin->companies()->first()?->id,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin isn't a User row
            'action'      => 'google_login',
            'module_key'  => 'auth',
            'entity_type' => 'CompanyAdmin',
            'entity_id'   => $admin->id,
            'new_values'  => [
                'email'        => $admin->email,
                'google_id'    => $googleUser->getId(),
                'linked_now'   => $wasJustLinked,
                'login_method' => 'google_oauth',
            ],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return [
            'success' => true,
            'data'    => [
                'type'  => 'admin',
                'token' => $token,
                'admin' => (new AdminResource($admin->load('companies.modules', 'package')))->resolve(),
            ],
        ];
    }
}
