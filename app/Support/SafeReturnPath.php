<?php

namespace App\Support;

/**
 * Validates a frontend path a caller wants to be redirected back to after an
 * external round trip (right now: Google Drive OAuth — see
 * Api\Admin\StorageController::connectUrl() and Auth\
 * GoogleDriveConnectionController::callback()).
 *
 * The one job this class has: refuse anything that could turn into an open
 * redirect. A path is safe only if it is unambiguously RELATIVE to this
 * app's own frontend — never an absolute URL, never protocol-relative
 * (`//evil.com` is parsed by every browser as `https://evil.com`), never
 * carrying a scheme or backslash trick. Nothing here tries to also enforce
 * "is this a real route" — that would mean hand-maintaining a route
 * allow-list that drifts from the frontend the moment a page is renamed;
 * refusing anything that isn't shaped like an in-app path is what actually
 * closes the security hole, and a caller passing a real-but-typo'd path
 * simply lands on a 404, same as typing it in the address bar.
 */
class SafeReturnPath
{
    private const MAX_LENGTH = 500;

    public static function isSafe(?string $path): bool
    {
        if (!$path || $path === '') {
            return false;
        }

        if (strlen($path) > self::MAX_LENGTH) {
            return false;
        }

        // Must start with exactly one '/' — "//host/..." is protocol-relative
        // (every browser treats it as an absolute URL to that host) and
        // "/\evil.com" / "\\evil.com" are the same trick via a backslash,
        // which some older browsers still normalize to a forward slash.
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }

        // No embedded scheme anywhere in the string ("/redirect?to=http://…"
        // is harmless to US — we never fetch it — but refusing it outright
        // means this class never has to reason about what a downstream
        // consumer might do with the value).
        if (preg_match('#^/[^\s]*$#u', $path) !== 1) {
            return false; // no whitespace/control characters
        }
        if (stripos($path, 'javascript:') !== false || preg_match('#[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return false;
        }

        return true;
    }

    /** Returns $path if safe, otherwise $fallback (never null, so callers always have somewhere to send the browser). */
    public static function sanitize(?string $path, string $fallback): string
    {
        return self::isSafe($path) ? $path : $fallback;
    }
}
