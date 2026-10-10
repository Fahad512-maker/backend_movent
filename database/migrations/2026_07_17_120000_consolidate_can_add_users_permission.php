<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// canAddUsers used to be duplicated under every module (sales, invoice, hr,
// project_management, ...). It's now a single company-wide capability stored
// under module_key='account' (see App\Services\ModuleCatalog). This moves any
// existing grants — however many modules they were scattered across per
// (user, company) — into one consolidated 'account' row, so no one who could
// already add users loses that ability when the per-module checkboxes disappear.
return new class extends Migration {
    public function up(): void
    {
        $pairs = DB::table('user_company_permissions')
            ->where('permission_key', 'canAddUsers')
            ->select('user_id', 'company_id', 'company_user_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $alreadyConsolidated = DB::table('user_company_permissions')
                ->where('user_id', $pair->user_id)
                ->where('company_id', $pair->company_id)
                ->where('module_key', 'account')
                ->where('permission_key', 'canAddUsers')
                ->exists();

            if (!$alreadyConsolidated) {
                DB::table('user_company_permissions')->insert([
                    'company_user_id' => $pair->company_user_id,
                    'user_id'         => $pair->user_id,
                    'company_id'      => $pair->company_id,
                    'module_key'      => 'account',
                    'permission_key'  => 'canAddUsers',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        DB::table('user_company_permissions')
            ->where('permission_key', 'canAddUsers')
            ->where('module_key', '!=', 'account')
            ->delete();
    }

    public function down(): void
    {
        // Irreversible by design — the original per-module rows this collapsed
        // are not individually recoverable (the whole point was de-duplication).
    }
};
