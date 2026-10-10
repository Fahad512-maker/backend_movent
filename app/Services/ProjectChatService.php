<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

// One Project = one chat thread (see the 2026_08_10_10* migrations that
// merged historical duplicates and added a DB-level unique constraint).
// Shared by both Api\User\ProjectMessengerController and
// Api\Admin\ProjectMessengerController — the only piece of this feature
// promoted out of the usual per-guard duplication, since the
// get-or-atomically-create race handling below needs to behave identically
// on both sides and is easy to get subtly wrong if written twice.
class ProjectChatService
{
    // Creates the thread if it doesn't exist yet. Callers must only use this
    // from a PM-tier/Admin code path — anyone else must use existingThreadFor()
    // so a regular staffer/Seller can never trigger creation just by looking.
    public static function threadFor(Project $project): ChatThread
    {
        $thread = static::existingThreadFor($project);
        if ($thread) {
            return $thread;
        }

        try {
            return ChatThread::create([
                'company_id'     => $project->company_id,
                'thread_type'    => 'project_group',
                'linked_to_type' => 'Project',
                'linked_to_id'   => $project->id,
                'visibility'     => 'internal',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a create race to a concurrent first-access — the winner's
            // row is now there, fetch it instead of failing the request.
            return static::existingThreadFor($project) ?? throw $e;
        }
    }

    /**
     * Where a CLIENT's notification about this conversation should land.
     *
     * The portal's floating "Talk to Seller" window (SellerProjectChatWindow,
     * opened by PortalAssistant) is mounted in the client layout, so it can
     * open over any portal page — the deep link therefore carries the project
     * id as a query parameter rather than pointing at a page of its own.
     * Mirrors the ?assistant_chat={threadId} convention the Direct Chat
     * notifications already use (Api\User\ClientChatController).
     *
     * One source of truth because three call sites write this link
     * (addParticipant() here, and notifyMessage()'s two loops in
     * Api\User\ProjectMessengerController) and a client landing on a link the
     * window does not recognise simply sees the dashboard, with no clue why.
     */
    public static function clientChatLink(int $projectId): string
    {
        return "/client/dashboard?seller_chat={$projectId}";
    }

    // Read-only — never creates. Returns null if nobody has caused the
    // project's chat to exist yet.
    public static function existingThreadFor(Project $project): ?ChatThread
    {
        return ChatThread::where('company_id', $project->company_id)
            ->where('linked_to_type', 'Project')
            ->where('linked_to_id', $project->id)
            ->where('thread_type', 'project_group')
            ->first();
    }

    // Keeps the project's formal team (PM, team members, task/production
    // assignees) automatically present in its one chat — a project's people
    // shouldn't have to wait for a manual add just to be mentionable/visible
    // in the team's main conversation. A Seller is the one deliberate
    // exception: even one recorded via a team/task assignment must NEVER be
    // auto-added — "Seller can be added only by Company Admin or Project
    // Manager" is a hard rule, not a default. Idempotent (firstOrCreate) and
    // safe to call on every PM/Admin show() — already-synced members are a
    // no-op, and only a truly new addition fires the "added to chat"
    // notification (guarded by wasRecentlyCreated). Same "from now" cutoff as
    // addTeamMember()/addTaskAssignee() — a newly-synced member never sees
    // the conversation from before they were tied to the project, however
    // that happened to be established.
    public static function syncFormalTeamParticipants(Project $project, ChatThread $thread): void
    {
        $memberIds = static::formalMemberIds($project);
        if ($memberIds->isEmpty()) {
            return;
        }

        $sellerIds = User::whereIn('id', $memberIds)->where('role_type', 'seller')->pluck('id');
        $autoAddIds = $memberIds->diff($sellerIds);
        $cutoff = static::currentMessageWatermark($thread);

        foreach ($autoAddIds as $userId) {
            $participant = ChatParticipant::firstOrCreate(
                ['thread_id' => $thread->id, 'user_id' => $userId],
                ['role' => 'member', 'joined_at' => now(), 'history_from_message_id' => $cutoff]
            );

            if (!$participant->wasRecentlyCreated) {
                continue;
            }

            Notification::create([
                'user_id'    => $userId,
                'company_id' => $project->company_id,
                'type'       => 'project_chat_added',
                'title'      => "Added to project chat — {$project->name}",
                'body'       => "You were added to project chat for '{$project->name}'.",
                'data'       => ['project_id' => $project->id, 'thread_id' => $thread->id, 'link' => "/projects/{$project->id}/chat"],
            ]);
        }
    }

    // The thread's current latest message id — the "from now" cutoff stamped
    // on a newly-joining participant so they never see what came before them
    // (0 on an empty thread, still not NULL, which is what distinguishes
    // "from now" from a full-history join everywhere this column is read).
    private static function currentMessageWatermark(ChatThread $thread): int
    {
        return (int) (ChatMessage::where('thread_id', $thread->id)->max('id') ?? 0);
    }

    // Shared by addSeller()/addTaskAssignee()/addTeamMember() — every "this
    // specific act of assignment is itself the authorization" call site.
    // Idempotent; creates the thread if needed and only notifies on a
    // genuinely new add (wasRecentlyCreated). $historyFromMessageId only
    // ever applies on that same first creation — firstOrCreate() leaves an
    // EXISTING participant's row (and their history) untouched, so
    // re-adding someone already in the conversation never narrows what they
    // can already see.
    private static function addParticipant(Project $project, int $userId, ?int $historyFromMessageId = null): void
    {
        $thread = static::threadFor($project);

        $participant = ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $userId],
            ['role' => 'member', 'joined_at' => now(), 'history_from_message_id' => $historyFromMessageId]
        );

        if (!$participant->wasRecentlyCreated) {
            return;
        }

        // The Client (added via addClient()) lives on the Client Portal, a
        // separate app tree with its own auth cookie — the staff route below
        // would 401 for them there (no staff session token to send) and trip
        // the staff axios interceptor's force-logout-on-401 handling. Only
        // reachable from this shared helper via addClient(); every other
        // caller (addSeller()/addTaskAssignee()/addTeamMember()) only ever
        // adds staff, for whom the staff route is correct.
        $link = User::find($userId)?->role_type === 'client'
            ? self::clientChatLink($project->id)
            : "/projects/{$project->id}/chat";

        Notification::create([
            'user_id'    => $userId,
            'company_id' => $project->company_id,
            'type'       => 'project_chat_added',
            'title'      => "Added to project chat — {$project->name}",
            'body'       => "You were added to project chat for '{$project->name}'.",
            'data'       => ['project_id' => $project->id, 'thread_id' => $thread->id, 'link' => $link],
        ]);
    }

