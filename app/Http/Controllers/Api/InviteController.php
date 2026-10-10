<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class InviteController extends Controller
{
    // GET /invite/{token}  — fetch invite info (name, email) for pre-filling the form
    public function show(string $token): JsonResponse
    {
        $user = User::where('invite_token', $token)
            ->where('invite_expires_at', '>', now())
            ->first();

        if (!$user) {
            return ApiResponse::error('This invite link is invalid or has expired.', 404);
        }

        return ApiResponse::success([
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    // POST /invite/{token}  — accept invite: set password, activate account
    public function accept(Request $request, string $token): JsonResponse
    {
        $user = User::where('invite_token', $token)
            ->where('invite_expires_at', '>', now())
            ->first();

        if (!$user) {
            return ApiResponse::error('This invite link is invalid or has expired.', 404);
        }

        $validated = $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user->update([
            'password'             => Hash::make($validated['password']),
            'invite_token'         => null,
            'invite_expires_at'    => null,
            'must_change_password' => false,
            'is_active'            => true,
            'status'               => 'active',
        ]);

        return ApiResponse::success(null, 'Account activated. You can now log in.');
    }
}
