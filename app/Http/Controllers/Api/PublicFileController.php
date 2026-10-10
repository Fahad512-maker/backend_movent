<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from the `public` disk over /api/files/{path}.
 *
 * Exists so uploaded images (avatars, company logos, support-ticket
 * attachments) display without a public/storage symlink, without APP_URL
 * being correct, and without the document root having to be public/ — see
 * App\Support\PublicFile for why each of those used to matter. /api/* is the
 * one path both environments already forward to Laravel (the Next.js dev
 * proxy, and the live host's /public/api/*), so riding on it works
 * everywhere with no per-host setup.
 *
 * Deliberately unauthenticated: every file it can reach was already public
 * (avatars and logos render in <img> tags, ticket attachments are linked to
 * clients). Enumeration is prevented by the filenames themselves — store()
 * assigns a random hash name — not by the route.
 */
class PublicFileController extends Controller
{
    // Only files written by an upload flow are servable. Anything else on the
    // disk (or anything a traversal attempt might reach) is refused outright
    // rather than sniffed, so this can never turn into a general file reader.
    private const ALLOWED_EXTENSIONS = [
        // Raster/vector images. avif/heic/heif/tiff are here because the
        // brand-logo upload accepts anything the server detects as image/*
        // (see Api\{Admin,User}\BrandController) — a phone photo is usually
        // HEIC, and a file that uploads but then can't be served back is
        // worse than one that was refused outright.
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
        'avif', 'heic', 'heif', 'tif', 'tiff',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
    ];

    public function show(string $path): Response
    {
        // Traversal guard. The route already forbids a literal '..' segment,
        // but the check is repeated here because this method is the thing
        // that actually opens a file: decode first (so %2e%2e can't sneak
        // past), reject any '..' segment, then confirm the resolved real path
        // is still inside the disk root — belt and braces, since a single
        // miss here would expose .env.
        $path = ltrim(str_replace('\\', '/', urldecode($path)), '/');

        if ($path === '' || in_array('..', explode('/', $path), true)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            abort(404);
        }

        $root = realpath($disk->path(''));
        $real = realpath($disk->path($path));

        if ($root === false || $real === false || !str_starts_with($real, $root)) {
            abort(404);
        }

        // Uploads are hash-named and never rewritten in place, so they can be
        // cached hard — without this every avatar is re-fetched on every page
        // render, which is the one real cost of serving through PHP.
        $headers = [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            // Never let a browser second-guess the stored type and, say, run
            // an .svg uploaded under a .png name as markup.
            'X-Content-Type-Options' => 'nosniff',
        ];

        // An SVG is markup, not pixels: it can carry <script>, and while that
        // never runs inside an <img> tag (how every logo/avatar here is
        // rendered), it WOULD run if someone opened the file URL directly.
        // These two headers close that off — nothing external loads, no
        // script executes, and the file can't frame anything — so SVG logos
        // are safe to accept (see the brand logo rules in
        // Api\{Admin,User}\BrandController).
        if ($extension === 'svg') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return $disk->response($path, null, $headers);
    }
}
