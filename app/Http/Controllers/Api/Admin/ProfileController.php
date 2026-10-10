<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\SystemAuditLog;
use App\Rules\ValidPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

// "My Profile" — the Company Admin's own personal identity (name/phone/
// avatar/password). Deliberately separate from Api\Admin\SettingsController,
// which is the shared tenant-wide BUSINESS profile (name/email/phone/
// timezone/logo used on invoices) — editing one must never touch the other.
// Every method resolves the actor from the auth guard only; no id is ever
// accepted from the request body, so an admin can only ever update themselves.
class ProfileController extends Controller
{
    private function admin()
    {
        return auth('admin')->user();
    }

    public function show(): JsonResponse
    {
        // AdminResource's 'companies'/'modules' fields are whenLoaded() —
        // omitted entirely from the JSON unless eager-loaded here, which
        // meant the Profile page never had a company name to show at all.
        return ApiResponse::success(new AdminResource($this->admin()->load(['companies.modules', 'package'])));
    }

    public function update(Request $request): JsonResponse
    {
        $admin = $this->admin();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'phone'    => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            // currency is deliberately NOT accepted here — the system only
            // ever operates in USD now (see company_admins.currency's
            // default and Api\Admin\ClientController's company currency
            // lock), so there is nothing left for an Admin to change.
        ]);

        $old = $admin->only(['name', 'phone']);
        $admin->update($validated);

        SystemAuditLog::create([
            'company_id'  => $admin->companies()->first()?->id,
            'user_id'     => null, // Company Admin actor isn't a `users` row
            'action'      => 'profile_updated',
            'module_key'  => 'account',
            'entity_type' => 'CompanyAdmin',
            'entity_id'   => $admin->id,
            'old_values'  => $old,
            'new_values'  => $validated,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(new AdminResource($admin->fresh()->load(['companies.modules', 'package'])), 'Profile updated');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $admin = $this->admin();

        if ($admin->avatar_path && Storage::exists($admin->avatar_path)) {
            Storage::delete($admin->avatar_path);
        }

        $path = $request->file('avatar')->store('company-admins/' . $admin->id . '/avatar', 'public');
        $admin->update(['avatar_path' => $path]);

        SystemAuditLog::create([
            'company_id'  => $admin->companies()->first()?->id,
            'user_id'     => null,
            'action'      => 'profile_updated',
            'module_key'  => 'account',
            'entity_type' => 'CompanyAdmin',
            'entity_id'   => $admin->id,
            'new_values'  => ['avatar' => 'updated'],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(new AdminResource($admin->fresh()->load(['companies.modules', 'package'])), 'Avatar updated');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $admin = $this->admin();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $admin->password)) {
            return ApiResponse::error('Current password is incorrect.', 422);
        }

        $admin->update(['password' => $validated['password']]);

        // Never log the password itself — only that the action happened.
        SystemAuditLog::create([
            'company_id'  => $admin->companies()->first()?->id,
            'user_id'     => null,
            'action'      => 'password_changed',
            'module_key'  => 'auth',
            'entity_type' => 'CompanyAdmin',
            'entity_id'   => $admin->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(null, 'Password changed successfully');
    }
}
