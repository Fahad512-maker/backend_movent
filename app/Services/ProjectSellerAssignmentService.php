<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSellerAssignment;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// Central "assign/switch a project's Seller" logic shared by both guards
// (Api\Admin\ProjectController and Api\User\ProjectController), mirroring
// how ProjectCompletionService is the shared brain behind those two
// controllers' Complete/Close/Reopen actions. Callers are responsible for
// their own permission check, project lookup/company-scope, and the
// "project is closed" guard before calling assign().
class ProjectSellerAssignmentService
{
    // The only user a project's seller_id may ever be set to: an ACTIVE
    // Seller of the SAME company as the project — never cross-company,
    // never a Developer/PM/Production/QA/Team Member even if they hold other
    // permissions. Exactly mirrors Api\User\LeadController::assignableSeller()
    // (the equivalent rule for lead assignment) — deliberately does NOT also
    // require a company_user_assignments row, since that table isn't
    // populated for every Seller (verified against real data: a Seller can
    // be a fully active users row with zero company_user_assignments rows,
    // which would make an otherwise-valid Seller invisible to this check).
    public function assignableSeller(int $companyId, int $sellerId): ?User
    {
        return User::where('id', $sellerId)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('companyAssignments', fn ($a) => $a
                        ->where('company_id', $companyId)
                        ->where('status', 'active'));
            })
            ->where('is_active', true)
            ->where('status', 'active')
            // holdingRole(), not role_type: this validates exactly what the
            // Assign/Switch Seller dropdown offers, and that dropdown now
            // offers multi-role Sellers too. Leaving this on role_type would
            // reject on save a person the picker had just listed.
            ->holdingRole($companyId, 'seller')
            ->first();
    }

    // Updates projects.seller_id/seller_assigned_*, writes a
    // project_seller_assignments row + a SystemAuditLog entry, and notifies
    // the new seller, the previous seller (on a switch), and the project's
    // PM. NotificationService already skips notifying the actor about their
    // own action, so no self-notify guard is needed here. Exactly one of
    // $actorUserId/$actorAdminId should be passed.
    public function assign(Project $project, User $seller, ?string $reason, ?int $actorUserId, ?int $actorAdminId, string $actorName): array
    {
        $oldSellerId = $project->seller_id;

        if ((int) $oldSellerId === (int) $seller->id) {
            return ['changed' => false, 'is_switch' => false];
        }

        $isSwitch = (bool) $oldSellerId;
        $oldSeller = $isSwitch ? User::find($oldSellerId) : null;

        $updates = [
            'seller_id'                   => $seller->id,
            'seller_assigned_by'          => $actorUserId,
            'seller_assigned_by_admin_id' => $actorAdminId,
            'seller_assigned_at'          => now(),
        ];

        // No real PM assigned yet (or the "PM" slot was only ever the
        // previous seller placeholder — same as the self-handoff flow's
        // project_manager_id ??= $user->id in Api\User\ProjectController::
        // store()) — follow the seller into that slot too, so the Project
        // Manager column/dropdown shows this seller as the responsible party
        // instead of "Unassigned" until a real PM is assigned. isPM()/
        // isInternalStaff() already hard-exclude role_type='seller'
        // regardless of project_manager_id match, so this never actually
        // grants the seller any internal/PM-tier capability — display only.
        if (!$project->project_manager_id || (int) $project->project_manager_id === (int) $oldSellerId) {
            $updates['project_manager_id'] = $seller->id;
        }

        $project->update($updates);
        $project->logActivity(
            $isSwitch ? 'seller_switched' : 'seller_assigned',
            $isSwitch && $oldSeller
                ? "{$actorName} switched seller from {$oldSeller->name} to {$seller->name}."
                : "{$actorName} assigned {$seller->name} as seller.",
            $actorName,
            ['from' => $oldSellerId, 'to' => $seller->id, 'reason' => $reason]
        );

        // Assigning/switching a project's seller now also drops them straight
        // into project chat — no more waiting on a PM/Admin to separately
        // remember "Manage Participants". See ProjectChatService::addSeller().
        ProjectChatService::addSeller($project, $seller->id);

        // The OLD seller loses their formal tie to THIS project the moment
        // the switch happens (2026-09-29, on request) — EVERY
        // project_team_members row they hold here at this instant is
        // removed, whether it's their real 'sales' row or the self-handoff
        // cosmetic PM-seat placeholder. removeParticipantIfNoLongerEligible()
        // then drops their live chat participant row too, since
        // formalMemberIds() no longer includes them (unless they're still
        // independently a task assignee, which correctly keeps them). Only
        // the ACTIVE tie is removed — historical ChatMessage rows and this
        // ProjectSellerAssignment/SystemAuditLog trail are never touched.
        //
        // This only reaches rows that existed AT THE MOMENT of the switch. A
        // team row added AFTER this point — by a later, separate, deliberate
        // action (e.g. someone re-adding this same person to the team) —
        // is untouched and behaves exactly as before; see
        // ProjectSellerSwitchVisibilityTest for that case.
        if ($isSwitch && $oldSeller) {
            $project->teamMembers()->where('user_id', $oldSeller->id)->delete();
            ProjectChatService::removeParticipantIfNoLongerEligible($project, $oldSeller->id);
        }

        ProjectSellerAssignment::create([
            'company_id'           => $project->company_id,
            'project_id'           => $project->id,
            'old_seller_id'        => $oldSellerId,
            'new_seller_id'        => $seller->id,
            'assigned_by'          => $actorUserId,
            'assigned_by_admin_id' => $actorAdminId,
            'reason'               => $reason,
        ]);

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => $isSwitch ? 'seller_switched' : 'seller_assigned',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'old_values'  => ['seller_id' => $oldSellerId],
            'new_values'  => ['seller_id' => $seller->id, 'reason' => $reason],
        ]);

        NotificationService::send([
            'company_id'        => $project->company_id,
            'recipient_user_id' => $seller->id,
            'actor_user_id'     => $actorUserId,
            'actor_admin_id'    => $actorAdminId,
            'module'            => 'project_management',
            'type'              => 'project_seller_assigned',
            'title'             => 'Project assigned to you',
            'message'           => "{$actorName} assigned project \"{$project->name}\" to you.",
            'entity_type'       => 'Project',
            'entity_id'         => $project->id,
            'url'               => "/projects/{$project->id}",
        ]);

        if ($isSwitch && $oldSeller) {
            NotificationService::send([
                'company_id'        => $project->company_id,
                'recipient_user_id' => $oldSeller->id,
                'actor_user_id'     => $actorUserId,
                'actor_admin_id'    => $actorAdminId,
                'module'            => 'project_management',
                'type'              => 'project_seller_reassigned',
                'title'             => 'Project reassigned',
                'message'           => "{$actorName} reassigned project \"{$project->name}\" from you to {$seller->name}.",
                'entity_type'       => 'Project',
                'entity_id'         => $project->id,
                'url'               => "/projects/{$project->id}",
            ]);
        }

        // Skip if the PM is also the new or previous seller — they already
        // got the "assigned to you"/"reassigned" notification above, and
        // sending a second, differently-worded notification about the same
        // event to the same person would just be a confusing duplicate.
        $pmAlreadyNotified = $project->project_manager_id
            && ((int) $project->project_manager_id === (int) $seller->id || (int) $project->project_manager_id === (int) ($oldSeller->id ?? 0));

        if ($project->project_manager_id && !$pmAlreadyNotified) {
            NotificationService::send([
                'company_id'        => $project->company_id,
                'recipient_user_id' => $project->project_manager_id,
                'actor_user_id'     => $actorUserId,
                'actor_admin_id'    => $actorAdminId,
                'module'            => 'project_management',
                'type'              => 'project_seller_changed',
                'title'             => 'Project seller updated',
                'message'           => $isSwitch
                    ? "Project \"{$project->name}\" seller was switched to {$seller->name}."
                    : "Project \"{$project->name}\" was assigned to seller {$seller->name}.",
                'entity_type'       => 'Project',
                'entity_id'         => $project->id,
                'url'               => "/projects/{$project->id}",
            ]);
        }

        // Company Admin. A Company Admin is not a `users` row, so the
        // recipient_user_id sends above can never reach them — routed through
        // notifyCompanyAdmins() for the same reason PaymentProjectStartService
        // does. Handing a project from one seller to another is exactly the
        // kind of ownership change the tenant owner is expected to see, and
        // they were previously the only party left out of it.
        //
        // Guarded: the seller change itself has already been committed, and a
        // notification failure must not roll it back or surface as an error to
        // whoever performed the switch.
        try {
            NotificationService::notifyCompanyAdmins($project->company_id, $actorAdminId, [
                'module'      => 'project_management',
                'type'        => $isSwitch ? 'project_seller_switched' : 'project_seller_assigned',
                'title'       => $isSwitch ? 'Project seller switched' : 'Project seller assigned',
                'message'     => $isSwitch && $oldSeller
                    ? "{$actorName} switched project \"{$project->name}\" from {$oldSeller->name} to {$seller->name}."
                        . ($reason ? " Reason: {$reason}" : '')
                    : "{$actorName} assigned project \"{$project->name}\" to {$seller->name}.",
                'entity_type' => 'Project',
                'entity_id'   => $project->id,
                'url'         => "/admin/projects/{$project->id}",
            ]);
        } catch (\Throwable $e) {
            Log::warning('[seller-assignment] company admin notification failed', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return ['changed' => true, 'is_switch' => $isSwitch, 'old_seller' => $oldSeller];
    }
}
