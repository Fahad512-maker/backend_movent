<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SystemAuditLog;
use App\Rules\ValidPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

// "My Profile" — every sub-user role (Project Manager, Seller, Production
// User, Developer, Designer, QA, Team Member, Invoice/HR/Finance/Compliance
// User, Viewer, ...) shares this same controller since profile self-service
// has nothing to do with role/permissions. Every method resolves the actor
// from the auth guard only; no id is ever accepted from the request body, so
// a sub-user can only ever update themselves — never another user's row.
class ProfileController extends Controller
{
    private function user()
    {
        return auth('sanctum')->user();
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success(new UserResource($this->user()->load([
            'company.modules',
            'company.admin:id,name',
            'companyAssignments.company:id,name,is_active,suspended_at',
            'userCompanyPermissions',
            'permissions',
            'createdBy:id,name',
        ])));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
        ]);

        $old = $user->only(['name', 'phone']);
        $user->update($validated);

        SystemAuditLog::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'profile_updated',
            'module_key'  => 'account',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'old_values'  => $old,
            'new_values'  => $validated,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(new UserResource($user->fresh()->load([
            'company.modules',
            'company.admin:id,name',
            'companyAssignments.company:id,name,is_active,suspended_at',
            'userCompanyPermissions',
            'permissions',
            'createdBy:id,name',
        ])), 'Profile updated');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $this->user();

        if ($user->avatar_path && Storage::exists($user->avatar_path)) {
            Storage::delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('users/' . $user->id . '/avatar', 'public');
        $user->update(['avatar_path' => $path]);

        SystemAuditLog::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'profile_updated',
            'module_key'  => 'account',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['avatar' => 'updated'],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(new UserResource($user->fresh()->load([
            'company.modules',
            'company.admin:id,name',
            'companyAssignments.company:id,name,is_active,suspended_at',
            'userCompanyPermissions',
            'permissions',
            'createdBy:id,name',
        ])), 'Avatar updated');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return ApiResponse::error('Current password is incorrect.', 422);
        }

        $user->update(['password' => $validated['password']]);

        // Never log the password itself — only that the action happened.
        SystemAuditLog::create([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'password_changed',
            'module_key'  => 'auth',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return ApiResponse::success(null, 'Password changed successfully');
    }
}
