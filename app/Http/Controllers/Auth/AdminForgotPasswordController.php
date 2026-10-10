<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\CompanyAdmin;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Password reset for Company Admin (App\Models\CompanyAdmin) only. Mirrors
// ForgotPasswordController (staff/sub-users) — kept separate rather than
// shared/parameterized, matching this codebase's existing Admin/User
// separation convention.
class AdminForgotPasswordController extends Controller
{
    private const TOKEN_TABLE = 'admin_password_reset_tokens';
    private const EXPIRY_MINUTES = 60;
    private const GENERIC_SENT_MESSAGE = 'If that email is registered, a password reset link has been sent.';
    private const GENERIC_INVALID_MESSAGE = 'This password reset link is invalid or has expired.';

    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $admin = CompanyAdmin::where('email', $request->email)->first();

        // Never reveal whether the email is registered — same response either way.
        if ($admin) {
            $token = Str::random(64);

            DB::table(self::TOKEN_TABLE)->updateOrInsert(
                ['email' => $admin->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = FrontendUrl::to('admin/reset-password?token=' . $token . '&email=' . urlencode($admin->email));

            try {
                Mail::to($admin->email)->send(new PasswordResetMail($resetUrl, $admin->name));
            } catch (\Throwable $e) {
                Log::error('Admin password reset email failed to send', ['email' => $admin->email, 'error' => $e->getMessage()]);
            }
        }

        return ApiResponse::success(null, self::GENERIC_SENT_MESSAGE);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => ['required', 'email'],
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $row = DB::table(self::TOKEN_TABLE)->where('email', $validated['email'])->first();

        if (!$row || !Hash::check($validated['token'], $row->token) || abs(now()->diffInMinutes($row->created_at)) > self::EXPIRY_MINUTES) {
            return ApiResponse::error(self::GENERIC_INVALID_MESSAGE, 400);
        }

        $admin = CompanyAdmin::where('email', $validated['email'])->first();
        if (!$admin) {
            return ApiResponse::error(self::GENERIC_INVALID_MESSAGE, 400);
        }

        $admin->update(['password' => $validated['password']]);

        DB::table(self::TOKEN_TABLE)->where('email', $validated['email'])->delete();

        return ApiResponse::success(null, 'Your password has been reset. You can now log in.');
    }
}
