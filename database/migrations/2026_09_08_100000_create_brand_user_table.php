<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A brand is worked by a TEAM, not one person: several Sellers / Lead
// Managers can each raise invoices under the same trading name. This replaces
// brands.assigned_to (a single FK) with a proper pivot, the same shape
// project_team_members already uses for projects.
//
// Existing single assignments are carried across before the column goes, so
// nobody loses the brand they were working under.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brand_user')) {
            Schema::create('brand_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                // One row per person per brand — assigning twice is a no-op,
                // not a duplicate.
                $table->unique(['brand_id', 'user_id']);
            });
        }

        // Carry the old single assignment over.
        if (Schema::hasColumn('brands', 'assigned_to')) {
            $rows = DB::table('brands')->whereNotNull('assigned_to')->get(['id', 'assigned_to']);
            $now  = now();

            foreach ($rows as $row) {
                DB::table('brand_user')->insertOrIgnore([
                    'brand_id'   => $row->id,
                    'user_id'    => $row->assigned_to,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('brands', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_to');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('brands', 'assigned_to')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->foreignId('assigned_to')->nullable()->after('is_active')
                    ->constrained('users')->nullOnDelete();
            });

            // Only the first assignee survives a rollback — the column can
            // only hold one, which is exactly why it was replaced.
            foreach (DB::table('brand_user')->orderBy('id')->get() as $row) {
                DB::table('brands')->where('id', $row->brand_id)->whereNull('assigned_to')
                    ->update(['assigned_to' => $row->user_id]);
            }
        }

        Schema::dropIfExists('brand_user');
    }
};
