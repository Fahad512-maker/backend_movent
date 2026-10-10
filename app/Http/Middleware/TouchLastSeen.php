<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps users.last_seen_at current, so presence can be read off real activity
 * rather than guessed.
 *
 * Runs after the response is composed (never delaying it) and only for a
 * Sanctum-authenticated `users` row — Company Admin and Super Admin sessions
 * are a different table and have no presence to show anywhere.
 *
 * WRITE THROTTLE. A busy seller makes several requests a second; stamping each
 * one would mean a write per request for no gain, since the only consumer asks
 * "within the last few minutes?". One write per THROTTLE_SECONDS is plenty and
 * keeps this off the hot path.
 *
 * updateQuietly() on purpose: presence is not a change to the account, so it
 * must not bump updated_at (which audit trails and "recently edited" listings
 * read) or fire model events.
 */
class TouchLastSeen
{
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return;
        }

        if ($user->last_seen_at && $user->last_seen_at->gt(now()->subSeconds(self::THROTTLE_SECONDS))) {
            return;
        }

        $user->forceFill(['last_seen_at' => now()])->updateQuietly();
    }
}
