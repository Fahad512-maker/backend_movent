<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded per-column — some deployments' database was seeded from a
        // raw SQL snapshot that may already include some but not all of
        // these columns.
        Schema::table('modules', function (Blueprint $table) {
            if (!Schema::hasColumn('modules', 'price_pkr')) $table->decimal('price_pkr', 10, 2)->default(0)->after('sub_modules');
            if (!Schema::hasColumn('modules', 'price_usd')) $table->decimal('price_usd', 10, 2)->default(0)->after('price_pkr');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['price_pkr', 'price_usd']);
        });
    }
};
