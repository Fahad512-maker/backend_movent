<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectCommentLike;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AttachmentStorageService;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectCommentController extends Controller
{
    private function user() { return auth('sanctum')->user(); }
    private function userName(): string { return trim((string) ($this->user()->name ?? '')) ?: 'User'; }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Deliberately NOT mirroring Api\User\ProjectController::visibleProjects()'s
    // canViewAllCompanyProjects bypass — project comments are restricted to
    // actual project membership (PM, team member, task-assignee, or the
    // linked seller/creator/lead/client) even for a company-wide viewer.
    // canViewAllCompanyProjects still works everywhere else in the app
    // (attachments, chat, project list, dashboards); here specifically, a
    // Developer/Designer/etc. holding it must not be able to view or post
    // comments on a project they were never actually added to.
    private function project(int $projectId): Project
    {
        $user = $this->user();

        return Project::where('company_id', $user->company_id)
            ->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhere('seller_id', $user->id)
                  ->orWhereHas('teamMembers', fn($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn($t) => $t->where('assigned_to', $user->id))
                  ->orWhereHas('lead', fn($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                  ->orWhereHas('client', fn($c) => $c->where('account_manager', $user->id));

                // A project with literally no PM/seller/team assigned yet has
                // no real "team" boundary to protect — the membership
                // restriction above is meant to keep outsiders off projects
                // that DO have a team, not to make a freshly-created,
                // not-yet-staffed project uncommentable by everyone
                // (including a company-wide viewer) until someone gets
                // formally assigned.
                if ($this->can('canViewAllCompanyProjects')) {
                    $q->orWhere(function ($u) {
                        $u->whereNull('project_manager_id')
                          ->whereNull('seller_id')
                          ->doesntHave('teamMembers');
                    });
                }
            })
            ->findOrFail($projectId);
    }

    // "Internal staff" (real team/PM) vs. a Seller following up on their own
    // linked project — the latter never sees 'internal' comments (rule 12:
    // hide internal PM notes) and can only ever post 'client'-visible ones.
    // role_type check comes FIRST and short-circuits to false for a Seller
    // no matter what permissions they hold — otherwise a Company Admin
    // granting a Seller something like canViewTasks (e.g. via the
    // simplified permission UI's "Manage Tasks" bundle, not realizing that
    // bundle isn't meant for this role) would silently reclassify them as
    // internal staff here and leak every internal comment on the project to
    // them, defeating the whole Seller-isolation boundary this controller
    // otherwise enforces carefully.
    private function isInternalStaff(): bool
    {
        if ($this->user()->role_type === 'seller') return false;
        return $this->can('canViewTasks') || $this->can('canViewAllCompanyProjects');
    }

    // "PM tier" for the Seller-conversation boundary specifically: this
    // project's literal assigned project_manager_id, OR a genuine Project
    // Manager (role_type='project_manager') with company-wide oversight
    // (canViewAllCompanyProjects) who isn't individually assigned to this
    // one project but still manages it in practice. Deliberately keyed off
    // role_type, not just the permission alone — canViewAllCompanyProjects
    // can be granted to any role (a Developer/Designer/QA/Production/Team
    // Member holding it must still NOT reach Seller conversations; that gap
    // was the real bug behind a prior fix here). Company Admin is never a
    // `users` row so is handled separately wherever this is checked.
    private function isProjectPmTier(Project $project): bool
    {
        $user = $this->user();
        return $project->project_manager_id === $user->id
            || ($user->role_type === 'project_manager' && $this->can('canViewAllCompanyProjects'));
    }

    // Strips any Seller-role user out of a notification recipient list — an
    // internal-visibility comment's notification carries a preview of the
    // comment body, so a Seller ending up in the recipients (e.g. recorded
    // as this project's project_manager_id via a handoff, or present in the
    // team-members notify-fallback) would leak internal content through the
    // notification bell even though index() correctly hides it from their
    // comment feed. Accepts/returns a plain id collection.
    private function excludeSellers($userIds)
    {
        $sellerIds = User::whereIn('id', collect($userIds)->filter())->where('role_type', 'seller')->pluck('id');
        return collect($userIds)->reject(fn ($id) => $sellerIds->contains($id))->values();
    }

    // Checks a permission for ANOTHER user (a mention candidate), always
    // scoped to the acting user's own company (mentions never cross companies).
    private function hasPerm(int $userId, string $permKey): bool
    {
        return UserCompanyPermission::where('user_id', $userId)
            ->where('company_id', $this->user()->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Sentinel id for "Company Admin" in mention lists — Admin isn't a
    // `users` row (notifications.user_id is a required FK to users), so it
    // can never be a real candidate id. 0 is safe: real user ids start at 1.
    private const ADMIN_MENTION_ID = 0;

    // Strips any Seller-role user out of a mention-candidate list — used for
    // task-scoped comments (see mentionCandidates()'s $taskId param): a Seller
    // has nothing to do with a task's internal/production work, so they're
    // never offered as a tag target there, regardless of visibility tier or
    // PM status.
    private function excludeSellerCandidates(array $candidates): array
    {
        $ids = collect($candidates)->pluck('user_id')->filter();
        $sellerIds = User::whereIn('id', $ids)->where('role_type', 'seller')->pluck('id');
        return collect($candidates)->reject(fn ($c) => $sellerIds->contains($c['user_id']))->values()->all();
    }

    // Candidate @mention lists for this project, split by visibility tier.
    // Never includes the acting user themselves (self-mention is meaningless
    // — notifyMentions() already no-ops on it, this just keeps it out of the
    // picker too). Company Admin is always offered — Admin oversees every
    // project regardless of visibility.
    //   - This project's actual PM gets every active company user (see the
    //     early return below) — same unrestricted list Company Admin has,
    //     since PM is the one role allowed to bridge every tier (tag a
    //     Seller into an internal comment, post client-facing, etc.).
    //   - internal (non-PM): narrower — only project team members / task-
    //     assignees who hold canViewTasks or canViewAllCompanyProjects
    //     (mirrors isInternalStaff()). No Seller here even if one is linked
    //     to the project — store()'s tag-rule would 403 a non-PM/non-Admin
    //     attempt anyway, so suggesting the Seller would just be a dead end.
    //   - client: the PM (if canAddClientFacingComment) plus any Seller
    //     actually linked to this project's lead/client/handoff (same
    //     linkage checks project()/hasLinkedAccess() already use elsewhere).
    //   - $taskId: when this comment is scoped to a specific task, a Seller
    //     is stripped out of whatever the tier above would otherwise offer —
    //     a Seller has nothing to do with a task's internal work, so this
    //     applies even to the PM/full-company list and the 'client' tier's
    //     own linked-Seller entry.
    private function mentionCandidates(Project $project, string $visibility, ?int $taskId = null): array
    {
        $selfId = $this->user()->id;
        $adminOption = ['user_id' => self::ADMIN_MENTION_ID, 'name' => 'Company Admin'];

        // This project's PM tier (see isProjectPmTier()) gets this project's
        // own team (PM + team members + task-assignees) plus its linked
        // Seller and Company Admin — NOT the whole company's user list. PM
        // is still the one role allowed to bridge every tier (tag the linked
        // Seller into an internal comment, post client-facing, etc. — see
        // store()'s tag-rule), so the Seller stays in this list unconditionally
        // (unless task-scoped, see below) — only the "every unrelated company
        // user" leak is removed. Regular internal staff below already get
        // this same narrower, project-scoped list (minus the Seller).
        //
        // Deliberately requires isInternalStaff() too — project_manager_id
        // can itself be a Seller (a project handed off to them, per the
        // Manager-label fix elsewhere this session). Without this guard,
        // such a Seller would get this project's team list for what's
        // supposed to be their strictly client-facing 'client'-tier request,
        // and store()'s mention filter (which reuses this same list) would
        // let them actually tag those people too — "Seller cannot
        // communicate directly with internal project team" would be
        // silently bypassed for a handoff Seller.
        if ($this->isInternalStaff() && $this->isProjectPmTier($project)) {
            $ids = collect([$project->project_manager_id, $project->seller_id])
                ->merge($project->teamMembers()->pluck('user_id'))
                ->merge(Task::where('project_id', $project->id)->pluck('assigned_to'))
                ->filter()->unique();

            $users = User::whereIn('id', $ids)->where('id', '!=', $selfId)->orderBy('name')->get(['id', 'name']);

            $fullList = [...$users->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name])->values()->all(), $adminOption];
            return $taskId ? $this->excludeSellerCandidates($fullList) : $fullList;
        }

        // Requires isInternalStaff() too — mentionableUsers() trusts the
        // ?visibility query param as-is, so without this a Seller (or anyone
        // else outside the internal tier) could request visibility=internal
        // directly and be handed the project's Developer/Designer/QA/
        // Production candidate list, even though they can never legitimately
        // post at that tier. A non-internal-staff caller always falls
        // through to the client-tier branch below regardless of what
        // visibility they asked for.
        if ($visibility === 'internal' && $this->isInternalStaff()) {
            // Unconditionally Seller-free, task-scoped or not — this
            // project's project_manager_id can itself be a Seller (a handoff,
            // per the note above), and a handoff Seller acting as PM may well
            // hold canViewTasks/canViewAllCompanyProjects legitimately for
            // this one project. A regular internal staff member (Developer/
            // Designer/QA/Production/Team Member) can NEVER tag a Seller at
            // all, at any scope — only Company Admin/PM may (store()'s
            // tag-rule 403s any other attempt) — so a Seller must never even
            // appear as a suggestion here, project-level or task-level.
            $ids = collect([$project->project_manager_id])
                ->merge($project->teamMembers()->pluck('user_id'))
                ->merge(Task::where('project_id', $project->id)->pluck('assigned_to'))
                ->filter()->unique();

            $users = User::whereIn('id', $ids)->where('id', '!=', $selfId)->get(['id', 'name']);
            $candidates = $users->filter(fn ($u) => $this->hasPerm($u->id, 'canViewTasks') || $this->hasPerm($u->id, 'canViewAllCompanyProjects'))
                ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name])->values()->all();

            return $this->excludeSellerCandidates([...$candidates, $adminOption]);
        }

        $ids = collect([$project->project_manager_id, $project->seller_id, $project->created_by])->filter()->unique();

        if ($project->lead_id) {
            $lead = Lead::find($project->lead_id);
            if ($lead) $ids = $ids->merge(collect([$lead->assigned_to, $lead->transferred_to])->filter());
        }
        if ($project->client_id) {
            $client = Client::find($project->client_id);
            if ($client?->account_manager) $ids->push($client->account_manager);
        }

        $users = User::whereIn('id', $ids->unique())->where('id', '!=', $selfId)->get(['id', 'name']);
        $candidates = $users->filter(fn ($u) => $this->hasPerm($u->id, 'canAddClientFacingComment'))
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name])->values()->all();

        $result = [...$candidates, $adminOption];
        return $taskId ? $this->excludeSellerCandidates($result) : $result;
    }

    // GET /user/projects/{id}/mentionable-users?visibility=internal|client&task_id=
    public function mentionableUsers(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $visibility = $request->get('visibility') === 'client' ? 'client' : 'internal';
        $taskId = $request->filled('task_id') ? (int) $request->task_id : null;

        return ApiResponse::success($this->mentionCandidates($project, $visibility, $taskId));
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        $isInternal = $this->isInternalStaff();
        if (!$isInternal && !$this->can('canViewProjects') && !$this->can('canViewLinkedProjects') && !$this->can('canAddClientFacingComment')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->project($projectId);

        $q = ProjectComment::where('project_id', $project->id)
            ->with(['authorAdmin:id,name', 'authorUser:id,name,role_type', 'attachments.uploadedByAdmin:id,name', 'attachments.uploadedByUser:id,name', 'likes.user:id,name', 'likes.admin:id,name']);

        $userId = $this->user()->id;
        // Seller-authored comments are "PM tier + Company Admin only" — see
        // isProjectPmTier(): this project's literal assigned PM, OR a genuine
        // Project Manager (role_type) with company-wide oversight. A
        // Developer/Designer/QA/Production/Team Member who happens to hold
        // that same canViewAllCompanyProjects permission still must not see
        // them — the role_type check is what keeps that gap closed.
        $isProjectPm = $this->isProjectPmTier($project);

        if (!$isInternal) {
            // A Seller otherwise only ever sees client-facing rows — the
            // orWhereJsonContains exception lets through the ONE specific
            // internal comment they were validly tagged into (see store()'s
            // tag-rule below), never the surrounding internal thread, since
            // this is a per-row filter, not a thread/context expansion.
            // seller_reply rows are fetched broadly here (every row in the
            // project, not just ones this Seller authored) and narrowed down
            // to "MY thread" below — a naive author_user_id-only filter would
            // only ever show the Seller their OWN messages, never the PM's or
            // Admin's replies back (those are author_admin_id/a different
            // author_user_id), which would make the "conversation" one-sided.
            $q->where(function ($w) use ($userId) {
                $w->where('visibility', 'client')
                  ->orWhereJsonContains('mentions', $userId)
                  ->orWhere('visibility', 'seller_reply');
            });
        } elseif (!$isProjectPm) {
            // Regular internal staff (Developer/Designer/QA/Production/Team
            // Member — anyone who isn't THIS project's actual assigned PM)
            // only ever see INTERNAL-tier comments, i.e. their own team's
            // working notes. Both the Seller<->PM/Admin/Client conversation
            // (visibility=client) and a Seller's private reply
            // (seller_reply) are Company Admin/PM territory — the wider
            // team never sees either one.
            $q->where('visibility', 'internal');
        }

        if ($request->filled('task_id')) {
            $q->where('task_id', $request->task_id);
        } else {
            // No task_id means "Project Overview" context — task-scoped
            // comments belong on their own Task page only, never here.
            $q->whereNull('task_id');
        }

        $comments = $q->orderByDesc('created_at')->get();

        // A PM/Admin comment that tags a Seller is part of that Seller
        // conversation, even though it's stored as visibility=internal (only
        // the Seller's own reply gets the dedicated seller_reply tier) — so
        // it must be hidden from the wider internal team the same way the
        // reply already is. Post-fetch filter (not a query clause) since it
        // needs a role_type lookup on whatever ids are sitting in `mentions`.
        if ($isInternal && !$isProjectPm) {
            $sellerIds = User::where('company_id', $this->user()->company_id)->where('role_type', 'seller')->pluck('id');
            $comments = $comments->reject(fn ($c) => $c->visibility === 'internal' && !empty($c->mentions) && collect($c->mentions)->intersect($sellerIds)->isNotEmpty())->values();
        }

        // Narrow the broadly-fetched seller_reply rows above down to "this
        // Seller's own thread" — findSellerReplyThreadOwner() walks each
        // row's parent chain back to whoever the thread actually belongs to
        // (the original tagged Seller), regardless of whether THIS particular
        // row was authored by that Seller, the PM, or Admin. Without this, a
        // Seller would only ever see their own messages in the conversation,
        // never the PM's/Admin's replies back.
        if (!$isInternal) {
            $comments = $comments->reject(fn ($c) => $c->visibility === 'seller_reply' && $this->findSellerReplyThreadOwner($c) !== $userId)->values();
        }

        $this->appendLikeFields($comments, $userId);

        return ApiResponse::success($comments);
    }

    // Attaches the same "who liked this / did I like it" data every returned
    // comment needs, without shipping the raw pivot rows themselves — those
    // are dropped (unset) once summarized, keeping the payload light. Works
    // on a single comment (store()/update()'s response) or a collection
    // (index()) since both just need `likes` eager-loaded first.
    private function appendLikeFields($comments, int $userId): void
    {
        $collection = $comments instanceof ProjectComment ? collect([$comments]) : $comments;

        foreach ($collection as $c) {
            $c->likes_count = $c->likes->count();
            $c->liked_by_me = $c->likes->contains(fn ($l) => $l->user_id === $userId);
            $c->liked_by = $c->likes->map(fn ($l) => $l->user?->name ?? $l->admin?->name ?? 'Someone')->values();
            unset($c->likes);
        }
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        $isInternal = $this->isInternalStaff();

        // A non-internal actor (Seller) needs the specific add-permission to
        // post at all here — merely being able to VIEW the project (index()'s
        // broader gate) isn't enough to post a client-facing comment.
        if (!$isInternal && !$this->can('canAddClientFacingComment')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'task_id'              => ['nullable', 'integer', 'exists:tasks,id'],
            'deliverable_id'       => ['nullable', 'integer', 'exists:deliverables,id'],
            'parent_comment_id'    => ['nullable', 'integer', 'exists:project_comments,id'],
            'body'                 => ['required', 'string'],
            'visibility'           => ['nullable', 'in:internal,client'],
            'mentioned_user_ids'   => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['integer'],
        ]);

        $parentComment = !empty($validated['parent_comment_id'])
            ? ProjectComment::where('project_id', $project->id)->find($validated['parent_comment_id'])
            : null;

        // A Seller replying to the one internal comment they were validly
        // tagged into (index()'s mentions exception) isn't posting a normal
        // client-facing comment — it's routed to the seller_reply tier
        // below, restricted to Company Admin/PM only, never the wider team
        // or the client. Any other reply/new comment from a Seller still
        // always resolves to 'client' as before.
        $isTaggedReply = !$isInternal && $parentComment
            && $parentComment->visibility === 'internal'
            && in_array($this->user()->id, $parentComment->mentions ?? [], true);

        // Replying INTO an existing seller_reply thread (PM/Admin continuing
        // it, or the same Seller sending a follow-up) must stay in that same
        // closed tier — letting it fall through to 'internal'/'client' would
        // widen a private PM+Admin+Seller conversation to the whole team or
        // the client. For a non-PM actor this can't just check the immediate
        // parent's author — a multi-turn thread alternates authorship
        // between the Seller and PM/Admin each reply, so the Seller's own
        // earlier turn might be several levels up. Walk the chain instead:
        // does this actor's own id show up anywhere as an author in this
        // thread, or in the root tagged comment's mentions? A random
        // internal staffer who isn't part of the thread can't self-admit
        // into it just by supplying a seller_reply comment's id.
        $isSellerThreadReply = false;
        if ($parentComment && $parentComment->visibility === 'seller_reply') {
            if ($this->isProjectPmTier($project)) {
                $isSellerThreadReply = true;
            } else {
                $node = $parentComment;
                for ($depth = 0; $node && $depth < 50; $depth++) {
                    if ($node->author_user_id === $this->user()->id) { $isSellerThreadReply = true; break; }
                    if ($node->visibility === 'internal' && in_array($this->user()->id, $node->mentions ?? [], true)) { $isSellerThreadReply = true; break; }
                    $node = $node->parent_comment_id ? ProjectComment::find($node->parent_comment_id) : null;
                }
            }
        }

        $rawMentionIds = collect($validated['mentioned_user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();

        // A Seller can also INITIATE a brand-new private thread (not just
        // reply to one PM/Admin started) by tagging Company Admin
        // (ADMIN_MENTION_ID) and/or this project's actual PM on a top-level
        // comment (no parent) — same closed seller_reply tier and fixed
        // PM+Admin audience as a reply. Every tagged id must resolve to one
        // of those two — tagging anyone else (or mixing in a real internal
        // id) falls through to the normal 'client' tier instead, same as an
        // untagged Seller comment, rather than silently admitting a third
        // party into what's supposed to be a closed conversation.
        $isSellerInitiatedTag = !$isInternal && !$parentComment && $rawMentionIds->isNotEmpty()
            && $rawMentionIds->every(fn ($id) => $id === self::ADMIN_MENTION_ID || $id === $project->project_manager_id);

        // Seller -> Developer/Production direct comment is explicitly blocked
        // here, not just silently coerced — PM must bridge the two sides.
        $visibility = $isSellerThreadReply
            ? 'seller_reply'
            : ($isSellerInitiatedTag
                ? 'seller_reply'
                : ($isInternal
                    ? ($validated['visibility'] ?? 'internal')
                    : ($isTaggedReply ? 'seller_reply' : 'client')));
        if (!$isInternal && $visibility === 'internal') {
            return ApiResponse::error('Seller cannot communicate directly with internal project team. Please contact the Project Manager.', 403);
        }

        // Symmetric gate on the other side: a plain internal team member
        // (Developer/Designer/QA/Production/Team Member — anyone who merely
        // holds canViewTasks) must not be able to mark their own note
        // client-facing. Only the PM/canViewAllCompanyProjects holder or
        // someone explicitly granted canAddClientFacingComment may post
        // visibility=client from the internal side.
        if ($isInternal && $visibility === 'client') {
            $isPmOrModerator = $project->project_manager_id === $this->user()->id || $this->can('canViewAllCompanyProjects');
            if (!$isPmOrModerator && !$this->can('canAddClientFacingComment')) {
                return ApiResponse::error('Only the Project Manager can post a client-facing comment.', 403);
            }
        }

        // Tag rule: mentioning a Seller into an INTERNAL comment is what
        // grants them visibility into that one comment (see index()'s
        // orWhereJsonContains exception) — only Company Admin or THIS
        // project's actual assigned PM may do that. Deliberately the same
        // strict project_manager_id-only definition index() uses (not the
        // broader canViewAllCompanyProjects "moderator" check used
        // elsewhere in this file) — a Developer/Designer/QA/Production/Team
        // Member who happens to hold that company-wide permission must not
        // be able to open a Seller conversation either, matching the same
        // absolute "PM + Company Admin only" boundary the visibility side
        // enforces. This is a hard block on the whole submission, not a
        // silent mention-drop, so the actor gets clear feedback rather than
        // a comment that quietly posted without the mention they intended.
        // Client-facing mentions need no extra gate — a linked Seller
        // already sees every client-facing comment regardless of tagging.
        //
        // Deliberately checked against the RAW submitted ids, not the
        // candidate-filtered $mentions below — a Seller isn't even offered
        // as a mention candidate to non-PM/non-Admin internal staff (see
        // mentionCandidates()), so checking the filtered list here would let
        // a Developer's tag attempt get silently dropped instead of hitting
        // this explicit block, and the comment would post successfully
        // minus the mention with no explanation at all.
        if ($visibility === 'internal' && $rawMentionIds->isNotEmpty()) {
            $taggedSeller = User::whereIn('id', $rawMentionIds)->where('role_type', 'seller')->exists();
            if ($taggedSeller && !$this->isProjectPmTier($project)) {
                return ApiResponse::error('Only Company Admin or Project Manager can mention Seller in project comments.', 403);
            }
        }

        // A task's comments are internal/production work — a Seller has
        // nothing to do with a task, so tagging one there is blocked outright
        // regardless of visibility tier or PM status (unlike the project-wide
        // tag-rule above, which does allow PM/Admin to bridge a Seller into a
        // project-level internal comment). Checked against the raw ids for
        // the same reason as above: a silent drop would leave the actor
        // wondering why their tag disappeared.
        if (!empty($validated['task_id']) && $rawMentionIds->isNotEmpty()) {
            $taggedSeller = User::whereIn('id', $rawMentionIds)->where('role_type', 'seller')->exists();
            if ($taggedSeller) {
                return ApiResponse::error('Seller cannot be tagged in task comments.', 403);
            }
        }

        // @mentions: only ids that are actually eligible mention candidates
        // for this comment's resolved visibility survive — anything else
        // (e.g. a Seller trying to @mention a Developer on an internal
        // comment they can't even post) is silently dropped rather than
        // rejecting the whole comment. seller_reply has no mention picker at
        // all — its audience (PM + Company Admin) is fixed regardless of
        // tagging, so this is skipped entirely for that tier.
        $mentions = collect();
        if ($visibility !== 'seller_reply') {
            $allowedIds = collect($this->mentionCandidates($project, $visibility, $validated['task_id'] ?? null))->pluck('user_id');
            $mentions = $rawMentionIds->filter(fn ($id) => $allowedIds->contains($id))->values();
        }

        $comment = ProjectComment::create([
            'company_id'        => $project->company_id,
            'project_id'        => $project->id,
            'task_id'           => $validated['task_id'] ?? null,
            'deliverable_id'    => $validated['deliverable_id'] ?? null,
            'parent_comment_id' => $parentComment?->id,
            'author_user_id'    => $this->user()->id,
            'body'              => $validated['body'],
            'visibility'        => $visibility,
            'mentions'          => $mentions->isNotEmpty() ? $mentions->all() : null,
        ]);

        if ($visibility === 'seller_reply') {
            $this->notifySellerReply($project, $comment, $this->user()->id, $parentComment);
        } else {
            $this->notifyAndLog($project, $comment, $this->user()->id);
            $this->notifyMentions($project, $comment, $mentions, $this->user()->id);
        }

        // Surface it in the task's own History feed too, not just the
        // comments panel — "who commented" per the task activity log.
        if ($comment->task_id) {
            $task = Task::find($comment->task_id);
            $task?->logActivity(
                'commented',
                "{$this->userName()} commented: " . Str::limit($comment->body, 80),
                $this->userName()
            );
        }

        $comment->load('authorUser:id,name,role_type', 'likes.user:id,name', 'likes.admin:id,name');
        $this->appendLikeFields($comment, $this->user()->id);

        return ApiResponse::success($comment, 'Comment added', 201);
    }

    // PATCH /user/projects/{projectId}/comments/{commentId} — only the
    // comment's own author can edit it (no moderator override here, unlike
    // destroy() below — editing someone else's words is a different concern
    // than removing them; same restraint Api\User\GeneralChatController's
    // updateMessage() already applies to chat).
    public function update(Request $request, int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->findOrFail($commentId);

        if ($comment->author_user_id !== $this->user()->id) {
            return ApiResponse::error('You can only edit your own comment.', 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $comment->update(['body' => $validated['body']]);

        $fresh = $comment->fresh()->load('authorUser:id,name,role_type', 'likes.user:id,name', 'likes.admin:id,name');
        $this->appendLikeFields($fresh, $this->user()->id);

        return ApiResponse::success($fresh, 'Comment updated');
    }

    // DELETE /user/projects/{projectId}/comments/{commentId} — the comment's
    // own author, OR this project's PM tier (see isProjectPmTier()) can
    // delete any comment on it (moderation). Hard delete: no soft-delete
    // column exists on project_comments, matching how Project/Task
    // Attachments are already deleted elsewhere in this codebase. Any
    // attachments on the comment are removed from storage first — the DB
    // rows cascade automatically via the FK's cascadeOnDelete().
    public function destroy(int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->with('attachments')->findOrFail($commentId);

        $user = $this->user();
        $isOwnComment = $comment->author_user_id === $user->id;
        $isModerator = $this->isProjectPmTier($project);

        if (!$isOwnComment && !$isModerator) {
            return ApiResponse::error('You can only delete your own comment.', 403);
        }

        foreach ($comment->attachments as $attachment) {
            AttachmentStorageService::delete($attachment->storage_type, $attachment->file_path, $attachment->google_drive_file_id, $attachment->company_id);
        }

        $comment->delete();

        return ApiResponse::success(null, 'Comment deleted');
    }

    // POST /user/projects/{projectId}/comments/{commentId}/like — toggles this
    // user's own like on/off. Gated behind the exact same row-level visibility
    // a comment already has (mirrors ProjectCommentAttachmentController::
    // visibleComment()) — liking something you can't otherwise see would leak
    // its existence, which would undo the whole point of the Seller-visibility
    // rules built earlier.
    public function toggleLike(int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->findOrFail($commentId);

        $userId = $this->user()->id;
        $isInternal = $this->isInternalStaff();
        $isProjectPm = $this->isProjectPmTier($project);
        $isTagged = in_array($userId, $comment->mentions ?? [], true);

        if (!$isInternal) {
            $visible = $comment->visibility === 'client'
                || $isTagged
                || ($comment->visibility === 'seller_reply' && $comment->author_user_id === $userId);
            if (!$visible) abort(404);
        } elseif (!$isProjectPm) {
            if (in_array($comment->visibility, ['client', 'seller_reply'], true)) abort(404);
            if ($comment->visibility === 'internal' && !empty($comment->mentions)) {
                $taggedSeller = User::whereIn('id', $comment->mentions)->where('role_type', 'seller')->exists();
                if ($taggedSeller) abort(404);
            }
        }

        $existing = ProjectCommentLike::where('comment_id', $comment->id)->where('user_id', $userId)->first();
        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            ProjectCommentLike::create(['company_id' => $project->company_id, 'comment_id' => $comment->id, 'user_id' => $userId]);
            $liked = true;
            $this->notifyCommentLiked($project, $comment, $userId);
        }

        return ApiResponse::success([
            'liked'       => $liked,
            'likes_count' => ProjectCommentLike::where('comment_id', $comment->id)->count(),
        ]);
    }

    // Notifies the comment's author that their comment got a like — never on
    // unlike, never a self-notify. A User author gets a real Notification
    // row (bell); an Admin-authored comment has no `users` row to notify, so
    // a SystemAuditLog entry surfaces it on the Admin's own bell instead —
    // same dual-write pattern used everywhere else in this file. No
    // visibility check needed here beyond what toggleLike() already
    // enforced — liking requires seeing the comment in the first place.
    private function notifyCommentLiked(Project $project, ProjectComment $comment, int $likerId): void
    {
        $likerName = $this->user()->name ?? 'Someone';

        if ($comment->author_user_id && $comment->author_user_id !== $likerId) {
            Notification::create([
                'user_id'    => $comment->author_user_id,
                'company_id' => $project->company_id,
                'type'       => 'comment_liked',
                'title'      => "Your comment got a like",
                'body'       => "{$likerName} liked your comment on {$project->name}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        } elseif ($comment->author_admin_id) {
            SystemAuditLog::create([
                'company_id'  => $project->company_id,
                'user_id'     => $likerId,
                'action'      => 'comment_liked',
                'module_key'  => 'project_management',
                'entity_type' => 'Project',
                'entity_id'   => $project->id,
                'new_values'  => [
                    'comment_id' => $comment->id,
                    'preview'    => Str::limit($comment->body, 120),
                    'project'    => $project->name,
                    'liked_by'   => $likerName,
                ],
            ]);
        }
    }

    private function notifyMentions(Project $project, ProjectComment $comment, $mentionedIds, int $actorId): void
    {
        $authorName = $comment->authorAdmin?->name ?? $comment->authorUser?->name ?? 'Someone';

        foreach ($mentionedIds as $uid) {
            if ($uid === $actorId) continue; // never notify the author of their own mention

            // Company Admin sentinel — recorded in `mentions` for history/
            // display, but no Notification row is possible (Admin isn't a
            // `users` row); Admin already sees this comment via the existing
            // SystemAuditLog-fed bell regardless of being tagged.
            if ($uid === self::ADMIN_MENTION_ID) continue;

            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'mentioned_in_comment',
                'title'      => "You were mentioned on {$project->name}",
                'body'       => "{$authorName}: " . Str::limit($comment->body, 120),
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }
    }

    // Mirrors Api\Admin\ProjectCommentController::notifyAndLog(). "Notify
    // Company Admin" needs no Notification row (Admin isn't a `users` row) —
    // the SystemAuditLog write below already surfaces in the Admin's bell,
    // since Admin\NotificationController reads all company SystemAuditLog
    // rows unfiltered by action.
    private function notifyAndLog(Project $project, ProjectComment $comment, int $actorUserId): void
    {
        $task = $comment->task_id ? Task::with('deliverables')->find($comment->task_id) : null;
        $pmId = $project->project_manager_id;
        $assigneeId = $task?->assigned_to;
        $productionUserId = $task?->production_assigned_to;
        $authorName = $comment->authorAdmin?->name ?? $comment->authorUser?->name ?? 'Someone';

        // Client-facing comments never route through the internal
        // PM/assignee/production notify logic below (that's for internal
        // notes only) — instead notify PM + linked Seller + the client
        // themselves, per the Client Communication Rules.
        if ($comment->visibility === 'client') {
            $this->notifyClientFacingComment($project, $comment, $actorUserId, $authorName);
            return;
        }

        if ($actorUserId === $pmId) {
            // PM comments → notify the assigned/production user.
            $targets = [$assigneeId, $productionUserId];
        } elseif ($actorUserId === $productionUserId) {
            // Production User comments → notify the PM.
            $targets = [$pmId];
        } else {
            // Assigned task user or plain team member → notify the PM.
            $targets = [$pmId];
        }

        $recipients = collect($targets)->filter()->unique()->reject(fn ($uid) => $uid === $actorUserId);

        // No PM/assignee/production user to target (e.g. unassigned project,
        // task-less comment) → fall back to the whole project team so the
        // comment doesn't go unnoticed by everyone.
        if ($recipients->isEmpty()) {
            $recipients = $project->teamMembers()->pluck('user_id')->unique()->reject(fn ($uid) => $uid === $actorUserId);
        }

        // This is an internal-visibility comment's notification — a Seller
        // must never be a recipient here, even incidentally (e.g. they're
        // recorded as project_manager_id via a handoff, or ended up in the
        // team-members fallback above). The notification body carries a
        // preview of the comment text, so this is the exact same "Seller
        // never sees internal team notes" rule index() already enforces on
        // the comment feed itself — just applied to this separate
        // notification code path.
        $recipients = $this->excludeSellers($recipients);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => $task ? 'task_comment' : 'project_comment',
                'title'      => "New comment on {$project->name}" . ($task ? " · {$task->title}" : ''),
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        $action = !$task ? 'project_comment_added'
            : ($task->production_assigned_to || $task->task_type === 'production' ? 'production_comment_added'
                : ($task->deliverables->isNotEmpty() ? 'deliverable_comment_added' : 'task_comment_added'));

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => $action,
            'module_key'  => 'project_management',
            'entity_type' => $comment->task_id ? 'Task' : 'Project',
            'entity_id'   => $comment->task_id ?? $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'task'       => $task?->title,
                'author'     => $authorName,
            ],
        ]);
    }

    // Walks a seller_reply thread up from the given comment to find the one
    // Seller it actually belongs to — the immediate parent's author might be
    // the PM/Admin continuing the conversation rather than the Seller
    // themselves (comments authored by Admin have no author_user_id at
    // all), so this keeps looking until it finds a seller_reply row (or the
    // root internal tag) that identifies the real participant.
    private function findSellerReplyThreadOwner(?ProjectComment $node): ?int
    {
        for ($depth = 0; $node && $depth < 50; $depth++) {
            if ($node->author_user_id && User::where('id', $node->author_user_id)->where('role_type', 'seller')->exists()) {
                return $node->author_user_id;
            }
            if ($node->visibility === 'internal' && !empty($node->mentions)) {
                $taggedSellerId = User::whereIn('id', $node->mentions)->where('role_type', 'seller')->value('id');
                if ($taggedSellerId) return $taggedSellerId;
            }
            $node = $node->parent_comment_id ? ProjectComment::find($node->parent_comment_id) : null;
        }
        return null;
    }

    // Notifies whichever side of the private thread isn't the actor —
    // either the seller's own follow-up (notify the PM) or the PM/Admin
    // continuing the thread from their side (notify the seller who actually
    // owns it, found via findSellerReplyThreadOwner() rather than trusting
    // the immediate parent's author — a PM- or Admin-authored parent
    // wouldn't identify the seller at all). Company Admin needs no
    // Notification row — the SystemAuditLog write below already surfaces on
    // their bell.
    private function notifySellerReply(Project $project, ProjectComment $comment, int $actorUserId, ?ProjectComment $parentComment = null): void
    {
        $authorName = $comment->authorUser?->name ?? 'Someone';
        $sellerId = $this->findSellerReplyThreadOwner($comment);

        // project_manager_id can itself be a Seller (a project handed off to
        // them — see the Manager-label fix earlier this session) who is NOT
        // the seller actually party to THIS thread. Notifying them anyway
        // would leak a different Seller's private conversation, so this
        // "PM" target only counts if they aren't a seller, or if they are,
        // they're the same seller this thread already belongs to.
        $pmId = $project->project_manager_id;
        if ($pmId && User::where('id', $pmId)->where('role_type', 'seller')->exists() && $pmId !== $sellerId) {
            $pmId = null;
        }

        $recipients = collect([$pmId, $sellerId])
            ->filter()
            ->unique()
            ->reject(fn ($uid) => $uid === $actorUserId);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'seller_reply',
                'title'      => "New private reply on {$project->name}",
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => 'seller_reply_added',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'author'     => $authorName,
            ],
        ]);
    }

    // Client Communication Rules — a client-facing comment notifies the PM
    // and any linked Seller (never the wider internal/production team), plus
    // the client themselves when the reply comes from staff. Company Admin
    // needs no Notification row — the SystemAuditLog write below already
    // surfaces on their bell, same as every other comment.
    private function notifyClientFacingComment(Project $project, ProjectComment $comment, int $actorUserId, string $authorName): void
    {
        $recipients = collect($this->mentionCandidates($project, 'client'))
            ->pluck('user_id')
            ->push($project->project_manager_id)
            ->filter(fn ($id) => $id && $id !== self::ADMIN_MENTION_ID)
            ->unique()
            ->reject(fn ($uid) => $uid === $actorUserId);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'client_facing_comment',
                'title'      => "New client-facing comment on {$project->name}",
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        // The client themselves — only relevant when the comment came from
        // staff (a client author is never notified of their own comment).
        if ($project->client_id) {
            $client = Client::find($project->client_id);
            if ($client?->user_id && $client->user_id !== $actorUserId) {
                Notification::create([
                    'user_id'    => $client->user_id,
                    'company_id' => $project->company_id,
                    'type'       => 'client_facing_comment_reply',
                    'title'      => "New reply on {$project->name}",
                    'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                    'data'       => [
                        'project_id' => $project->id,
                        'task_id'    => $comment->task_id,
                        'comment_id' => $comment->id,
                        'link'       => "/client/projects/{$project->id}",
                    ],
                ]);
            }
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => 'client_facing_comment_added',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'author'     => $authorName,
            ],
        ]);
    }
}
