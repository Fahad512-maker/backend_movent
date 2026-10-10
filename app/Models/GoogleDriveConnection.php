<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's Google Drive integration — at most one row per company.
 *
 * `credentials` carries the OAuth tokens and is the one place they exist in
 * this application. It is `encrypted:array` (transparently encrypted with
 * APP_KEY, same cast CompanyPaymentGateway::config already uses for gateway
 * API keys), so the access_token/refresh_token are never readable at rest
 * and are NEVER included in any Eloquent ->toArray()/->toJson() output by
 * accident — see $hidden below, which is the second, independent guard
 * against that. Every API response that mentions this connection is built
 * by hand from named, safe fields (see Api\Admin\StorageController) —
 * nothing here is ever serialized whole.
 */
class GoogleDriveConnection extends Model
{
    protected $table = 'company_google_drive_connections';

    protected $fillable = [
        'company_id', 'connected_by_admin_id',
        'google_account_email', 'google_account_id',
        'credentials', 'root_folder_id', 'folder_ids',
        'status', 'last_error', 'connected_at', 'disconnected_at',
        'quota_total_bytes', 'quota_used_bytes', 'quota_synced_at',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials'       => 'encrypted:array',
        'folder_ids'        => 'array',
        'connected_at'      => 'datetime',
        'disconnected_at'   => 'datetime',
        'quota_synced_at'   => 'datetime',
        'quota_total_bytes' => 'integer',
        'quota_used_bytes'  => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'connected_by_admin_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isUsable(): bool
    {
        return $this->isConnected() && !empty($this->credentials['refresh_token'] ?? null);
    }

    public function accessToken(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    public function refreshToken(): ?string
    {
        return $this->credentials['refresh_token'] ?? null;
    }

    /** True once the cached access token is expired or about to be — a 60s margin against clock/latency skew. */
    public function accessTokenExpired(): bool
    {
        $expiresAt = $this->credentials['expires_at'] ?? null;

        return !$expiresAt || $expiresAt <= now()->addSeconds(60)->timestamp;
    }

    /** The cached Drive folder id for one upload module ('projects', 'project_comments', …), or null if never created. */
    public function folderIdFor(string $moduleKey): ?string
    {
        return $this->folder_ids[$moduleKey] ?? null;
    }
}
