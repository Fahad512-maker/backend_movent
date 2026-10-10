<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canViewAllCompanyInvoices is now a Lead Manager default going forward (see
// App\Services\RoleDefaultPermissions::MAP) — without it, an invoice the
// Lead Manager themself generated for a lead, then assigned to a Seller, was
// invisible to that Seller (Api\User\InvoiceController::visibleInvoices()
// only shows an invoice's own creator by default), and the Lead Manager
// couldn't see the wider team's invoices either. Backfill existing Lead
// Managers the same way — only ever INSERTS a missing grant, never touches
// or removes an existing permission row.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();

        $users = DB::table('users')->where('role_type', 'lead_manager')->get(['id', 'company_id']);

        foreach ($users as $user) {
            DB::table('user_company_permissions')->insertOrIgnore([[
                'user_id'        => $user->id,
                'company_id'     => $user->company_id,
                'module_key'     => 'invoice',
                'permission_key' => 'canViewAllCompanyInvoices',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
