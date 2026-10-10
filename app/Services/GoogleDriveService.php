<?php

namespace App\Services;

use App\Models\Company;
use App\Models\GoogleDriveConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything that talks to Google: the OAuth dance, folder management, and
 * the Drive v3 API calls themselves (upload, download, delete).
 *
 * Deliberately plain REST calls over Laravel's `Http` facade rather than the
 * official `google/apiclient` SDK — the same choice this codebase already
 * made for Stripe/PayPal/Authorize.net (see App\Services\PaymentGateways):
 * one dependency-free class per external API instead of a large generic
 * client, and every call site stays readable as "an HTTP request to Google",
 * not a chain of SDK builder objects.
 *
 * A SEPARATE OAuth client from the one 'Sign in with Google' already uses
 * (config('services.google')) — see the long note on config('services.
 * google_drive') for why, and the setup steps for creating it in Google
 * Cloud Console. Scope is `drive.file` (+ `userinfo.email` to show which
 * account is connected) — Section 3's "minimum scopes required": this app
 * only ever touches files it created itself, never the connected account's
 * other Drive content.
 *
 * The connected Google account is a property of the COMPANY's storage
 * integration, never of any CRM user — it does not need to, and usually
 * will not, match the Company Admin's own login email. Nothing here ever
 * checks that it does.
 */
class GoogleDriveService
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API_BASE  = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';

    // Above this, use a resumable upload session instead of one multipart
    // request — avoids holding the whole file in memory twice (once as the
    // UploadedFile stream, once as a request body string) for anything
    // sizeable, and survives a dropped connection mid-upload by resuming
    // rather than restarting. 5 MB is comfortably under PHP's usual
    // memory_limit even for a simple upload, so small files skip the extra
    // round trip.
    private const RESUMABLE_THRESHOLD_BYTES = 5 * 1024 * 1024;

    public static function isConfigured(): bool
    {
        return filled(config('services.google_drive.client_id'))
            && filled(config('services.google_drive.client_secret'))
            && filled(config('services.google_drive.redirect'));
    }

    // ── OAuth ────────────────────────────────────────────────────────────────

    /**
     * The URL to send the Company Admin's browser to. $state is an opaque,
     * single-use token the caller has already stashed (see
     * Api\Admin\StorageController::connectUrl()) — Google echoes it back on
     * the callback unmodified, which is what lets the callback (a bare,
     * unauthenticated browser redirect) recover WHICH company is connecting.
     *
     * prompt=consent + access_type=offline together are what guarantee a
     * refresh_token comes back even on a account that has authorized this
     * app before — Google only issues one on the FIRST consent otherwise,
     * silently leaving a reconnect with no way to refresh later.
     */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => config('services.google_drive.client_id'),
            'redirect_uri'           => config('services.google_drive.redirect'),
            'response_type'          => 'code',
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            // Lets the admin's browser show the Google account chooser
            // rather than silently reusing whatever Google session happens
            // to be active — Section "GOOGLE DRIVE ACCOUNT SELECTION":
            // the admin must be able to pick ANY Google account, not
            // necessarily the one they are currently signed into.
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /**
     * Exchange an authorization code for tokens, and identify the connected
     * account. Throws on any failure — the caller (the callback route) turns
     * that into a user-facing redirect, never a raw exception page.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: int, email: ?string, google_id: ?string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.google_drive.redirect'),
        ]);

        if (!$response->successful()) {
            Log::warning('[google-drive] code exchange failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Google did not accept the authorization code.');
        }

        $tokens = $response->json();
        $accessToken = $tokens['access_token'] ?? null;

        if (!$accessToken) {
            throw new \RuntimeException('Google returned no access token.');
        }

        $profile = Http::withToken($accessToken)->get(self::USERINFO_URL);

        return [
            'access_token'  => $accessToken,
            // Absent when this account already granted consent before AND
            // prompt=consent somehow didn't re-issue one — treated as a hard
            // failure by the caller, since a connection with no refresh
            // token cannot outlive the first hour.
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at'    => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->timestamp,
            'email'         => $profile->successful() ? ($profile->json('email')) : null,
            'google_id'     => $profile->successful() ? ($profile->json('id')) : null,
        ];
    }

    /**
     * A connection with a live access token — refreshing first if the
     * cached one has expired. Persists the refreshed token immediately, so
     * every other request racing this one reuses it instead of each
     * independently refreshing (Google does not error on a double refresh,
     * but there is no reason to make the extra call).
     *
     * Returns null when the refresh itself fails (revoked access, deleted
     * Google-side grant, …) — the connection is flipped to 'error' status so
     * the Company Admin sees "needs to be reconnected" rather than every
     * upload failing with no explanation. Never throws: a Drive outage must
     * never take down the attachment system, only this one company's Drive
     * fallback.
     */
    public function ensureFreshToken(GoogleDriveConnection $connection): ?GoogleDriveConnection
    {
        if (!$connection->isUsable()) {
            return null;
        }

        if (!$connection->accessTokenExpired()) {
            return $connection;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $connection->refreshToken(),
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::warning('[google-drive] token refresh failed', [
                'company_id' => $connection->company_id, 'status' => $response->status(),
            ]);

            $connection->update([
                'status'     => 'error',
                'last_error' => 'Google Drive access could not be refreshed. Please reconnect.',
            ]);

            return null;
        }

        $tokens = $response->json();

        $connection->update([
            'credentials' => array_merge($connection->credentials ?? [], [
                'access_token' => $tokens['access_token'],
                'expires_at'   => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->timestamp,
            ]),
            'status'     => 'connected',
            'last_error' => null,
        ]);

        return $connection->refresh();
    }

    /**
     * Best-effort revoke of the token with Google, so disconnecting here
     * actually withdraws the app's access rather than leaving a live grant
     * the Company Admin thinks they removed. Never throws — a revoke
     * failure (network hiccup, token already invalid) must not block the
     * disconnect the admin asked for; Google-side cleanup can lag the local
     * one without harm.
     */
    public function revokeToken(GoogleDriveConnection $connection): void
    {
        $token = $connection->refreshToken() ?? $connection->accessToken();
        if (!$token) return;

        try {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable $e) {
            Log::warning('[google-drive] revoke failed (non-fatal)', ['company_id' => $connection->company_id, 'error' => $e->getMessage()]);
        }
    }

    // ── Storage Quota ────────────────────────────────────────────────────────

    /**
     * The connected Google account's REAL storage quota — Section 1/2 of the
     * Storage Tracking spec: "total/used/available", not this CRM's own
     * internal quota (that is CompanyStorageService's job). `about.storageQuota`
     * is account-wide (Gmail/Photos/Docs/Drive combined, whatever this Google
     * account's plan actually is), which is the correct, honest number to show
     * — this app only controls what fraction of it its own uploads consume,
     * never the total itself.
     *
     * Returns null on any failure (expired/invalid token, Drive API down,
     * rate-limited) — same "never throw, let the caller decide" convention as
     * every other method here; the caller (CompanyStorageService::
     * refreshDriveQuota()) falls back to the last persisted value.
     *
     * @return array{total_bytes: ?int, used_bytes: int}|null total_bytes is
     *         null when Google reports no `limit` at all — an account on an
     *         unlimited-storage plan (Section 12: "handle cases where Google
     *         does not return a storage limit gracefully").
     */
    public function accountStorageQuota(GoogleDriveConnection $connection): ?array
    {
        $token = $this->tokenFor($connection);
        if (!$token) return null;

        $response = Http::withToken($token)->get(self::API_BASE . '/about', [
            'fields' => 'storageQuota',
        ]);

        if (!$response->successful()) {
            Log::warning('[google-drive] storage quota fetch failed', [
                'company_id' => $connection->company_id, 'status' => $response->status(), 'body' => $response->body(),
            ]);
            return null;
        }

        $quota = $response->json('storageQuota') ?? [];

        return [
            'total_bytes' => isset($quota['limit']) ? (int) $quota['limit'] : null,
            'used_bytes'  => (int) ($quota['usage'] ?? 0),
        ];
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    /** Human labels for the module-key folders — mirrors the spec's example tree. */
    public const MODULE_FOLDER_LABELS = [
        'projects'         => 'Projects',
        'project_comments' => 'Project Comments',
        'project_delivery' => 'Project Delivery',
        'documents'        => 'Documents',
        'tasks'            => 'Tasks',
        'chat'             => 'Chat Attachments',
        'support'          => 'Support Tickets',
        'other'            => 'Other Attachments',
    ];

    /**
     * The Drive folder id a file for $moduleKey should upload into —
     * creating the company's root folder and/or this subfolder on first use
     * only. Cached on the connection row (folder_ids), so a busy company
     * does not re-resolve "does this folder already exist" on every single
     * upload.
     */
    public function folderFor(GoogleDriveConnection $connection, string $moduleKey): ?string
    {
        if ($cached = $connection->folderIdFor($moduleKey)) {
            return $cached;
        }

        $root = $this->ensureRootFolder($connection);
        if (!$root) return null;

        $label = self::MODULE_FOLDER_LABELS[$moduleKey] ?? self::MODULE_FOLDER_LABELS['other'];
        $folderId = $this->createFolder($connection, $label, $root);
        if (!$folderId) return null;

        $connection->update(['folder_ids' => array_merge($connection->folder_ids ?? [], [$moduleKey => $folderId])]);

        return $folderId;
    }

    private function ensureRootFolder(GoogleDriveConnection $connection): ?string
    {
        if ($connection->root_folder_id) {
            return $connection->root_folder_id;
        }

        $crmFolder = $this->findOrCreateFolder($connection, 'CRM', null);
        if (!$crmFolder) return null;

        $company = Company::find($connection->company_id);
        $companyFolder = $this->createFolder($connection, $company?->name ?: "Company #{$connection->company_id}", $crmFolder);
        if (!$companyFolder) return null;

        $connection->update(['root_folder_id' => $companyFolder]);

        return $companyFolder;
    }

    /** Reuses an existing folder of that name under $parentId if one exists (idempotent across reconnects), else creates it. */
    private function findOrCreateFolder(GoogleDriveConnection $connection, string $name, ?string $parentId): ?string
    {
        $token = $this->tokenFor($connection);
        if (!$token) return null;

        $q = "name = '" . addslashes($name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false"
            . ($parentId ? " and '{$parentId}' in parents" : " and 'root' in parents");

        $response = Http::withToken($token)->get(self::API_BASE . '/files', [
            'q' => $q, 'fields' => 'files(id)', 'spaces' => 'drive',
        ]);

        $existing = $response->successful() ? ($response->json('files.0.id')) : null;

        return $existing ?: $this->createFolder($connection, $name, $parentId);
    }

    private function createFolder(GoogleDriveConnection $connection, string $name, ?string $parentId): ?string
    {
        $token = $this->tokenFor($connection);
        if (!$token) return null;

        $body = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parentId) $body['parents'] = [$parentId];

        $response = Http::withToken($token)->post(self::API_BASE . '/files', $body);

        if (!$response->successful()) {
            Log::warning('[google-drive] folder create failed', ['company_id' => $connection->company_id, 'name' => $name, 'body' => $response->body()]);
            return null;
        }

        return $response->json('id');
    }

    // ── Files ────────────────────────────────────────────────────────────────

    /**
     * Upload $file into $folderId. Small files go in one multipart request;
     * anything at or above RESUMABLE_THRESHOLD_BYTES uses a resumable
     * session (Section 13: "use resumable uploads instead of loading the
     * entire file unnecessarily into memory") — opened with a metadata-only
     * POST, then the file streamed to it in one PUT rather than buffered
     * into a single oversized request body.
     *
     * Returns the new file's Drive id, or null on failure (logged; the
     * caller decides what a failed Drive upload means for the request in
     * progress — see AttachmentStorageService::store()).
     */
    public function upload(GoogleDriveConnection $connection, UploadedFile $file, string $folderId, string $originalName): ?string
    {
        $token = $this->tokenFor($connection);
        if (!$token) return null;

        return $file->getSize() >= self::RESUMABLE_THRESHOLD_BYTES
            ? $this->uploadResumable($token, $file, $folderId, $originalName)
            : $this->uploadMultipart($token, $file, $folderId, $originalName);
    }

    private function uploadMultipart(string $token, UploadedFile $file, string $folderId, string $originalName): ?string
    {
        $metadata = ['name' => $originalName, 'parents' => [$folderId]];

        $response = Http::withToken($token)
            ->attach('metadata', json_encode($metadata), 'metadata.json', ['Content-Type' => 'application/json; charset=UTF-8'])
            ->attach('file', file_get_contents($file->getRealPath()), $originalName, ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'])
            ->post(self::UPLOAD_BASE . '/files?uploadType=multipart&fields=id');

        if (!$response->successful()) {
            Log::warning('[google-drive] multipart upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json('id');
    }

    private function uploadResumable(string $token, UploadedFile $file, string $folderId, string $originalName): ?string
    {
        $metadata = ['name' => $originalName, 'parents' => [$folderId]];

        // Step 1 — open the session. Google returns the upload URL to PUT
        // the bytes to in the Location header.
        $init = Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type'   => $file->getMimeType() ?: 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $file->getSize(),
            ])
            ->post(self::UPLOAD_BASE . '/files?uploadType=resumable&fields=id', $metadata);

        if (!$init->successful() || !$init->header('Location')) {
            Log::warning('[google-drive] resumable session open failed', ['status' => $init->status(), 'body' => $init->body()]);
            return null;
        }

        $uploadUrl = $init->header('Location');
        $stream = fopen($file->getRealPath(), 'rb');

        try {
            // Step 2 — PUT the bytes. withBody() streams from the resource
            // rather than reading it into a string first, which is the
            // whole point of going resumable for a large file.
            $put = Http::withToken($token)
                ->withBody($stream, $file->getMimeType() ?: 'application/octet-stream')
                ->withHeaders(['Content-Length' => (string) $file->getSize()])
                ->put($uploadUrl);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }

        if (!$put->successful()) {
            Log::warning('[google-drive] resumable upload failed', ['status' => $put->status(), 'body' => $put->body()]);
            return null;
        }

        return $put->json('id');
    }

    /**
     * Stream a Drive file back through THIS server — never a direct Drive
     * link, and never the OAuth token, reach the browser. The caller (e.g.
     * Api\User\ProjectAttachmentController::download()) has already run its
     * own permission check before this is called; this method's only job is
     * proxying bytes once that is settled.
     */
    public function streamDownload(GoogleDriveConnection $connection, string $fileId, string $downloadName, ?string $mimeType = null): ?StreamedResponse
    {
        $token = $this->tokenFor($connection);
        if (!$token) return null;

        $response = Http::withToken($token)
            ->withOptions(['stream' => true])
            ->get(self::API_BASE . "/files/{$fileId}", ['alt' => 'media']);

        if (!$response->successful()) {
            Log::warning('[google-drive] download failed', ['file_id' => $fileId, 'status' => $response->status()]);
            return null;
        }

        $body = $response->toPsrResponse()->getBody();

        return new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type'        => $mimeType ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes($downloadName) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Delete a file from Drive. Only ever called for a deliberate,
     * user-initiated attachment delete — NEVER as a side effect of
     * disconnecting the company's Drive integration (Section 14: existing
     * Drive files must survive a disconnect). Swallows failure: a Drive-side
     * delete failing must not block the CRM from removing its own
     * attachment row, which is the record that actually governs what the
     * CRM shows.
     */
    public function delete(GoogleDriveConnection $connection, string $fileId): void
    {
        $token = $this->tokenFor($connection);
        if (!$token) return;

        try {
            Http::withToken($token)->delete(self::API_BASE . "/files/{$fileId}");
        } catch (\Throwable $e) {
            Log::warning('[google-drive] delete failed (non-fatal)', ['file_id' => $fileId, 'error' => $e->getMessage()]);
        }
    }

    private function tokenFor(GoogleDriveConnection $connection): ?string
    {
        $fresh = $this->ensureFreshToken($connection);

        return $fresh?->accessToken();
    }

    /** A short-lived, single-use, unguessable handle for the OAuth `state` param. */
    public static function newState(): string
    {
        return Str::random(48);
    }
}
