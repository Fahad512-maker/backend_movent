<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->timestamp('submitted_at')->nullable()->after('delivered_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropColumn(['version', 'submitted_at', 'approved_at']);
        });
    }
};
