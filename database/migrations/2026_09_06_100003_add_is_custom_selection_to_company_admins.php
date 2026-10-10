<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            // True only for the "Build Your Own Plan" registration flow —
            // marks that selected_modules was an explicit hand-picked set, so
            // it must never be auto-trimmed to a package's module list (see
            // PublicController::register()'s full-catalog trial grant and
            // SubscriptionPaymentController::process()'s post-payment trim).
            $table->boolean('is_custom_selection')->default(false)->after('package_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->dropColumn('is_custom_selection');
        });
    }
};
