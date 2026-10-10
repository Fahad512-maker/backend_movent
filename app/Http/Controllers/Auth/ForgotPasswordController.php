<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Password reset for staff/sub-users (App\Models\User) only. See
// AdminForgotPasswordController for the parallel Company Admin flow.
class ForgotPasswordController extends Controller
{
    private const TOKEN_TABLE = 'password_reset_tokens';
    private const EXPIRY_MINUTES = 60;
    private const GENERIC_SENT_MESSAGE = 'If that email is registered, a password reset link has been sent.';
    private const GENERIC_INVALID_MESSAGE = 'This password reset link is invalid or has expired.';

    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        // Never reveal whether the email is registered — same response either way.
        if ($user) {
            $token = Str::random(64);

            DB::table(self::TOKEN_TABLE)->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            // A Client Portal login is an ordinary User row (role_type='client'),
            // so this flow already resets their password correctly — it always
            // has. What it got wrong was the destination: the staff
            // /reset-password page is wrapped in the marketing navbar and the
            // blue brand, and every link on it leads into an app a portal
            // client has no account on. They get the portal's own page instead,
            // which posts to this very same endpoint.
            //
            // Decided from the user row, never from the request: letting a
            // caller pick the client variant for any address would turn the
            // deliberately generic "link sent" response above into a way to
            // test whether an email is a portal client.
            $page = $user->role_type === 'client' ? 'client/reset-password' : 'reset-password';

            $resetUrl = FrontendUrl::to($page . '?token=' . $token . '&email=' . urlencode($user->email));

            try {
                Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $user->name));
            } catch (\Throwable $e) {
                Log::error('Password reset email failed to send', ['email' => $user->email, 'error' => $e->getMessage()]);
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

        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return ApiResponse::error(self::GENERIC_INVALID_MESSAGE, 400);
        }

        $user->update(['password' => $validated['password']]);

        DB::table(self::TOKEN_TABLE)->where('email', $validated['email'])->delete();

        return ApiResponse::success(null, 'Your password has been reset. You can now log in.');
    }
}
