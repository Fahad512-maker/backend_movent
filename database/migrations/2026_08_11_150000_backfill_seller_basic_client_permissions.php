<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // App\Services\RoleDefaultPermissions::MAP's Seller 'sales' bucket grants
    // the bundled "Basic Clients" keys (canViewClients/canCreateClients/
    // canEditClients) so a Seller can pick a client when invoicing without the
    // company buying the full Client module. Sellers created before that grant
    // existed never got them, so Api\User\ClientController::index() answers
    // their invoice-create client lookup with a 403 — which the form renders as
    // "No clients found for this company", indistinguishable from a company
    // that genuinely has none. Backfill them the same way new signups get it.
    //
    // Scoped to companies that actually have the Sales module enabled, mirroring
    // RoleDefaultPermissions::forRole()'s own purchased-module filtering — these
    // keys live in the 'sales' catalog bucket, so granting them to a Seller in a
    // company without Sales would hand out client access nobody bought.
    //
    // Only ever INSERTS a missing grant — never touches or removes an existing row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')
            || !Schema::hasTable('company_modules')) {
            return;
        }

        $permissionKeys = ['canViewClients', 'canCreateClients', 'canEditClients'];
        $now = now();

        // DB module keys that map to the 'sales' catalog module — see
        // App\Services\ModuleCatalog::dbKeyToCatalog().
        $salesCompanyIds = DB::table('company_modules')
            ->whereIn('module_key', ['leads', 'projects_handoff', 'lead_transfer', 'reports_seller'])
            ->where('is_enabled', true)
            ->pluck('company_id')
            ->unique();

        if ($salesCompanyIds->isEmpty()) {
            return;
        }

        $users = DB::table('users')
            ->where('role_type', 'seller')
            ->whereIn('company_id', $salesCompanyIds)
            ->get(['id', 'company_id']);

        foreach ($users as $user) {
            // Skip anyone who already holds client access through EITHER bucket.
            // Api\User\ClientController::can() accepts module_key 'client' or
            // 'sales' for the same key, so a Seller granted "View Clients" on the
            // Client module card is already covered — adding a second row under
            // 'sales' would only create a grant the permissions UI hides (see
            // hideIfCatalogKey: 'client' in frontend/lib/moduleCatalog.ts) and
            // therefore cannot be revoked from that card later.
            $alreadyGranted = DB::table('user_company_permissions')
                ->where('user_id', $user->id)
                ->where('company_id', $user->company_id)
                ->whereIn('module_key', ['client', 'sales'])
                ->where('permission_key', 'canViewClients')
                ->exists();

            if ($alreadyGranted) {
                continue;
            }

            $rows = [];
            foreach ($permissionKeys as $permKey) {
                $rows[] = [
                    'user_id'        => $user->id,
                    'company_id'     => $user->company_id,
                    'module_key'     => 'sales',
                    'permission_key' => $permKey,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
