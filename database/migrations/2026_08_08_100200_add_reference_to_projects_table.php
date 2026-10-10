<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Human-readable project reference (e.g. PRJ-2026-0124), generated on
// creation the same way invoice_number already is — lets the client-facing
// "Related Project" display and activity log reference something
// meaningful instead of the raw numeric id.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects') || Schema::hasColumn('projects', 'reference')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('reference', 30)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'reference')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('reference');
            });
        }
    }
};
