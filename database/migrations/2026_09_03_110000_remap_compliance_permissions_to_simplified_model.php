<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Simplifies the Compliance permission model down to 5 keys (View Compliance,
// View All Company Compliance, Assign Compliance User, Change Compliance
// Status, Download Compliance Data — see ModuleCatalog.php's compliance
// entry for what each does). Remaps every existing grant of the 3 old keys
// this repo had been using onto the new set instead of just dropping them,
// so already-provisioned Compliance Users keep equivalent access:
//   - canViewComplianceCases        -> canViewCompliance
//   - canManageComplianceRequirements -> canChangeComplianceStatus AND
//                                        canDownloadComplianceData (this old
//                                        key covered both case-workflow
//                                        actions and implicit download access)
//   - canManageComplianceTemplates  -> dropped, no replacement (template
//                                      management is Company Admin-only now)
return new class extends Migration
{
    private const REMAP = [
        'canViewComplianceCases' => ['canViewCompliance'],
        'canManageComplianceRequirements' => ['canChangeComplianceStatus', 'canDownloadComplianceData'],
    ];

    private const DROPPED = ['canManageComplianceTemplates'];

    public function up(): void
    {
        if (!Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();

        foreach (self::REMAP as $oldKey => $newKeys) {
            $rows = DB::table('user_company_permissions')
                ->where('module_key', 'compliance')
                ->where('permission_key', $oldKey)
                ->get(['user_id', 'company_id']);

            $inserts = [];
            foreach ($rows as $row) {
                foreach ($newKeys as $newKey) {
                    $inserts[] = [
                        'user_id'        => $row->user_id,
                        'company_id'     => $row->company_id,
                        'module_key'     => 'compliance',
                        'permission_key' => $newKey,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }
            }

            if (!empty($inserts)) {
                DB::table('user_company_permissions')->insertOrIgnore($inserts);
            }

            DB::table('user_company_permissions')
                ->where('module_key', 'compliance')
                ->where('permission_key', $oldKey)
                ->delete();
        }

        DB::table('user_company_permissions')
            ->where('module_key', 'compliance')
            ->whereIn('permission_key', self::DROPPED)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — the old keys no longer correspond to
        // any permission check, so there's nothing meaningful to roll back to.
    }
};
