<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company Admin has never been able to be a `notifications` recipient —
// user_id is a hard FK to `users`, and CompanyAdmin is a separate table/model
// entirely. This is the structural reason Admin's bell has always been a
// synthetic SystemAuditLog-based feed instead of real per-row notifications
// (see Api\Admin\NotificationController). This migration makes user_id
// nullable and adds a parallel recipient_admin_id, so a row can now target
// EITHER a User or a CompanyAdmin. Also adds actor_user_id/actor_admin_id
// (who performed the action, for NotificationService's self-skip check) and
// url/module/entity_type/entity_id as first-class columns — url mirrors into
// the existing data['link'] key too, so old rows/frontend reads never break.
// Every add is nullable/additive; no existing column or row is touched.
return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing FK constraint before loosening nullability, same
        // pattern as 2026_07_04_000002_make_documents_uploaded_by_nullable.php
        // — then re-add it (still cascadeOnDelete, matching the original).
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'recipient_admin_id')) {
                $table->foreignId('recipient_admin_id')->nullable()->after('user_id')->constrained('company_admins')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('notifications', 'actor_user_id')) {
                $table->foreignId('actor_user_id')->nullable()->after('recipient_admin_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('notifications', 'actor_admin_id')) {
                $table->foreignId('actor_admin_id')->nullable()->after('actor_user_id')->constrained('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('notifications', 'module')) {
                $table->string('module', 50)->nullable()->after('type');
            }
            if (!Schema::hasColumn('notifications', 'entity_type')) {
                $table->string('entity_type', 50)->nullable()->after('data');
            }
            if (!Schema::hasColumn('notifications', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (!Schema::hasColumn('notifications', 'url')) {
                $table->string('url', 255)->nullable()->after('entity_id');
            }
        });

        // recipient_admin_id was just added above in this same migration, so
        // this index can never already exist — no guard needed.
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['recipient_admin_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            foreach (['recipient_admin_id', 'actor_user_id', 'actor_admin_id'] as $col) {
                if (Schema::hasColumn('notifications', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
            foreach (['module', 'entity_type', 'entity_id', 'url'] as $col) {
                if (Schema::hasColumn('notifications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
