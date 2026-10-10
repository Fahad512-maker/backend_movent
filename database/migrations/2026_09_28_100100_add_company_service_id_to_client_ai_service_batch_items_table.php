<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_ai_service_batch_items', function (Blueprint $table) {
            // Set when this suggestion was ranked from the company's own real
            // catalogue (see ClientServiceRecommendationService::rankCatalog())
            // — null for a purely AI-generated suggestion with no backing
            // catalogue row. Lets future recommendation runs recognise "this
            // exact real service was already purchased" by id rather than by
            // fuzzy name matching alone.
            $table->foreignId('company_service_id')->nullable()->after('batch_id')
                ->constrained('company_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_ai_service_batch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_service_id');
        });
    }
};
