<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // ISO 3166-1 alpha-2 code (e.g. 'US', 'PK') — the country picked
            // on the signup form's Country dropdown, previously discarded
            // after only its derived timezone/currency were saved.
            $table->string('country', 5)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
