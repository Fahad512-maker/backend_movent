<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance', 'manual_time')) {
                $table->boolean('manual_time')->default(false)->after('is_manual_override');
            }
            if (!Schema::hasColumn('attendance', 'manual_entered_by')) {
                $table->string('manual_entered_by', 100)->nullable()->after('manual_time');
            }
            if (!Schema::hasColumn('attendance', 'manual_reason')) {
                $table->text('manual_reason')->nullable()->after('manual_entered_by');
            }
            if (!Schema::hasColumn('attendance', 'manual_created_at')) {
                $table->timestamp('manual_created_at')->nullable()->after('manual_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['manual_time', 'manual_entered_by', 'manual_reason', 'manual_created_at']);
        });
    }
};
