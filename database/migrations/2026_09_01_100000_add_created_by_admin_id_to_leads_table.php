<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// leads.created_by only FKs to `users`, but a Company Admin has no `users`
// row — so every lead an Admin raised was stored with created_by = null and
// the "Created By" column/field read "—". Adds the parallel nullable
// company_admins FK, exactly the dual-column pattern already used by
// projects.created_by_admin_id (2026_07_09_000002).
//
// Then backfills the rows that are already null. Lead::logActivity() writes a
// 'created' activity whose causer_name is the creator's display name, so the
// original author IS recoverable — as an Admin (Api\Admin\LeadController never
// set created_by at all) or as a staff user (Api\User\LeadController only
// started setting created_by in commit 8088112, so leads raised before that
// are null too — which is why a Lead Manager's older leads show "—" even
// though the current code records them correctly).
//
// A name is not a key, so the backfill only writes when it resolves to
// exactly ONE candidate; anything ambiguous is left null rather than
// attributing a lead to the wrong person.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'created_by_admin_id')) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
                $table->foreign('created_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        if (!Schema::hasTable('lead_activities') || !Schema::hasColumn('lead_activities', 'causer_name')) {
            return;
        }

        $orphans = DB::table('leads')
            ->whereNull('created_by')
            ->whereNull('created_by_admin_id')
            ->select('id', 'company_id')
            ->get();

        foreach ($orphans as $lead) {
            $causer = DB::table('lead_activities')
                ->where('lead_id', $lead->id)
                ->where('type', 'created')
                ->orderBy('id')
                ->value('causer_name');

            if (!$causer || !$lead->company_id) {
                continue;
            }

            // Staff user first. Company membership lives in BOTH users.company_id
            // (the user's home company) and company_user_assignments (multi-company
            // membership), so both are checked — a Lead Manager shared across
            // companies would otherwise never match. Soft-deleted users are
            // deliberately included: a since-removed colleague is still who
            // created the lead.
            $userIds = DB::table('users')
                ->where('name', $causer)
                ->where(function ($q) use ($lead) {
                    $q->where('company_id', $lead->company_id);
                    if (Schema::hasTable('company_user_assignments')) {
                        $q->orWhereIn('id', DB::table('company_user_assignments')
                            ->where('company_id', $lead->company_id)
                            ->pluck('user_id'));
                    }
                })
                ->pluck('id');

            if ($userIds->count() === 1) {
                DB::table('leads')->where('id', $lead->id)->update(['created_by' => $userIds->first()]);
                continue;
            }

            // Otherwise the Company Admin that owns this lead's company —
            // company_admins has no company_id of its own, the link is
            // companies.admin_id.
            $adminId = DB::table('companies')->where('id', $lead->company_id)->value('admin_id');

            if ($adminId && DB::table('company_admins')->where('id', $adminId)->where('name', $causer)->exists()) {
                DB::table('leads')->where('id', $lead->id)->update(['created_by_admin_id' => $adminId]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'created_by_admin_id')) {
                $table->dropForeign(['created_by_admin_id']);
                $table->dropColumn('created_by_admin_id');
            }
        });
    }
};
