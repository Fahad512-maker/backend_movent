<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One Google Drive connection per company — isolated the same way
// company_payment_gateways already is (unique company_id, own credentials).
//
// `credentials` is `encrypted:array` on the model (App\Models\
// GoogleDriveConnection), same cast CompanyPaymentGateway.config already
// uses for gateway API keys — access_token/refresh_token never sit in the
// database in plain text and are never sent to the frontend (the Storage
// settings endpoint only ever returns google_account_email + status).
//
// `folder_ids` caches the Drive folder id created under the company's root
// folder for each upload module ('projects', 'project_comments',
// 'project_delivery', 'documents', 'other') — created lazily the first time
// each module actually uploads something, so connecting Drive doesn't
// immediately create six empty folders nobody used yet.
//
// The connected Google account can be, and usually is, a different email
// than the Company Admin's own CRM login — see google_account_email below,
// which is display-only and never used to gate anything.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_google_drive_connections')) return;

        Schema::create('company_google_drive_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Who clicked Connect — audit only, never a permission check
            // (the admin guard itself is the gate, same as every other
            // Company Admin-only action in this app).
            $table->unsignedBigInteger('connected_by_admin_id')->nullable();

            // Display-only identity of the connected Drive account — safe to
            // return to the frontend. Never used to restrict which CRM admin
            // may manage the connection.
            $table->string('google_account_email')->nullable();
            $table->string('google_account_id')->nullable();

            // access_token, refresh_token, expires_at (unix timestamp) —
            // encrypted at rest, never serialized to any API response.
            $table->text('credentials')->nullable();

            // The company's own root folder in Drive ("CRM/{Company Name}"),
            // and the per-module subfolders created under it.
            $table->string('root_folder_id')->nullable();
            $table->json('folder_ids')->nullable();

            // 'connected' (working normally) | 'disconnected' (admin turned
            // it off, or never connected) | 'error' (refresh failed / access
            // revoked — see GoogleDriveService::refreshAccessToken()).
            // Existing Drive-backed attachments keep resolving regardless of
            // this status; it only gates whether NEW uploads may use Drive.
            $table->enum('status', ['connected', 'disconnected', 'error'])->default('disconnected');
            $table->string('last_error')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');
            $table->foreign('connected_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_google_drive_connections');
    }
};
