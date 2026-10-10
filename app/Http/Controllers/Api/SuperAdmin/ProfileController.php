<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\SystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// "Account Settings" for the Super Admin themselves — the counterpart to
// Api\Admin\ProfileController (Company Admin) and Api\User\ProfileController
// (staff), and deliberately much smaller than either.
//
// Password only. Name and email are NOT editable here: the login check in
// Api\Auth\SuperAdminAuthController compares the submitted email against
// config('super-admin.email') (i.e. the SUPER_ADMIN_EMAIL env value), so a
// self-service email change would lock the account out of its own console.
// The actor is always resolved from the guard — no id is ever read from the
// request, so this can only ever act on the caller.
class ProfileController extends Controller
{
    private function superAdmin(): SuperAdmin
    {
        return auth('super_admin')->user();
    }

    // GET /super-admin/profile
    public function show(): JsonResponse
    {
        $superAdmin = $this->superAdmin();

        return ApiResponse::success([
            'id'                  => $superAdmin->id,
            'name'                => $superAdmin->name,
            'email'               => $superAdmin->email,
            'last_login_at'       => $superAdmin->last_login_at,
            'password_changed_at' => $superAdmin->password_changed_at,
            // True while the account is still on the .env password — worth
            // saying out loud, because that password keeps working until the
            // first change here.
            'uses_env_password'   => $superAdmin->usesConfigPassword(),
            // The console shows the two fields as read-only and says why —
            // better than a disabled input with no explanation.
            'email_locked'        => true,
        ]);
    }

    // POST /super-admin/profile/change-password
    public function changePassword(Request $request): JsonResponse
    {
        $superAdmin = $this->superAdmin();

        // min:8 + confirmed is the same rule the Company Admin and staff
        // password forms use (Api\Admin\ProfileController::changePassword),
        // so all three portals hold one policy rather than three. `different`
        // stops a "change" that changes nothing.
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'password.min'       => 'New password must be at least 8 characters.',
            'password.confirmed' => 'New password and confirm password do not match.',
            'password.different' => 'New password must be different from your current password.',
        ]);

        if (!Hash::check($validated['current_password'], $superAdmin->password)) {
            return ApiResponse::error('Current password is incorrect.', 422);
        }

        // 'hashed' cast on the model does the hashing — passing an already
        // hashed value here would double-hash it.
        //
        // password_changed_at is what makes the change stick: while it is null
        // login still accepts SUPER_ADMIN_PASSWORD from .env and re-syncs the
        // row to it, which would quietly undo this on the next sign-in with
        // the old value (see SuperAdminAuthController::login).
        $superAdmin->update([
            'password'            => $validated['password'],
            'password_changed_at' => now(),
        ]);

        // Every other session is signed out: a password change is also how
        // someone reacts to a suspected leak, and leaving old tokens alive
        // would make that reaction useless. The current token is kept so the
        // admin isn't logged out of the tab they just used.
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $superAdmin->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        // company_id is null — the Super Admin belongs to no tenant, and the
        // per-company feeds (Api\Admin\NotificationController) filter on both
        // company_id and a non-null user_id, so this row stays out of every
        // tenant's notifications. Never log the password itself.
        SystemAuditLog::create([
            'company_id'  => null,
            'user_id'     => null,
            'action'      => 'password_changed',
            'module_key'  => 'auth',
            'entity_type' => 'SuperAdmin',
            'entity_id'   => $superAdmin->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(null, 'Password changed successfully');
    }
}
