<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which service a project delivered.
//
// This is what makes the Client Portal's recommendations data-driven rather
// than guessed: a project tagged "Website Development" is why that client is
// then offered SEO, Maintenance and Hosting.
//
// Nullable, and every existing project is null — nobody is going back to tag
// years of history. ServiceRecommendationService therefore falls back to
// matching the project's NAME against the catalogue's keywords when the tag is
// missing, so existing clients still get sensible suggestions; the tag simply
// makes it exact from here on.
//
// nullOnDelete: a project keeps existing if the service row it referenced is
// ever hard-deleted — the work happened either way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('company_service_id')->nullable()->after('client_id');
            $table->foreign('company_service_id')->references('id')->on('company_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['company_service_id']);
            $table->dropColumn('company_service_id');
        });
    }
};
