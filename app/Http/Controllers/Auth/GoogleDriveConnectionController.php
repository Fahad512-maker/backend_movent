<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveService;
use App\Support\FrontendUrl;
use App\Support\SafeReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Google Drive OAuth callback — necessarily unauthenticated, exactly
 * like Auth\GoogleAuthController::callback() for the login flow: Google
 * redirects the bare browser here with no way to carry a Bearer token, so
 * "which company is connecting" has to travel through the `state` param
 * instead.
 *
 * That `state` value is minted and stashed in Cache by
 * Api\Admin\StorageController::connectUrl() (an authenticated request, made
 * right before the browser navigates away to Google) and is pulled — and
 * therefore consumed, single-use — here. A forged or replayed state simply
 * fails to resolve to anything and the request is refused; nothing about
 * WHICH company gets connected is ever taken from this request itself.
 */
class GoogleDriveConnectionController extends Controller
{
    public function callback(Request $request): RedirectResponse
    {
        // Resolved FIRST, before anything else, so every exit below —
        // success or failure — can return the admin to wherever they
        // started. Google echoes `state` back unchanged on every outcome,
        // including the admin clicking Cancel/Deny, so this is available
        // even on the earliest possible failure. Cache::pull() consumes it
        // once regardless of what happens next: a state is single-use
        // whether the round trip that spent it succeeded or not, so it can
        // never be replayed either way.
        $state   = (string) $request->query('state');
        $context = $state ? Cache::pull("google_drive_connect:{$state}") : null;
        // Re-validated here too (defense in depth) even though
        // StorageController::connectUrl() already checked it before storing
        // — this callback must never trust a stored value blindly either.
        $returnTo = $context['return_to'] ?? null;
        $returnTo = is_string($returnTo) && SafeReturnPath::isSafe($returnTo) ? $returnTo : null;

        if ($request->filled('error')) {
            // The admin clicked "Cancel"/"Deny" on Google's consent screen.
            return $this->redirectWithResult('error', 'authorization_denied', $returnTo);
        }

        if (!$context || empty($context['company_id'])) {
            // No context to recover a return_to from either — Settings is
            // the only safe destination left.
            return $this->redirectWithResult('error', 'session_expired');
        }

        $code = (string) $request->query('code');
        if (!$code) {
            return $this->redirectWithResult('error', 'authorization_denied', $returnTo);
        }

        try {
            $tokens = app(GoogleDriveService::class)->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning('[google-drive] callback exchange failed', ['error' => $e->getMessage()]);
            return $this->redirectWithResult('error', 'authorization_failed', $returnTo);
        }

        if (!$tokens['refresh_token']) {
            // No refresh token means this connection cannot outlive the
            // first hour — refuse rather than silently create a connection
            // that will flip to 'error' status on its own within the hour.
            return $this->redirectWithResult('error', 'no_refresh_token', $returnTo);
        }

        $existing = GoogleDriveConnection::where('company_id', $context['company_id'])->first();

        // A Drive folder id only ever resolves inside the Google account it
        // was created in. Reconnecting the SAME account (e.g. after a token
        // error) can safely reuse the cached folder tree — but "Change
        // Google Account" to a genuinely different one means those ids are
        // now meaningless in the new account, so they are cleared and
        // GoogleDriveService::folderFor() recreates the tree lazily under
        // the new account the next time each module needs it. Files already
        // uploaded under the PREVIOUS account are untouched either way —
        // their own attachment rows keep their own
        // google_drive_file_id/folder_id regardless of what this row does.
        $accountChanged = $existing && $existing->google_account_id
            && $existing->google_account_id !== $tokens['google_id'];

        GoogleDriveConnection::updateOrCreate(
            ['company_id' => $context['company_id']],
            array_merge([
                'connected_by_admin_id' => $context['admin_id'] ?? null,
                'google_account_email'  => $tokens['email'],
                'google_account_id'     => $tokens['google_id'],
                'credentials'           => [
                    'access_token'  => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_at'    => $tokens['expires_at'],
                ],
                'status'          => 'connected',
                'last_error'      => null,
                'connected_at'    => now(),
                'disconnected_at' => null,
                // Whatever quota was last recorded (a previous account, or a
                // now-stale figure from before this connection errored out)
                // no longer describes the connection that exists after this
                // point — cleared so the next Settings view fetches fresh
                // (Section 8: "storage information should start tracking
                // again" on reconnect).
                'quota_total_bytes' => null,
                'quota_used_bytes'  => null,
                'quota_synced_at'   => null,
            ], $accountChanged ? ['root_folder_id' => null, 'folder_ids' => null] : [])
        );

        return $this->redirectWithResult('connected', null, $returnTo);
    }

    /**
     * Where the browser goes next. $returnTo — the exact page "Connect
     * Google Drive" was clicked from, recovered from the OAuth state — wins
     * whenever it is present; Settings is only ever the fallback for the
     * cases with no context to return to at all (state expired/missing) or
     * where none was ever supplied (the Settings page's own Connect/Change
     * Account buttons, which have nowhere else to go back to).
     *
     * $result/$reason are appended as query params onto WHICHEVER
     * destination is used, merged with that destination's own existing
     * query string rather than overwriting it — landing back on
     * "/projects/45?tab=documents" must keep ?tab=documents, not lose it.
     */
    private function redirectWithResult(string $result, ?string $reason = null, ?string $returnTo = null): RedirectResponse
    {
        $destination = $returnTo ?? 'settings?tab=storage';

        [$path, $query] = str_contains($destination, '?')
            ? explode('?', $destination, 2)
            : [$destination, ''];

        parse_str($query, $params);
        $params['drive'] = $result;
        if ($reason) {
            $params['reason'] = $reason;
        } else {
            unset($params['reason']);
        }

        return redirect()->away(FrontendUrl::to($path . '?' . http_build_query($params)));
    }
}
