<?php

namespace App\Http\Controllers\Api\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperAdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $config = config('super-admin');

        // Fail closed when the environment doesn't carry the credentials.
        // config/super-admin.php deliberately has no fallback values any more,
        // so a missing SUPER_ADMIN_EMAIL/PASSWORD means "no super admin login
        // is configured here" — never "let anything through". Checked before
        // the comparisons below so a null config value can't be matched by a
        // crafted request, and before firstOrCreate() so a row with a null
        // email is never written.
        if (empty($config['email']) || empty($config['password'])) {
            return ApiResponse::error('Super admin sign-in is not configured on this server.', 503);
        }

        if ($request->email !== $config['email']) {
            return ApiResponse::error('Invalid credentials', 401);
        }

        // Find or create the DB record used for Sanctum token storage
        $superAdmin = SuperAdmin::firstOrCreate(
            ['email' => $config['email']],
            ['name' => $config['name'], 'password' => Hash::make($config['password'])]
        );

        // Verify password: the stored hash always, and the config plain-text
        // only while this account has never set its own password.
        //
        // That second half is the bootstrap path — a fresh install signs in
        // with SUPER_ADMIN_PASSWORD from .env and the row is synced to it. But
        // once the Super Admin changes their password in the console
        // (password_changed_at set, see Api\SuperAdmin\ProfileController), the
        // env value MUST stop working: otherwise the old password would still
        // log in and the sync below would overwrite the new one with it,
        // silently undoing the change.
        $validByHash   = Hash::check($request->password, $superAdmin->password);
        $validByConfig = $superAdmin->usesConfigPassword()
            && $request->password === $config['password'];

        if (!$validByConfig && !$validByHash) {
            return ApiResponse::error('Invalid credentials', 401);
        }

        // Sync DB password if config changed
        if ($validByConfig && !$validByHash) {
            $superAdmin->update(['password' => Hash::make($config['password'])]);
        }

        $superAdmin->update(['last_login_at' => now()]);

        $token = $superAdmin->createToken('super-admin-token', ['role:super_admin'])->plainTextToken;

        return ApiResponse::success([
            'token'       => $token,
            'super_admin' => [
                'id'    => $superAdmin->id,
                'name'  => $superAdmin->name,
                'email' => $superAdmin->email,
            ],
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success(null, 'Logged out');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'id'    => $request->user()->id,
            'name'  => $request->user()->name,
            'email' => $request->user()->email,
        ]);
    }
}
