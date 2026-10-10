<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Links a project back to the won Lead it was handed off from, so Sales
    // can show "Linked Projects" and Project Management can trace a project's
    // sales origin. Nullable — most projects still aren't lead-originated.
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'lead_id')) {
                $table->foreignId('lead_id')->nullable()->after('client_id')->constrained('leads')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'lead_id')) {
                $table->dropForeign(['lead_id']);
                $table->dropColumn('lead_id');
            }
        });
    }
};
