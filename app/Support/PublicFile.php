<?php

namespace App\Support;

/**
 * Builds the browser-facing URL for a file stored on the `public` disk.
 *
 * Replaces Storage::url(), which produced "{APP_URL}/storage/{path}" and so
 * quietly depended on three things being right at once:
 *   1. a public/storage symlink existing (storage:link can't run on every
 *      host — symlink() is disabled on plenty of shared hosting);
 *   2. APP_URL being correct; and
 *   3. the document root actually being public/ — it isn't on the live host,
 *      which serves the app from a /public sub-path, so /storage/... 404'd
 *      even when the symlink was there.
 *
 * Routing through /api/files/{path} drops all three: PHP reads the file
 * itself (nothing needs to be web-reachable, so no symlink and no web-server
 * permissions), and url() derives the host AND the app's base path from the
 * incoming request, so the live /public prefix appears on its own.
 *
 * Absolute, not relative, on purpose: logo_url and attachment_url are used
 * straight in <img src> / <a href> on the frontend without passing through
 * resolveAvatarUrl(), so a relative path would resolve against the Next.js
 * origin instead of the API's and 404.
 */
class PublicFile
{
    public static function url(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return url('/api/files/' . ltrim($path, '/'));
    }
}
