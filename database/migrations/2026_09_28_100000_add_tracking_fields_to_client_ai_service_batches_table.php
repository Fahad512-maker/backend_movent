<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_ai_service_batches', function (Blueprint $table) {
            // Which project this batch was generated for/around — audit
            // metadata only (see App\Services\ClientServiceRecommendationService,
            // which already drives the actual content). Nullable: a batch can
            // be generated for a client with no project yet (the default/
            // starter set).
            $table->foreignId('project_id')->nullable()->after('client_id')
                ->constrained('projects')->nullOnDelete();

            // Sequential per client, starting at 1 — lets "which generation is
            // this" be answered without counting rows.
            $table->unsignedInteger('batch_number')->default(1)->after('generated_via');

            // When this batch was rotated out (superseded by a new one) —
            // distinct from updated_at so a batch's own history reads clearly
            // even if other columns are touched later for any reason.
            $table->timestamp('rotated_at')->nullable()->after('batch_number');
        });

        // Widen the enum to include the new value, migrate existing rows,
        // then narrow to the final set — a straight rename would either
        // truncate 'superseded' rows to an invalid value or fail outright
        // under strict SQL mode.
        DB::statement("ALTER TABLE client_ai_service_batches MODIFY status ENUM('active','superseded','completed') NOT NULL DEFAULT 'active'");
        DB::table('client_ai_service_batches')->where('status', 'superseded')->update(['status' => 'completed']);
        DB::statement("ALTER TABLE client_ai_service_batches MODIFY status ENUM('active','completed') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE client_ai_service_batches MODIFY status ENUM('active','completed','superseded') NOT NULL DEFAULT 'active'");
        DB::table('client_ai_service_batches')->where('status', 'completed')->update(['status' => 'superseded']);
        DB::statement("ALTER TABLE client_ai_service_batches MODIFY status ENUM('active','superseded') NOT NULL DEFAULT 'active'");

        Schema::table('client_ai_service_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['batch_number', 'rotated_at']);
        });
    }
};
