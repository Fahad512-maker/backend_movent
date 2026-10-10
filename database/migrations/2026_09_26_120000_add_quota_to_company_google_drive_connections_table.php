<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Persists Google's own account-level storage quota (Drive API's
// `about?fields=storageQuota` — the account's real total/used bytes across
// Gmail/Photos/Drive, e.g. "7.4 GB / 15 GB", as opposed to this CRM's own
// tiny internal quota tracked in CompanyStorageService). Persisted here
// rather than kept only in cache so "Last synced" survives a cache
// eviction/restart — see App\Services\CompanyStorageService::
// refreshDriveQuota()/driveQuotaStatus(), the only reader/writer of these
// three columns.
//
// quota_total_bytes is nullable independently of quota_synced_at being set:
// Google returns no `limit` field at all for an account with unlimited
// storage, which this app represents as "synced, but no total" rather than
// "never synced".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_google_drive_connections', function (Blueprint $table) {
            if (Schema::hasColumn('company_google_drive_connections', 'quota_total_bytes')) return;

            $table->unsignedBigInteger('quota_total_bytes')->nullable()->after('folder_ids');
            $table->unsignedBigInteger('quota_used_bytes')->nullable()->after('quota_total_bytes');
            $table->timestamp('quota_synced_at')->nullable()->after('quota_used_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('company_google_drive_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('company_google_drive_connections', 'quota_total_bytes')) return;

            $table->dropColumn(['quota_total_bytes', 'quota_used_bytes', 'quota_synced_at']);
        });
    }
};