    // Call right after a project's seller_id is set (handoff creation in
    // Api\User\ProjectController::store(), or ProjectSellerAssignmentService::
    // assign()) — NOT a violation of syncFormalTeamParticipants()'s "Seller
    // can be added only by Company Admin or Project Manager" rule: assigning
    // seller_id IS that authorized act (Admin's own "Assign Seller" action,
    // or a Seller's own sanctioned self-handoff), it just never also dropped
    // them into chat_participants.
    public static function addSeller(Project $project, int $sellerId): void
    {
        static::addParticipant($project, $sellerId);
    }

    // The project's Client, once their portal account is linked and enabled —
    // added to the SAME internal thread as the team, but this alone doesn't
    // expose team chatter to them: only ChatMessage rows with
    // visibility='client' are ever surfaced back to the Client (see
    // Api\Client\ProjectChatController, which reads this same thread filtered
    // that way). Safe to call repeatedly (addParticipant() is idempotent);
    // callers only invoke this from a staff view (Admin/PM opening Project
    // Chat), same convention as syncFormalTeamParticipants() — the Client
    // itself never reaches this service (Api\User\ProjectMessengerController
    // hard-blocks role_type='client').
    public static function addClient(Project $project, int $clientUserId): void
    {
        static::addParticipant($project, $clientUserId);
    }

    // Call right after a task's assigned_to is set (TaskController::store()/
    // update(), both Admin and User guards) — same convention as addSeller():
    // the assignment itself is the authorized act, so the new assignee
    // shouldn't have to wait for a PM to next open the chat page
    // (syncFormalTeamParticipants()) before they can see/send in it. Never
    // called with a Seller's id — assignedToRule() already keeps a Seller
    // from ever being a task's assigned_to. "From now" cutoff — a newly
    // assigned Developer/Designer/QA/Production never sees the conversation
    // from before their assignment.
    public static function addTaskAssignee(Project $project, int $userId): void
    {
        $thread = static::threadFor($project);
        static::addParticipant($project, $userId, static::currentMessageWatermark($thread));
    }

    // Call right after a user is added/updated as a formal team member
    // (assignTeam()'s per-member loop, both Admin and User guards) — same
    // convention as addTaskAssignee(): being formally added to the team IS
    // the authorized act, so e.g. a Seller handing the project off to a new
    // Project Manager via the Team page shouldn't leave that PM stuck
    // waiting for someone else to next open the chat page before they can
    // see/send in it. Callers must NEVER invoke this for a Seller being
    // added to the team — mirrors syncFormalTeamParticipants()'s hard
    // "Seller can never be auto-added" rule. "From now" cutoff, same as
    // addTaskAssignee() — a newly added Developer/Designer/QA/Project
    // Manager/plain team member never sees the conversation from before they
    // joined the project.
    public static function addTeamMember(Project $project, int $userId): void
    {
        $thread = static::threadFor($project);
        static::addParticipant($project, $userId, static::currentMessageWatermark($thread));
    }

    // Call after a user stops being formally tied to a project (e.g.
    // removed from its team) — if they're no longer eligible via ANY path
    // (team, task/production assignment, or project_manager_id) they lose
    // their chat participant row too, so they immediately stop being
    // taggable/visible in the project's one chat. A no-op if they're still
    // tied some other way (e.g. still assigned to an open task), or if no
    // chat thread exists yet.
    public static function removeParticipantIfNoLongerEligible(Project $project, int $userId): void
    {
        $thread = static::existingThreadFor($project);
        if (!$thread) {
            return;
        }

        if (static::formalMemberIds($project)->contains($userId)) {
            return;
        }

        ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->delete();
    }

    // Users formally tied to the project: PM, team members, task assignees,
    // and each task's production handoff assignee. Mirrors the per-guard
    // projectMemberIds() in both ProjectMessengerControllers exactly, so
    // auto-add/auto-remove here never disagrees with the manual-add
    // eligibility check they enforce.
    private static function formalMemberIds(Project $project)
    {
        $teamIds = $project->teamMembers()->pluck('user_id');
        $taskAssigneeIds = $project->tasks()->whereNotNull('assigned_to')->pluck('assigned_to');
        $productionAssigneeIds = $project->tasks()->whereNotNull('production_assigned_to')->pluck('production_assigned_to');

        return collect([$project->project_manager_id])
            ->merge($teamIds)->merge($taskAssigneeIds)->merge($productionAssigneeIds)
            ->filter()->unique()->values();
    }
}
