<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('next_followup_date')->nullable()->after('notes');
            $table->text('lost_reason')->nullable()->after('next_followup_date');
        });

        // Extend priority enum to include 'urgent'
        DB::statement("ALTER TABLE leads MODIFY COLUMN priority ENUM('low','medium','high','urgent') DEFAULT 'medium'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leads MODIFY COLUMN priority ENUM('low','medium','high') DEFAULT 'medium'");
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['next_followup_date', 'lost_reason']);
        });
    }
};
