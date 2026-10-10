<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded per-column (not just per-table) — some deployments'
        // database was seeded from a raw SQL snapshot that may already
        // include some but not all of these columns.
        Schema::table('packages', function (Blueprint $table) {
            if (!Schema::hasColumn('packages', 'price_pkr')) $table->decimal('price_pkr', 10, 2)->default(0)->after('price');
            if (!Schema::hasColumn('packages', 'price_usd')) $table->decimal('price_usd', 10, 2)->default(0)->after('price_pkr');
            if (!Schema::hasColumn('packages', 'trial_days')) $table->integer('trial_days')->default(14)->after('price_usd');
            if (!Schema::hasColumn('packages', 'is_popular')) $table->boolean('is_popular')->default(false)->after('is_visible');
            if (!Schema::hasColumn('packages', 'features')) $table->json('features')->nullable()->after('description');
        });

        Schema::table('package_modules', function (Blueprint $table) {
            if (!Schema::hasColumn('package_modules', 'is_core')) $table->boolean('is_core')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['price_pkr', 'price_usd', 'trial_days', 'is_popular', 'features']);
        });
        Schema::table('package_modules', function (Blueprint $table) {
            $table->dropColumn('is_core');
        });
    }
};
