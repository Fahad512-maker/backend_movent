<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_services', function (Blueprint $table) {
            // Default false: a purchased service is excluded from future AI
            // recommendations unless a company explicitly marks it as one a
            // client might reasonably buy again (e.g. a monthly retainer,
            // recurring maintenance) — see
            // App\Services\ClientServiceRecommendationService.
            $table->boolean('is_repeatable')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('company_services', function (Blueprint $table) {
            $table->dropColumn('is_repeatable');
        });
    }
};
