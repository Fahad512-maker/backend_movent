<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a notifications row target the Super Admin, the same way
// 2026_07_31_120000 made it able to target a Company Admin. Two changes:
//
//  1. recipient_super_admin_id — SuperAdmin is its own table/model, so it
//     could never be a recipient through user_id or recipient_admin_id.
//  2. company_id becomes nullable — every existing notification belongs to
//     exactly one tenant, but platform-level events don't: a new Company Admin
//     account exists before any company does, and a subscription payment is
//     made by an admin, not by a company.
//
// Both additive; no existing column value is touched.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) return;

        if (!Schema::hasColumn('notifications', 'recipient_super_admin_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->foreignId('recipient_super_admin_id')->nullable()
                    ->after('recipient_admin_id')
                    ->constrained('super_admins')->cascadeOnDelete();
                $table->index(['recipient_super_admin_id', 'is_read']);
            });
        }

        // Drop the FK before loosening nullability, then re-add it — same
        // pattern the user_id change in 2026_07_31_120000 used.
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('notifications')) return;

        if (Schema::hasColumn('notifications', 'recipient_super_admin_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex(['recipient_super_admin_id', 'is_read']);
                $table->dropConstrainedForeignId('recipient_super_admin_id');
            });
        }

        // company_id is deliberately left nullable — platform-level rows
        // written while this migration was up would violate a restored NOT
        // NULL, and dropping them to satisfy it would lose history.
    }
};
