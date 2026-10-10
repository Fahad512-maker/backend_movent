<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Services\ClientPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * The Client Portal's own "my details" screen.
 *
 * TWO EMAILS, and the whole point of this controller is how they relate:
 *
 *   Contact Email (clients.email) — editable. Where the company writes to this
 *                                   client about their work.
 *   Login Email   (users.email)   — read-only on the form. The credential the
 *                                   portal authenticates against.
 *
 * They are kept in step by this controller, in one direction only: saving a new
 * Contact Email moves the Login Email with it, so the address the client just
 * typed is the address they sign in with next time. The Login Email is never
 * editable on its own — two separately-editable credentials is exactly how a
 * client ends up locked out of an account whose contact address still looks
 * right.
 *
 * Changing the Login Email is changing a credential, so it is gated on the
 * client's CURRENT PASSWORD. Without that, anyone who reaches a signed-in
 * portal tab — a shared machine, a stolen session — can point the login at an
 * address they control and own the account permanently.
 */
class ProfileController extends Controller
{
    private function user(): ?User
    {
        return auth('sanctum')->user();
    }

    /**
     * The client record behind this login.
     *
     * Same precedence as Api\Client\AuthController::login() — a portal-enabled
     * record wins, otherwise the first one — so the profile screen always
     * describes the record the session was actually established against.
     */
    private function client(User $user): ?Client
    {
        return Client::where('user_id', $user->id)->where('portal_access', true)->first()
            ?? Client::where('user_id', $user->id)->first();
    }

    public function show(): JsonResponse
    {
        $user = $this->user();
        $client = $this->client($user);

        if (!$client) {
            return ApiResponse::error('No client profile found', 404);
        }

        return ApiResponse::success([
            'name'         => $client->name,
            'company_name' => $client->company_name,
            'email'        => $client->email,
            'phone'        => $client->phone,
            'address'      => $client->address,
            // Read-only on the form. Sent so the screen can show what the
            // client currently signs in with, and warn them when a Contact
            // Email change is about to move it.
            'login_email'  => $user->email,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user();
        $client = $this->client($user);

        if (!$client) {
            return ApiResponse::error('No client profile found', 404);
        }

        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:200'],
            'phone'   => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'address' => ['nullable', 'string', 'max:500'],
            'email'   => [
                'required', 'email', 'max:255',
                // Unique across LOGINS, not just contact rows: this address is
                // about to become users.email, so it has to be free there.
                // Ignores this client's own login so re-saving an unchanged
                // form never trips it.
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            // Only required when the email is actually changing — see below.
            'current_password' => ['nullable', 'string'],
        ]);

        $newEmail = strtolower(trim($validated['email']));
        $emailChanged = $newEmail !== strtolower((string) $user->email);

        if ($emailChanged) {
            if (empty($validated['current_password'])) {
                return ApiResponse::error('Enter your current password to change your email address.', 422);
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                return ApiResponse::error('That password is incorrect.', 422);
            }

            // Defence in depth over the unique rule above: that only looks at
            // users.email, while this also refuses a Company Admin address and
            // any staff login. Without it a client could point their login at a
            // colleague's address and quietly take the account over.
            if (ClientPortalService::emailBelongsToAnotherAccount($newEmail)) {
                return ApiResponse::error('This email is already registered to another account.', 422);
            }
        }

        // One transaction: the two email columns must never be left disagreeing
        // with each other, which is the exact state this screen exists to
        // prevent.
        DB::transaction(function () use ($client, $user, $validated, $newEmail, $emailChanged) {
            $client->update([
                'name'    => $validated['name'],
                'phone'   => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'email'   => $newEmail,
            ]);

            if ($emailChanged) {
                $user->update(['email' => $newEmail]);
            }
        });

        return ApiResponse::success(
            [
                'name'        => $client->name,
                'email'       => $client->email,
                'phone'       => $client->phone,
                'address'     => $client->address,
                'login_email' => $user->fresh()->email,
                'email_changed' => $emailChanged,
            ],
            $emailChanged
                ? 'Saved. Use your new email address the next time you sign in.'
                : 'Your details have been saved.'
        );
    }
}
