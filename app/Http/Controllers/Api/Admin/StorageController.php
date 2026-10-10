<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\GoogleDriveConnection;
use App\Services\CompanyStorageService;
use App\Services\GoogleDriveService;
use App\Support\SafeReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Company Admin > Settings > Storage. The admin guard itself is the
 * authorization for every method here — no separate permission to check,
 * same as the rest of Api\Admin\SettingsController — which is exactly
 * Section 15's rule: only the Company Admin may connect, change, view or
 * disconnect the company's Google Drive integration. There is no staff-side
 * (`Api\User`) counterpart to this controller at all, unlike most of
 * Settings, which staff can be granted access to — this one deliberately
 * has no equivalent route for a sub-user to reach, however permissioned.
 *
 * Follows the exact same "whichever company the topbar filter is on" rule
 * Api\Admin\SettingsController::settingsCompany() already established:
 * Storage is per-company, and this screen never lets "All Companies" mode
 * write to (or silently pick) one.
 */
class StorageController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->orderBy('id')->pluck('id')->toArray();
    }

    private function allCompaniesMode(): bool
    {
        return request()->header('X-Active-Company-Id') === 'all' && count($this->companyIds()) > 1;
    }

    private function storageCompany(): ?Company
    {
        if ($this->allCompaniesMode()) {
            return null;
        }

        $companyId = $this->activeCompanyId();

        return $companyId ? Company::find($companyId) : null;
    }

    private function noCompanySelected(): JsonResponse
    {
        return $this->allCompaniesMode()
            ? ApiResponse::error('Storage belongs to one company. Pick a single company in the company filter at the top, then try again.', 422)
            : ApiResponse::error('Create a company before configuring its storage.', 422);
    }

    // GET /admin/storage
    public function show(): JsonResponse
    {
        $company = $this->storageCompany();
        if (!$company) {
            return ApiResponse::success(['requires_company_selection' => true]);
        }

        $connection = GoogleDriveConnection::where('company_id', $company->id)->first();

        return ApiResponse::success([
            'requires_company_selection' => false,
            'company_id'                 => $company->id,
            'storage'                    => CompanyStorageService::status($company->id),
            'breakdown'                  => CompanyStorageService::breakdown($company->id),
            'drive'                      => $this->driveSummary($connection),
            'drive_quota'                => $this->driveQuota($connection),
            'drive_configured'           => GoogleDriveService::isConfigured(),
        ]);
    }

    /**
     * The real Google Drive account quota (Section 1/2 of the Storage
     * Tracking spec) — null while Drive isn't connected, since there is
     * nothing real to show yet (never a fake/zeroed card, Section 1: "Do not
     * use hardcoded or fake values"). A passive page view respects
     * CompanyStorageService::refreshDriveQuota()'s TTL; only the explicit
     * "Refresh Storage" button (refreshQuota() below) forces a live fetch.
     */
    private function driveQuota(?GoogleDriveConnection $connection): ?array
    {
        if (!$connection || !$connection->isConnected()) {
            return null;
        }

        return CompanyStorageService::driveQuotaStatus($connection);
    }

    /**
     * The ONLY fields a Google Drive connection ever exposes to the
     * frontend — Section 16: "never expose OAuth client secret, access
     * token, refresh token". Nothing here is built from
     * $connection->toArray(); every field is named explicitly, so a column
     * added to the table later can never leak here by accident (the same
     * allow-list discipline App\Services\Finance\FinancePresenter uses for
     * payment data).
     */
    private function driveSummary(?GoogleDriveConnection $connection): array
    {
        if (!$connection) {
            return ['status' => 'disconnected', 'connected' => false, 'email' => null, 'connected_at' => null, 'last_error' => null];
        }

        return [
            'status'       => $connection->status,
            'connected'    => $connection->isConnected(),
            'email'        => $connection->google_account_email,
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'last_error'   => $connection->status === 'error' ? $connection->last_error : null,
        ];
    }

    /**
     * GET /admin/storage/google-drive/connect-url?return_to=/projects/45
     *
     * Also used for "Change Google Account" — it is the identical action
     * (start OAuth again, let the admin pick any account in Google's own
     * chooser); the frontend just relabels the button when a connection
     * already exists. A successful re-connect overwrites this row's
     * credentials/email but touches nothing else — no Drive file this
     * company has ever uploaded is deleted or re-pointed, so an attachment
     * uploaded under the previous account keeps resolving through it (see
     * the class note on GoogleDriveConnection).
     *
     * `return_to` is what makes "Connect Google Drive" clicked from an
     * attachment upload land the admin back on that exact page instead of
     * always at Settings — see Auth\GoogleDriveConnectionController::
     * callback(), which reads it back out of this same state payload.
     * Validated with SafeReturnPath before it is ever stored: an unsafe or
     * absent value simply means "no return_to", not an error — the callback
     * already has a safe Settings fallback for that case.
     */
    public function connectUrl(Request $request): JsonResponse
    {
        if (!GoogleDriveService::isConfigured()) {
            return ApiResponse::error('Google Drive is not configured on this server yet. Ask your platform administrator to set it up.', 422);
        }

        $company = $this->storageCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $returnTo = $request->query('return_to');
        $returnTo = is_string($returnTo) && SafeReturnPath::isSafe($returnTo) ? $returnTo : null;

        // A short-lived, single-use handle — NOT a JWT/signed value, on
        // purpose: Cache::pull() in the callback consumes it exactly once,
        // so a captured/replayed callback URL can never be used twice, and
        // there is no secret embedded in it for a client-side observer to
        // learn anything from. return_to travels inside this same
        // server-side payload rather than as its own OAuth param — Google
        // only ever sees and echoes back the opaque `state` string, never
        // the destination path itself.
        $state = GoogleDriveService::newState();
        Cache::put("google_drive_connect:{$state}", [
            'company_id' => $company->id,
            'admin_id'   => $this->admin()->id,
            'return_to'  => $returnTo,
        ], now()->addMinutes(10));

        $url = app(GoogleDriveService::class)->authorizationUrl($state);

        return ApiResponse::success(['url' => $url]);
    }

    /**
     * POST /admin/storage/google-drive/disconnect
     *
     * Section 14: revokes the app's access with Google and marks the
     * connection disconnected, but deliberately does NOT touch a single
     * attachment row or delete anything from Drive — every attachment
     * already stored there keeps its google_drive_file_id and (so long as
     * the file itself still exists in that Drive account) keeps opening
     * normally; only NEW uploads stop being offered Drive as a destination
     * until reconnected.
     */
    public function disconnect(): JsonResponse
    {
        $company = $this->storageCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $connection = GoogleDriveConnection::where('company_id', $company->id)->first();
        if (!$connection || !$connection->isConnected()) {
            return ApiResponse::error('Google Drive is not connected for this company.', 422);
        }

        app(GoogleDriveService::class)->revokeToken($connection);

        $connection->update([
            'status'             => 'disconnected',
            'disconnected_at'    => now(),
            'credentials'        => null,
            // Stale quota numbers for an account no longer connected would
            // just be confusing leftovers on a reconnect — cleared, not kept.
            'quota_total_bytes'  => null,
            'quota_used_bytes'   => null,
            'quota_synced_at'    => null,
        ]);

        return ApiResponse::success(['drive' => $this->driveSummary($connection->fresh())], 'Google Drive disconnected');
    }

    /**
     * POST /admin/storage/google-drive/refresh
     *
     * The "Refresh Storage" button (Section 5) — forces a live re-fetch of
     * Google's real quota past CompanyStorageService::refreshDriveQuota()'s
     * passive TTL (still throttled against a double-click there, not here).
     */
    public function refreshQuota(): JsonResponse
    {
        $company = $this->storageCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $connection = GoogleDriveConnection::where('company_id', $company->id)->first();
        if (!$connection || !$connection->isConnected()) {
            return ApiResponse::error('Google Drive is not connected for this company.', 422);
        }

        return ApiResponse::success(['drive_quota' => CompanyStorageService::driveQuotaStatus($connection, force: true)]);
    }
}
