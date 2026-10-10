<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Auth\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Send me a reset link" for the Client Portal.
 *
 * Deliberately DIFFERENT from the staff flow in one respect, on request: this
 * one tells the visitor when the address is not a registered portal client, so
 * the error appears under the field instead of a cheerful "check your inbox"
 * for an email that will never arrive.
 *
 * THE TRADE-OFF, stated plainly because it is a real one: the staff endpoint
 * answers identically whether or not an address exists, specifically so nobody
 * can use it to discover which addresses are registered. Answering honestly
 * here turns this form into exactly that oracle for portal clients. It is
 * accepted because a company's client list is a small, largely known set and
 * the usability win is concrete — not because the concern does not apply. The
 * route is rate-limited to blunt bulk probing; do not remove that.
 *
 * The staff endpoint keeps its generic response. Nothing about it changed.
 *
 * Only the CHECK is new. The token, its hashing, the one-hour expiry and the
 * email all come from Auth\ForgotPasswordController — there is one reset flow
 * in this app and this is a front door onto it, not a second implementation.
 */
class ForgotPasswordController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        if (!self::isRegisteredPortalClient($request->input('email'))) {
            // A 422 with an `email` key so the field renders its own error —
            // the page keeps the address the visitor typed and never navigates.
            throw ValidationException::withMessages([
                'email' => 'No Client Portal account is registered with this email address.',
            ]);
        }

        return (new StaffForgotPasswordController())->sendResetLink($request);
    }

    /**
     * Mirrors what Api\Client\AuthController::login() actually requires, so the
     * answer here can never disagree with whether they can sign in:
     * a role_type='client' User, active, with a portal-enabled Client record.
     *
     * A client whose portal_access was switched off is reported as NOT
     * registered — truthfully, since a reset link would only lead them to a
     * login that refuses them anyway.
     */
    private static function isRegisteredPortalClient(string $email): bool
    {
        $user = User::where('email', $email)
            ->where('role_type', 'client')
            ->where('is_active', true)
            ->first();

        if (!$user) {
            return false;
        }

        return Client::where('user_id', $user->id)
            ->where('portal_access', true)
            ->exists();
    }
}
