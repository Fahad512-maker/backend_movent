<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

// One Project = one CLIENT-FACING chat thread (thread_type='project_client',
// see the 2026_08_12_11* migrations and their DB-level unique constraint).
// Shared by all three guards — Api\Client\ProjectChatController,
// Api\User\ProjectClientChatController and Api\Admin\ProjectClientChatController
// — for the same reason ProjectChatService is shared: the
// get-or-atomically-create race handling must behave identically everywhere.
//
// Membership is derived from the project itself: the client who owns it
// (their portal `users` row) and its own linked Seller (projects.seller_id)
// are always in, automatically. The project's Project Manager(s) are the one
// invitable party — never auto-added, only ever pulled in by the Seller (see
// invitablePmIds() and Api\User\ProjectClientChatController::invitePm()), and
// then either with full history or only from the invite onward
// (chat_participants.history_from_message_id). Company Admin is deliberately
// NOT a participant row — it has no `users` id and always has implicit
// access to every project's client chat, the same convention as
// Api\Admin\ProjectMessengerController / GeneralChatController.
//
// Deliberately separate from ProjectChatService's internal 'project_group'
// thread: the internal team conversation must never be visible to a client,
// and this one must never be visible to the wider production team. It is
// also per-PROJECT, unlike the per-CLIENT 'sales' thread behind
// Api\Client\ChatController — a client with three projects gets three
// distinct conversations, and only ever sees the ones for projects that are
// actually theirs.
class ProjectClientChatService
{
    // Creates the thread if it doesn't exist yet. Safe for any of the three
    // guards to call: unlike the internal messenger there is no "can this
    // person cause the thread to exist" question here — the only actors who
    // ever reach it are the project's own client, its own Seller, and
    // Company Admin, all of whom are legitimately in the conversation.
    public static function threadFor(Project $project): ChatThread
    {
        $thread = static::existingThreadFor($project);

        if (!$thread) {
            try {
                $thread = ChatThread::create([
                    'company_id'     => $project->company_id,
                    'thread_type'    => 'project_client',
                    'linked_to_type' => 'Project',
                    'linked_to_id'   => $project->id,
                    'title'          => $project->name,
                    'visibility'     => 'client_facing',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a create race to a concurrent first-access — the
                // winner's row is now there, fetch it instead of failing.
                $thread = static::existingThreadFor($project) ?? throw $e;
            }
        }

        static::syncParticipants($project, $thread);

        return $thread;
    }

    // Read-only — never creates. Returns null if nobody has opened this
    // project's client chat yet.
    public static function existingThreadFor(Project $project): ?ChatThread
    {
        return ChatThread::where('company_id', $project->company_id)
            ->where('linked_to_type', 'Project')
            ->where('linked_to_id', $project->id)
            ->where('thread_type', 'project_client')
            ->first();
    }

    // Keeps the two `users`-backed sides of the conversation present as real
    // participant rows so their last_read_at state persists and the "who's in
    // this chat" header is accurate. Idempotent — safe to call on every
    // access. Membership is derived, never manually managed, so this both
    // ADDS (a Seller assigned later, or a client whose portal access is
    // enabled later, joins on the next access) and PRUNES: a Seller replaced
    // by a handoff stops being in the conversation immediately, which is the
    // whole point of scoping this to the project's CURRENT counterpart. Their
    // past messages are untouched — sender_id still attributes them, exactly
    // like project_activities keeps a causer_name for people no longer on the
    // project.
    public static function syncParticipants(Project $project, ChatThread $thread): void
    {
        $autoIn = static::participantIds($project);

        foreach ($autoIn as $userId) {
            ChatParticipant::firstOrCreate(
                ['thread_id' => $thread->id, 'user_id' => $userId],
                ['role' => 'member', 'joined_at' => now()]
            );
        }

        // The project's Project Manager(s) are ALLOWED to be here but are
        // never auto-added — they only ever get in when the Seller explicitly
        // invites them (Api\User\ProjectClientChatController::invitePm()), so
        // their row must survive this prune while still disappearing if the
        // project's PM later changes to someone else.
        $allowed = $autoIn->merge(static::invitablePmIds($project))->filter()->unique()->values();

        ChatParticipant::where('thread_id', $thread->id)
            ->whereNotIn('user_id', $allowed->all() ?: [0])
            ->delete();
    }

    // The Project Manager(s) a Seller may invite into this project's client
    // chat — empty ONLY when this project genuinely has no manager on it.
    // Three ways to qualify, because a project can record its manager in any
    // of them and all three mean "this PM is on this project":
    //   - projects.project_manager_id (the designated manager);
    //   - a team member carrying role_in_project='project_manager' (same as
    //     Api\User\ProjectMessengerController::isProjectPmUser());
    //   - a team member whose ACCOUNT is a Project Manager
    //     (users.role_type='project_manager'), whatever role_in_project label
    //     they were given when added. Assigning a PM to a project's team as a
    //     plain 'team_member' is common, and they are still the PM the Seller
    //     means to loop into the client conversation.
    // Excluded from all three:
    //   - the Seller themselves. 2026_08_11_150000 backfilled
    //     project_manager_id = seller_id for seller-run projects purely so the
    //     "Project Manager" column shows a name instead of "Unassigned", so a
    //     project can LOOK like it has a manager while having nobody to invite.
    //   - any role_type='seller' account, even one recorded as PM via a
    //     handoff — same rule as isProjectPmUser().
    //   - deactivated accounts.
    public static function invitablePmIds(Project $project)
    {
        $teamIds = $project->teamMembers()->pluck('user_id');

        $namedPmIds = collect([$project->project_manager_id])
            ->merge($project->teamMembers()->where('role_in_project', 'project_manager')->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        if ($namedPmIds->isEmpty() && $teamIds->isEmpty()) {
            return $namedPmIds;
        }

        return User::where('company_id', $project->company_id)
            ->where('is_active', true)
            ->where('role_type', '!=', 'seller')
            // A null seller_id would make a plain `!=` comparison match
            // nothing at all in SQL, hence the guard.
            ->when($project->seller_id, fn ($q) => $q->where('id', '!=', $project->seller_id))
            ->where(function ($q) use ($namedPmIds, $teamIds) {
                $q->whereIn('id', $namedPmIds->all() ?: [0]);

                if ($teamIds->isNotEmpty()) {
                    $q->orWhere(fn ($inner) => $inner
                        ->whereIn('id', $teamIds)
                        ->where('role_type', 'project_manager'));
                }
            })
            ->orderBy('name')
            ->pluck('id');
    }

    // The project's client portal user + the project's linked Seller. A
    // client with no portal account yet (portal_access false, or never
    // enabled) contributes nothing — the thread still works for the Seller
    // and Company Admin, and the client joins the moment their portal is
    // enabled.
    public static function participantIds(Project $project)
    {
        $clientUserId = $project->client_id
            ? Client::where('id', $project->client_id)->where('portal_access', true)->value('user_id')
            : null;

        return collect([$clientUserId, $project->seller_id])->filter()->unique()->values();
    }

    // The client portal `users` id for this project, or null — used by the
    // staff/Admin sides to notify the client of a new message.
    public static function clientUserId(Project $project): ?int
    {
        return $project->client_id
            ? Client::where('id', $project->client_id)->where('portal_access', true)->value('user_id')
            : null;
    }

    // ── @mentions ────────────────────────────────────────────────────────
    //
    // Everyone in this conversation can tag everyone else in it, never
    // themselves. Concretely that means the Seller can tag the Client and
    // Company Admin (plus the PM once invited), the Client can tag the Seller
    // and Company Admin (plus the invited PM), Company Admin can tag anyone,
    // and an invited PM can tag anyone but themselves — one rule covering all
    // four, since the participant list IS the conversation.

    // Sentinel id for "Company Admin" in mentions — Admin isn't a `users` row
    // (notifications.user_id is a real FK), so it can never collide with a
    // genuine mention target. Same convention and value as
    // Api\User\ProjectMessengerController::ADMIN_MENTION_ID and
    // ProjectCommentController's.
    public const ADMIN_MENTION_ID = 0;

    // Who the caller may tag. $excludeUserId is the caller when they're a
    // `users` row; $includeAdmin is false only when the caller IS Company
    // Admin (nobody tags themselves).
    public static function mentionablesFor(ChatThread $thread, ?int $excludeUserId, bool $includeAdmin)
    {
        $people = ChatParticipant::where('thread_id', $thread->id)
            ->when($excludeUserId, fn ($q) => $q->where('user_id', '!=', $excludeUserId))
            ->with('user:id,name,role_type')
            ->get()
            ->map(fn ($p) => [
                'user_id'   => $p->user_id,
                'name'      => $p->user?->name,
                'role_type' => $p->user?->role_type,
            ])
            ->filter(fn ($p) => $p['name'] !== null)
            ->values();

        return $includeAdmin
            ? $people->prepend([
                'user_id'   => static::ADMIN_MENTION_ID,
                'name'      => 'Company Admin',
                'role_type' => 'admin',
            ])
            : $people;
    }

    // Keeps only ids the caller is actually allowed to tag. Anything else is
    // silently dropped rather than failing the send — same convention as the
    // internal messenger's mention filter.
    public static function filterMentions(ChatThread $thread, ?array $requested, ?int $actorUserId, bool $adminAllowed)
    {
        $allowed = static::mentionablesFor($thread, $actorUserId, $adminAllowed)->pluck('user_id');

        return collect($requested ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn ($id) => $allowed->contains($id))
            ->values();
    }

    // One "you were mentioned" notification per tagged party, on top of the
    // ordinary new-message notification every participant already gets. The
    // ADMIN_MENTION_ID sentinel routes through NotificationService, the only
    // path that can target a Company Admin (recipient_admin_id).
    public static function notifyMentions(
        Project $project,
        ChatMessage $message,
        $mentions,
        string $senderName,
        ?int $actorUserId = null,
        ?int $actorAdminId = null
    ): void {
        if (collect($mentions)->isEmpty()) {
            return;
        }

        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $clientUserId = static::clientUserId($project);

        foreach ($mentions as $uid) {
            if ($uid === static::ADMIN_MENTION_ID) {
                NotificationService::notifyCompanyAdmins($project->company_id, $actorAdminId, [
                    'type'        => 'mentioned_in_client_chat',
                    'module'      => 'project_management',
                    'title'       => "You were mentioned — {$project->name}",
                    'message'     => "{$senderName}: {$preview}",
                    'entity_type' => 'Project',
                    'entity_id'   => $project->id,
                    'url'         => "/admin/projects/{$project->id}/client-chat",
                ]);
                continue;
            }

            if ($uid === $actorUserId) {
                continue;
            }

            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'mentioned_in_client_chat',
                'title'      => "You were mentioned — {$project->name}",
                'body'       => "{$senderName}: {$preview}",
                'data'       => [
                    'project_id' => $project->id,
                    'thread_id'  => $message->thread_id,
                    'message_id' => $message->id,
                    'link'       => $uid === $clientUserId
                        ? "/client/projects/{$project->id}?tab=chat"
                        : "/projects/{$project->id}/client-chat",
                ],
            ]);
        }
    }
}
