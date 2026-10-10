<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every project auto-started by a client's payment before
// App\Services\PaymentProjectStartService learned to assign one was created
// with created_by, project_manager_id and seller_id all null — it belonged to
// nobody and showed "Unassigned" forever, since activate() never fills those
// in either.
//
// Applies that service's own ownership rule to the projects already sitting
// in that state: the invoice's creator when a sub-user raised it, otherwise
// the person the deal belongs to (the lead's current owner, else the client's
// account manager) — which is who an Admin-raised invoice was raised on
// behalf of.
//
// Deliberately narrow and non-destructive:
//   • only source='*_auto_start' projects — a hand-created project's blank PM
//     is a deliberate choice, not this bug;
//   • only rows where BOTH project_manager_id and seller_id are still null, so
//     anything since assigned by hand (or by 2026_08_11_150000) is untouched;
//   • no notifications and no project_seller_assignments rows — announcing
//     historical assignments would light up everyone's bell for work they
//     already know about. Only the columns and the ProjectTeamMember row
//     visibleProjects() needs are written.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'invoices', 'users'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        $projects = DB::table('projects')
            ->whereIn('source', ['paid_invoice_auto_start', 'partial_paid_invoice_auto_start'])
            ->whereNull('project_manager_id')
            ->whereNull('seller_id')
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'invoice_id', 'lead_id', 'client_id', 'created_by']);

        foreach ($projects as $project) {
            $invoice = $project->invoice_id
                ? DB::table('invoices')->where('id', $project->invoice_id)->first(['created_by', 'created_by_admin_id'])
                : null;

            $creator = $this->activeUser($invoice?->created_by, $project->company_id);
            $owner   = $creator ?: $this->dealOwner($project);

            $updates = array_filter([
                'created_by'          => $project->created_by ?: ($creator->id ?? null),
                'created_by_admin_id' => $invoice?->created_by_admin_id,
            ], fn ($v) => $v !== null);

            if ($owner) {
                $updates['project_manager_id'] = $owner->id;

                if ($owner->role_type === 'seller') {
                    $updates['seller_id']          = $owner->id;
                    $updates['seller_assigned_at'] = now();
                }
            }

            if (!$updates) {
                continue;
            }

            DB::table('projects')->where('id', $project->id)->update($updates);

            if ($owner) {
                $exists = DB::table('project_team_members')
                    ->where('project_id', $project->id)
                    ->where('user_id', $owner->id)
                    ->exists();

                if (!$exists) {
                    DB::table('project_team_members')->insert([
                        'project_id'      => $project->id,
                        'user_id'         => $owner->id,
                        'role_in_project' => 'project_manager',
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see
        // 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }

    private function activeUser($userId, $companyId)
    {
        return $userId
            ? DB::table('users')
                ->where('id', $userId)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->first(['id', 'role_type'])
            : null;
    }

    private function dealOwner($project)
    {
        $lead = $project->lead_id
            ? DB::table('leads')->where('id', $project->lead_id)->first(['assigned_to', 'transferred_to'])
            : null;

        $candidates = [
            $lead?->transferred_to,
            $lead?->assigned_to,
            $project->client_id
                ? DB::table('clients')->where('id', $project->client_id)->value('account_manager')
                : null,
        ];

        foreach ($candidates as $candidateId) {
            if ($owner = $this->activeUser($candidateId, $project->company_id)) {
                return $owner;
            }
        }

        return null;
    }
};
