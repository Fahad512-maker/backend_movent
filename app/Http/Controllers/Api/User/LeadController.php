<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Models\CompanyUserAssignment;
use App\Models\UserCompanyPermission;
use App\Rules\ValidPhoneNumber;
use App\Services\EventMailService;
use App\Services\LeadChatInviteService;
use App\Services\LeadProjectTitleService;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private function user()       { return auth('sanctum')->user(); }
    private function userName(): string { return $this->user()->name ?? 'User'; }

    private function companyId(): int
    {
        $user = $this->user();
        $requested = (int) request()->header('X-Active-Company-Id');

        if ($requested && CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $requested)
            ->where('status', 'active')
            ->exists()) {
            return $requested;
        }

        return (int) $user->company_id;
    }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $companyId = $this->companyId();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('module_key', 'sales')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $companyId, $user->role_type, 'sales', $permKey, $result);
        return $result;
    }

    // canCreateClients is granted identically whether the company purchased
    // the Client module or the Sales module ("basic client access included
    // with Sales") — mirrors Api\User\ClientController::can()'s dual-bucket
    // check so a Sales-only grant (the default for Seller) still works here.
    private function canCreateClient(): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $this->companyId())
            ->whereIn('module_key', ['client', 'sales'])
            ->where('permission_key', 'canCreateClients')
            ->exists();
    }

    // Leads this Seller can see: every company lead if canViewAllCompanyLeads
    // is granted, otherwise only leads assigned to them. Mirrors
    // Api\User\ProjectController::visibleProjects()'s pattern. A Lead
    // Manager's default permission set includes canViewAllCompanyLeads, so
    // they get full company-wide visibility through this same mechanism —
    // no role-specific branch needed.
    private function visibleLeads()
    {
        $user = $this->user();
        $base = Lead::where('company_id', $this->companyId());

        if ($this->can('canViewAllCompanyLeads')) {
            return $base;
        }

        return $base->where('assigned_to', $user->id);
    }

    // The plain "Next Follow-up Date/Time" fields on the lead create/edit
    // form only ever wrote to leads.next_followup_date/time — they never
    // created a row in the separate follow_ups table, so a follow-up set
    // this way never showed up in the Follow-up queue (left menu) or the
    // lead detail page's own follow-up list, both of which read exclusively
    // from follow_ups (see FollowUpController::queue(), show() above). This
    // creates the matching row so both views actually reflect it. Uses a
    // generic 'other' type since this quick field has no type selector of
    // its own, unlike the dedicated "Add Follow-up" modal.
    private function syncFollowUp(Lead $lead, string $date, ?string $time, int $userId): void
    {
        FollowUp::create([
            'lead_id'     => $lead->id,
            'company_id'  => $lead->company_id,
            'created_by'  => $userId,
            'assigned_to' => $lead->assigned_to,
            'type'        => 'other',
            'scheduled_at'=> $date . ' ' . ($time ?: '09:00') . ':00',
            'status'      => 'pending',
        ]);
    }

    // The only targets a lead can ever be assigned/transferred TO via
    // transfer()/companyUsers() below: an ACTIVE Seller or Lead Manager of
    // the SAME company — never cross-company, never a Developer/PM/
    // Production/HR/Finance user. A Lead Manager IS a valid target (unlike
    // the old rule here) since RoleDefaultPermissions grants that role
    // canTransferLeads/canAssignLeadOwner precisely so they can own/
    // redistribute leads directly, not just oversee them. Company Admin's
    // own LeadController is intentionally left unrestricted ("Company Admin
    // can still assign leads as before") — this check only ever applies to
    // this User guard.
    private function assignableSeller(int $userId, int $companyId): ?User
    {
        return User::where('users.id', $userId)
            ->where('is_active', true)
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->whereHas('companyAssignments', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('status', 'active'))
            ->first();
    }

    // Writes a company-wide audit-log entry (surfaces in Company Admin's
    // notification bell via Api\Admin\NotificationController, which reads
    // SystemAuditLog unfiltered by action — Admin isn't a `users` row so has
    // no Notification rows of its own).
    private function auditLog(Lead $lead, string $action, string $preview): void
    {
        SystemAuditLog::create([
            'company_id'  => $lead->company_id,
            'user_id'     => $this->user()->id,
            'action'      => $action,
            'module_key'  => 'sales',
            'entity_type' => 'Lead',
            'entity_id'   => $lead->id,
            'new_values'  => ['preview' => $preview, 'author' => $this->userName(), 'lead' => $lead->name],
        ]);
    }

    // Notifies a staff user (in-app) unless they're the one who made the
    // change themselves.
    private function notifyUser(?int $userId, Lead $lead, string $type, string $title, string $body): void
    {
        if (!$userId || $userId === $this->user()->id) return;

        Notification::create([
            'user_id'    => $userId,
            'company_id' => $lead->company_id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
        ]);

        // "Lead Assigned" by email too — covers store(), update()'s
        // reassignment AND transfer(), since every one of them routes through
        // here. Status-change/transferred-away stay in-app only.
        if (in_array($type, ['lead_assigned', 'lead_transferred'], true)) {
            EventMailService::leadAssigned($lead, $userId, $this->userName(), $this->user()->id);
        }
    }

    // A brand-new lead has no "owning" Lead Manager yet (unlike
    // FollowUpController::notifyLeadManagers(), which looks at the lead's
    // creator/last transferrer — neither exists for a lead that was JUST
    // created), so every Lead Manager in the company is notified — they
    // already have canViewAllCompanyLeads oversight of the whole pipeline,
    // this just tells them a new one landed in it. Skips the actor
    // themselves (a Lead Manager creating their own lead doesn't need to be
    // told about it).
    private function notifyLeadManagersOfNewLead(Lead $lead): void
    {
        $actorId = $this->user()->id;

        $leadManagerIds = User::where('company_id', $lead->company_id)
            ->where('role_type', 'lead_manager')
            ->where('id', '!=', $actorId)
            ->pluck('id');

        foreach ($leadManagerIds as $managerId) {
            Notification::create([
                'user_id'    => $managerId,
                'company_id' => $lead->company_id,
                'type'       => 'lead_created',
                'title'      => 'New lead created',
                'body'       => "{$this->userName()} created a new lead \"{$lead->name}\".",
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }

        // Same set by email, plus the Company Admins — they own lead intake
        // oversight but aren't `users` rows, so the loop above can't reach
        // them (their bell is fed by the SystemAuditLog entry instead).
        EventMailService::leadCreated(
            $lead,
            $this->userName(),
            array_merge(
                $leadManagerIds->map(fn ($id) => ['user_id' => $id])->all(),
                EventMailService::companyAdminRecipients($lead->company_id),
            ),
        );
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $q = $this->visibleLeads()
            ->with(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name'])
            ->orderByDesc('created_at');

        // Converted leads stay listed, shown with a "Client" status in the UI
        // — same rule (and same converted_at signal) as
        // Api\Admin\LeadController::index(); "Client" is a display label
        // only, never a stored status.
        if ($request->input('status') === 'converted') {
            $q->whereNotNull('converted_at');
        } elseif ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('priority')) $q->where('priority', $request->priority);
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $q->where(fn($x) => $x->where('name', 'like', $s)
                ->orWhere('email', 'like', $s)
                ->orWhere('company_name', 'like', $s)
                ->orWhere('phone', 'like', $s));
        }

        return ApiResponse::success(['leads' => $q->get()->map(fn($l) => $this->format($l))]);
    }

    // ── The Lead's own no-login Sales Chat link ──────────────────────────────
    //
    // Staff-side mirror of Api\Admin\LeadController's three chat-invite
    // endpoints. Two gates, not one: visibleLeads() restricts WHICH leads this
    // user can touch at all (their own, unless they hold
    // canViewAllCompanyLeads), and the permission check below restricts
    // whether they may act on it.
    //
    // Reading the link state needs only canViewLeads — a Seller has to be able
    // to see and copy the link for a lead they are already working. Sending
    // and revoking need canEditLeads: both change something the Lead receives,
    // and revoking can lock them out of a live conversation.

    // GET /user/leads/{id}/chat-invite
    public function chatInvite(int $id): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);

        return ApiResponse::success($this->chatInviteState($lead));
    }

    // POST /user/leads/{id}/chat-invite — `regenerate` kills the old link
    // first; a plain resend keeps the existing one so the Lead never ends up
    // holding two links with one of them dead.
    public function sendChatInvite(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->with('assignedTo:id,name')->findOrFail($id);

        if (!LeadChatInviteService::isSendable($lead)) {
            return ApiResponse::error('This lead has no email address to send the invite to.', 422);
        }

        $regenerate = $request->boolean('regenerate');

        if (!LeadChatInviteService::send($lead, $regenerate)) {
            return ApiResponse::error('Could not send the invite email. Check the mail settings and try again.', 500);
        }

        return ApiResponse::success(
            $this->chatInviteState($lead->fresh()),
            $regenerate ? 'Previous link revoked and a new invite sent' : 'Invite sent'
        );
    }

    // DELETE /user/leads/{id}/chat-invite — kills the link with no
    // replacement. The conversation itself survives: it stays in the Sales
    // Chat tab, and a later invite drops the Lead back into the same thread.
    public function revokeChatInvite(int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);

        $lead->revokeChatToken();
        $lead->logActivity('note_added', 'Sales Chat link revoked', $this->userName());

        return ApiResponse::success($this->chatInviteState($lead->fresh()), 'Chat link revoked');
    }

    /**
     * GET /user/leads/{id}/ai-project-title
     *
     * A suggested Project Title read out of this lead's Sales Chat, for the
     * Project Title field on Create Invoice. Returns `title: null` whenever
     * the conversation does not actually say what is being built — the screen
     * then offers nothing rather than a plausible-looking guess.
     *
     * Gated on canViewLeads plus visibleLeads(), the same reach the Sales Chat
     * itself has: the response is derived from that conversation, so anyone
     * who may read it may read a summary of it.
     *
     * Cached per lead, keyed on the exact transcript/attachments so a
     * reload with no new chat activity never re-calls the AI; a message
     * sent a moment ago still changes the key and is reflected immediately.
     * See LeadProjectTitleService::suggest().
     */
    public function aiProjectTitle(int $id): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);

        return ApiResponse::success(['title' => LeadProjectTitleService::suggest($lead)]);
    }

    private function chatInviteState(Lead $lead): array
    {
        return [
            'has_link'   => (bool) $lead->chat_token,
            'chat_url'   => $lead->chatUrl(),
            'invited_at' => $lead->chat_invited_at?->toIso8601String(),
            'can_send'   => LeadChatInviteService::isSendable($lead),
        ];
    }

    public function show(int $id): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        // client:id,lead_id,name — lead_id (the hasOne FK) must be in the
        // restricted column list, or Eloquent can't match the loaded Client
        // row back to this Lead and $lead->client silently resolves to
        // null even when a real Client row exists (format()'s 'client_id'
        // then always came back null for an already-converted lead).
        $lead = $this->visibleLeads()
            ->with(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'client:id,lead_id,name', 'followUps.assignedTo:id,name', 'activities'])
            ->findOrFail($id);

        $data               = $this->format($lead);
        $data['has_invoice'] = $lead->invoices()->exists();
        // Once this lead has a Project, its chat conversation moved there —
        // see App\Services\PaymentProjectStartService::migrateChatHistory()
        // — so the frontend can point the Sales Chat tab at Project Chat
        // instead of the old, now-abandoned Lead-anchored thread.
        $data['chat_project_id'] = $lead->projects()->latest()->value('id');
        $data['follow_ups'] = $lead->followUps->map(fn($f) => $this->formatFollowUp($f))->values();
        $data['activities'] = $lead->activities->map(fn($a) => [
            'id'          => $a->id,
            'type'        => $a->type,
            'description' => $a->description,
            'causer_name' => $a->causer_name,
            'meta'        => $a->meta,
            'created_at'  => $a->created_at->toIso8601String(),
        ])->values();

        return ApiResponse::success($data);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can('canCreateLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $companyId = $this->companyId();

        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:150'],
            'email'              => ['required', 'email', 'max:255'],
            'phone'              => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'company_name'       => ['nullable', 'string', 'max:200'],
            'source'             => ['nullable', 'in:website,referral,cold_call,social,event,other'],
            'status'             => ['nullable', 'in:new,contacted,qualified,proposal,negotiation,won,lost'],
            'priority'           => ['nullable', 'in:low,medium,high,urgent'],
            'estimated_value'    => ['nullable', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string'],
            'next_followup_date' => ['nullable', 'date'],
            'next_followup_time' => ['nullable', 'date_format:H:i'],
            // Company-scoped (not a bare exists:users,id) — assigned_to is
            // otherwise a second, unguarded path to the exact cross-company
            // assignment transfer()/companyUsers() above are specifically
            // hardened against.
            'assigned_to'        => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('role_type', 'seller')],
        ]);

        if (!empty($validated['assigned_to']) && !$this->assignableSeller((int) $validated['assigned_to'], $companyId)) {
            return ApiResponse::error('Selected seller does not belong to this company.', 422);
        }

        // Same company-scoped duplicate guard as Api\User\ClientController::
        // store() — a Lead with this email already exists for this company.
        if (!empty($validated['email']) && Lead::where('company_id', $companyId)->where('email', $validated['email'])->exists()) {
            return ApiResponse::error('A lead with this email already exists.', 422);
        }

        // A brand-new lead can never be created Won directly — Won only ever
        // comes from updateStatus() (which stamps deal_reference/won_at/
        // fulfillment_status) or LeadDealService::markWonFromPayment(). A
        // plain Lead::create() with status=won would skip all of that and
        // leave a "Deal" with no deal_reference.
        if (($validated['status'] ?? 'new') === 'won') {
            return ApiResponse::error('A lead cannot be created as Won directly — mark it Won from the pipeline, or let it become Won automatically once its invoice is paid in full.', 422);
        }

        $validated['company_id'] = $companyId;
        $validated['created_by'] = $user->id;
        $validated['assigned_to'] ??= $user->id;
        $validated['status']   ??= 'new';
        $validated['priority'] ??= 'medium';

        $lead = Lead::create($validated);
        $lead->logActivity('created', "Lead \"{$lead->name}\" created", $this->userName());
        $this->auditLog($lead, 'lead_created', "Lead \"{$lead->name}\" created");

        if (!empty($validated['next_followup_date'])) {
            $this->syncFollowUp($lead, $validated['next_followup_date'], $validated['next_followup_time'] ?? null, $user->id);
        }

        if ($lead->assigned_to) {
            $this->notifyUser($lead->assigned_to, $lead, 'lead_assigned', 'New lead assigned',
                "You were assigned lead \"{$lead->name}\".");
        }

        $this->notifyLeadManagersOfNewLead($lead);

        // Emails the Lead THEMSELVES their no-login Sales Chat link — the one
        // outward-facing message in this method; everything above notifies
        // internal staff. Never throws: see LeadChatInviteService::send().
        // Loads assignedTo first so the email can name the Seller the Lead
        // will actually be talking to.
        $lead->load('assignedTo:id,name');
        LeadChatInviteService::send($lead);

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name']);

        return ApiResponse::success($this->format($lead), 'Lead created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $lead = $this->visibleLeads()->findOrFail($id);

        // A Won lead is a closed deal — nothing about it (name, notes,
        // follow-up date, etc.) is editable from this form anymore, not just
        // status. Requested explicitly; previously only the status field
        // itself was locked once Won.
        if ($lead->status === 'won') {
            return ApiResponse::error('This lead has already been won and can no longer be edited.', 422);
        }

        $old  = $lead->only(['status', 'priority', 'assigned_to', 'notes']);
        $oldFollowupDate = $lead->next_followup_date?->toDateString();
        $oldFollowupTime = $lead->next_followup_time ? substr($lead->next_followup_time, 0, 5) : null;

        $validated = $request->validate([
            'name'               => ['sometimes', 'string', 'max:150'],
            'email'              => ['nullable', 'email', 'max:255'],
            'phone'              => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'company_name'       => ['nullable', 'string', 'max:200'],
            'source'             => ['nullable', 'in:website,referral,cold_call,social,event,other'],
            'status'             => ['nullable', 'in:new,contacted,qualified,proposal,negotiation,won,lost'],
            'priority'           => ['nullable', 'in:low,medium,high,urgent'],
            'estimated_value'    => ['nullable', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string'],
            'next_followup_date' => ['nullable', 'date'],
            'next_followup_time' => ['nullable', 'date_format:H:i'],
            'lost_reason'        => ['nullable', 'string'],
            // Company-scoped (not a bare exists:users,id) — assigned_to is
            // otherwise a second, unguarded path to the exact cross-company
            // assignment transfer()/companyUsers() above are specifically
            // hardened against.
            'assigned_to'        => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('role_type', 'seller')],
        ]);

        if (!empty($validated['assigned_to']) && !$this->assignableSeller((int) $validated['assigned_to'], $lead->company_id)) {
            return ApiResponse::error('Selected seller does not belong to this company.', 422);
        }

        // Same company-scoped duplicate guard as store() — editing a lead's
        // email must not collide with a different lead's.
        if (!empty($validated['email']) && $validated['email'] !== $lead->email
            && Lead::where('company_id', $lead->company_id)->where('email', $validated['email'])->where('id', '!=', $lead->id)->exists()) {
            return ApiResponse::error('A lead with this email already exists.', 422);
        }

        // Same one-way Won lock as updateStatus() — the plain Edit form must
        // not be a backdoor around it.
        $earlierStages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation'];
        if ($old['status'] === 'won' && isset($validated['status']) && in_array($validated['status'], $earlierStages, true)) {
            return ApiResponse::error('This lead has already been won and cannot be moved back to an earlier stage.', 422);
        }

        // Becoming Won only ever happens through updateStatus() (stamps
        // deal_reference/won_at/fulfillment_status) or
        // LeadDealService::markWonFromPayment() — never through this plain
        // Edit form, which would skip all of that. Re-saving status=won on
        // an already-won lead is still a harmless no-op.
        if (isset($validated['status']) && $validated['status'] === 'won' && $old['status'] !== 'won') {
            return ApiResponse::error('A lead cannot be marked Won from this form — use the pipeline action, or let it become Won automatically once its invoice is paid in full.', 422);
        }

        // Same invoice lock as updateStatus() — once a lead has an invoice,
        // status is driven only by LeadDealService::markWonFromPayment() on
        // payment, never by hand through this form.
        if (isset($validated['status']) && $validated['status'] !== $old['status'] && $lead->invoices()->exists()) {
            return ApiResponse::error('This lead has an invoice — its status now changes automatically once the invoice is paid in full.', 422);
        }

        $lead->update($validated);

        // Same sync as store() — keep the follow_ups table reflecting
        // whatever the plain Next Follow-up Date/Time fields were changed
        // to, so the Follow-up queue and lead detail follow-up list actually
        // show it. Guarded on an actual change so re-saving the form without
        // touching these fields doesn't spawn a duplicate entry every time.
        $newFollowupDate = array_key_exists('next_followup_date', $validated) ? $validated['next_followup_date'] : $oldFollowupDate;
        $newFollowupTime = array_key_exists('next_followup_time', $validated) ? $validated['next_followup_time'] : $oldFollowupTime;
        if ($newFollowupDate && ($newFollowupDate !== $oldFollowupDate || $newFollowupTime !== $oldFollowupTime)) {
            $this->syncFollowUp($lead, $newFollowupDate, $newFollowupTime, $user->id);
        }

        $name = $this->userName();
        if (isset($validated['status']) && $validated['status'] !== $old['status']) {
            $lead->logActivity('status_changed', "Status changed from {$old['status']} to {$validated['status']}", $name,
                ['from' => $old['status'], 'to' => $validated['status']]);
            $this->auditLog($lead, 'lead_status_changed', "Status changed from {$old['status']} to {$validated['status']}");
            $this->notifyUser($lead->assigned_to, $lead, 'lead_status_changed', 'Lead status changed',
                "Lead \"{$lead->name}\" status changed to \"{$validated['status']}\".");
        }
        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $old['assigned_to']) {
            $assignee = $validated['assigned_to'] ? User::find($validated['assigned_to'])?->name : 'unassigned';
            $lead->logActivity('assigned', "Lead assigned to {$assignee}", $name, ['to' => $assignee]);
            $this->auditLog($lead, 'lead_assigned', "Lead \"{$lead->name}\" assigned to {$assignee}");
            $this->notifyUser($validated['assigned_to'], $lead, 'lead_assigned', 'New lead assigned',
                "You were assigned lead \"{$lead->name}\".");
        }
        if (isset($validated['notes']) && $validated['notes'] !== $old['notes'] && $validated['notes']) {
            $lead->logActivity('note_added', 'Notes updated', $name);
        }

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name']);
        return ApiResponse::success($this->format($lead));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManagePipeline')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);
        $old  = $lead->status;

        // Once Won, a deal can never be walked back to an earlier pipeline
        // stage — mirrors the existing one-way Lost lock (which requires the
        // explicit "Reopen" action to leave, never a plain status edit).
        $earlierStages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation'];
        if ($old === 'won' && in_array($request->input('status'), $earlierStages, true)) {
            return ApiResponse::error('This lead has already been won and cannot be moved back to an earlier stage.', 422);
        }

        // Won is reachable ONLY via LeadDealService::markWonFromPayment() —
        // an invoice on this lead paid in full — never manually through the
        // pipeline, same as store()/update() already enforce. Re-saving
        // status=won on an already-won lead is still a harmless no-op.
        if ($request->input('status') === 'won' && $old !== 'won') {
            return ApiResponse::error('A lead cannot be marked Won manually - it becomes Won automatically once its invoice is paid in full.', 422);
        }

        // Once a lead has an invoice, its pipeline is driven only by
        // LeadDealService::markWonFromPayment() on payment — no more manual
        // clicks through this endpoint (Lost, Reopen, or otherwise).
        if ($request->input('status') !== $old && $lead->invoices()->exists()) {
            return ApiResponse::error('This lead has an invoice — its status now changes automatically once the invoice is paid in full.', 422);
        }

        $request->validate([
            'status'      => ['required', 'in:new,contacted,qualified,proposal,negotiation,won,lost'],
            'lost_reason' => ['nullable', 'string'],
        ]);

        $data = ['status' => $request->status];
        if ($request->status === 'lost' && $request->filled('lost_reason')) {
            $data['lost_reason'] = $request->lost_reason;
        }
        $lead->update($data);

        $type = $request->status === 'lost' ? 'lost' : 'status_changed';
        $lead->logActivity($type, "Status changed from {$old} to {$request->status}", $this->userName(),
            ['from' => $old, 'to' => $request->status]);

        $auditAction = $request->status === 'lost' ? 'lead_lost' : 'lead_status_changed';
        $this->auditLog($lead, $auditAction, "Lead \"{$lead->name}\" status changed from {$old} to {$request->status}");
        $this->notifyUser($lead->assigned_to, $lead, $auditAction,
            $request->status === 'lost' ? 'Lead lost' : 'Lead status changed',
            "Lead \"{$lead->name}\" status changed to \"{$request->status}\".");

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name']);
        return ApiResponse::success($this->format($lead));
    }

    // POST /user/leads/{id}/convert — mirrors Api\Admin\LeadController::convert().
    // Was previously admin-only (no User-guard route existed at all), leaving
    // Sellers with no way to turn a won lead into a Client despite already
    // holding canCreateClients by default.
    public function convert(int $id): JsonResponse
    {
        if (!$this->can('canManagePipeline') || !$this->canCreateClient()) {
            return ApiResponse::error('You do not have permission to convert this lead.', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);

        if ($lead->client()->exists()) {
            return ApiResponse::error('Lead already converted to a client', 422);
        }

        // Same company-scoped duplicate guard as Api\User\ClientController::
        // store()/update() — converting a lead must not silently create a
        // second Client row for an email that already exists in this company.
        if (!empty($lead->email) && Client::where('company_id', $lead->company_id)->where('email', $lead->email)->exists()) {
            return ApiResponse::error('Client with this email already exists.', 422);
        }

        $client = Client::create([
            'company_id'      => $lead->company_id,
            'name'            => $lead->name,
            'email'           => $lead->email,
            'phone'           => $lead->phone,
            'company_name'    => $lead->company_name,
            'notes'           => $lead->notes,
            'status'          => 'active',
            'lead_id'         => $lead->id,
            // The converting Seller becomes this client's account manager —
            // without this, Api\User\ClientController::visibleClients()'s
            // ownership scope would still cover them via the lead_id link,
            // but this makes the ownership explicit and survives the lead
            // later being reassigned away from them.
            'account_manager' => $this->user()->id,
            'created_by'      => $this->user()->id,
        ]);

        $lead->update(['status' => 'won', 'converted_at' => now()]);
        $lead->logActivity('converted', "Lead converted to client \"{$client->name}\"", $this->userName(),
            ['client_id' => $client->id]);
        $this->auditLog($lead, 'lead_converted', "Lead \"{$lead->name}\" converted to client \"{$client->name}\"");

        // Any project auto-created from this lead's paid invoice while it was
        // still a lead (PaymentProjectStartService::createDraftProject()) was
        // stamped with lead_id only — client_id was null since no Client
        // existed yet. Without this, the Client Portal's project list
        // (Api\Client\ProjectController, filtered strictly on client_id)
        // would never surface that project, even once activated and even
        // after portal access is granted.
        Project::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);

        // Same reasoning as the Project backfill above, for an invoice paid
        // while this was still a lead (created via /invoices/new?lead_id=...,
        // client_id null since no Client existed yet) — otherwise the Client
        // Portal's invoice list (Api\Client\InvoiceController, filtered
        // strictly on client_id) would never surface it, even after the
        // lead converts and portal access is granted.
        Invoice::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);

        return ApiResponse::success(['client_id' => $client->id], 'Lead converted to client', 201);
    }

    // POST /user/leads/{id}/transfer — reassigns lead ownership, keeping a
    // full audit trail (old owner, new owner, reason, who did it, when) in
    // lead_transfers, distinct from the single-row assigned_to update that
    // update() already does.
    public function transfer(Request $request, int $id): JsonResponse
    {
        $user = $this->user();
        $lead = $this->visibleLeads()->findOrFail($id);
        $fromUserId = $lead->assigned_to;

        if ($lead->status === 'won') {
            return ApiResponse::error('Won leads cannot be transferred.', 422);
        }

        // "Assign Lead Owner" only covers giving an UNOWNED lead its first
        // owner; moving a lead that already has an owner to someone else is
        // specifically "Transfer Leads" — holding only the former must never
        // let a user reassign a lead away from its current owner (previously
        // both permissions were treated as interchangeable here, so
        // unchecking "Transfer Leads" alone didn't actually revoke this).
        $authorized = $fromUserId === null
            ? ($this->can('canAssignLeadOwner') || $this->can('canTransferLeads'))
            : $this->can('canTransferLeads');

        if (!$authorized) {
            return ApiResponse::error('You do not have permission to assign leads.', 403);
        }

        $validated = $request->validate([
            'to_user_id' => ['required', 'integer'],
            'reason'     => ['nullable', 'string', 'max:1000'],
        ]);

        $toUserId = (int) $validated['to_user_id'];

        if (!$this->assignableSeller($toUserId, $lead->company_id)) {
            return ApiResponse::error('Selected seller does not belong to this company.', 422);
        }

        if ($fromUserId === $toUserId) {
            return ApiResponse::error('Lead is already assigned to this user.', 422);
        }

        LeadTransfer::create([
            'lead_id'                => $lead->id,
            'company_id'             => $lead->company_id,
            'from_user_id'           => $fromUserId,
            'to_user_id'             => $toUserId,
            'transferred_by_user_id' => $user->id,
            'reason'                 => $validated['reason'] ?? null,
        ]);

        $lead->update(['assigned_to' => $toUserId, 'transferred_to' => $toUserId, 'transferred_at' => now()]);

        $toName = User::find($toUserId)?->name ?? 'Unknown';
        $fromName = $fromUserId ? (User::find($fromUserId)?->name ?? 'Unknown') : null;
        // causer_name (passed separately below) already carries the actor's
        // name for the activity feed's own "{causer}: {description}"
        // rendering — matching every other logActivity() call in this file,
        // so it's deliberately not re-embedded into the description text.
        $description = $fromName
            ? "transferred from {$fromName} to {$toName}" . (!empty($validated['reason']) ? ". Reason: {$validated['reason']}" : '')
            : "assigned to {$toName}";
        $lead->logActivity('transferred', "Lead {$description}",
            $this->userName(), ['from' => $fromUserId, 'to' => $toUserId, 'reason' => $validated['reason'] ?? null]);
        $this->auditLog($lead, 'lead_transferred', "Lead \"{$lead->name}\" {$description}");
        $this->notifyUser($toUserId, $lead, 'lead_transferred', 'New lead assigned',
            "{$this->userName()} assigned lead \"{$lead->name}\" to you.");
        // The seller it was taken FROM also hears about it — silent
        // reassignment away from someone was the one gap here before.
        if ($fromUserId) {
            $this->notifyUser($fromUserId, $lead, 'lead_transferred_away', 'Lead reassigned',
                "{$this->userName()} transferred lead \"{$lead->name}\" from you to {$toName}" . (!empty($validated['reason']) ? " — {$validated['reason']}" : '') . '.');
        }

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name']);
        return ApiResponse::success($this->format($lead), 'Lead transferred');
    }

    // GET /user/leads/{id}/project-eligibility — Deal financial summary +
    // whether it currently clears the kickoff-payment bar for project
    // creation. Single source of truth: App\Services\DealEligibilityService.
    public function projectEligibility(int $id): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->visibleLeads()->findOrFail($id);

        return ApiResponse::success(\App\Services\DealEligibilityService::summary($lead));
    }

    // GET /user/leads/company-users — picker list for the Transfer Lead
    // modal. Only active Sellers and Lead Managers of this same company —
    // never a Developer/PM/Production/HR/Finance user, and never another
    // company's — matching assignableSeller()'s rule exactly so this picker
    // can never offer a choice transfer() would then reject.
    public function companyUsers(): JsonResponse
    {
        if (!$this->can('canTransferLeads') && !$this->can('canAssignLeadOwner')) {
            return ApiResponse::error('You do not have permission to assign leads.', 403);
        }

        $user = $this->user();
        $companyId = $this->companyId();
        $users = User::whereHas('companyAssignments', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('status', 'active'))
            ->where('is_active', true)
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return ApiResponse::success($users);
    }

    public function pipeline(Request $request): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $leads = $this->visibleLeads()
            ->whereNotIn('status', ['won', 'lost'])
            ->with(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name'])
            ->orderByRaw("FIELD(status,'new','contacted','qualified','proposal','negotiation')")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($l) => $this->format($l));

        return ApiResponse::success(['leads' => $leads]);
    }

    private function format(Lead $lead): array
    {
        return [
            'id'                 => $lead->id,
            'company_id'         => $lead->company_id,
            'name'               => $lead->name,
            'email'              => $lead->email,
            'phone'              => $lead->phone,
            'company_name'       => $lead->company_name,
            'source'             => $lead->source,
            'status'             => $lead->status,
            'priority'           => $lead->priority,
            'estimated_value'    => (float) $lead->estimated_value,
            'notes'              => $lead->notes,
            'next_followup_date' => $lead->next_followup_date?->toDateString(),
            'next_followup_time' => $lead->next_followup_time ? substr($lead->next_followup_time, 0, 5) : null,
            'lost_reason'        => $lead->lost_reason,
            'assigned_to'        => $lead->assigned_to,
            'assigned_user'      => $lead->assignedTo ? ['id' => $lead->assignedTo->id, 'name' => $lead->assignedTo->name] : null,
            'created_by'         => $lead->created_by,
            // Mirrors Api\Admin\LeadController's serializer — a staff user
            // viewing a lead the Company Admin created must see the Admin's
            // name, not "—". See the note there.
            'creator'            => $lead->createdBy
                ? ['id' => $lead->createdBy->id, 'name' => $lead->createdBy->name, 'type' => 'user']
                : ($lead->createdByAdmin
                    ? ['id' => $lead->createdByAdmin->id, 'name' => $lead->createdByAdmin->name, 'type' => 'admin']
                    : null),
            'converted_at'       => $lead->converted_at?->toIso8601String(),
            'client_id'          => $lead->client?->id ?? null,
            'created_at'         => $lead->created_at->toIso8601String(),
            'updated_at'         => $lead->updated_at->toIso8601String(),
            // Deal fields
            'deal_reference'             => $lead->deal_reference,
            'proposed_project_title'     => $lead->proposed_project_title,
            'service_category'           => $lead->service_category,
            'scope_summary'               => $lead->scope_summary,
            'detailed_scope'              => $lead->detailed_scope,
            'quotation_reference'         => $lead->quotation_reference,
            'required_kickoff_amount'     => $lead->required_kickoff_amount !== null ? (float) $lead->required_kickoff_amount : null,
            'required_kickoff_percentage' => $lead->required_kickoff_percentage !== null ? (float) $lead->required_kickoff_percentage : null,
            'expected_start_date'         => $lead->expected_start_date?->toDateString(),
            'expected_end_date'           => $lead->expected_end_date?->toDateString(),
            'fulfillment_status'          => $lead->fulfillment_status,
            'won_at'                      => $lead->won_at?->toIso8601String(),
        ];
    }

    private function formatFollowUp($f): array
    {
        return [
            'id'               => $f->id,
            'type'             => $f->type,
            'scheduled_at'     => $f->scheduled_at->toIso8601String(),
            'completed_at'     => $f->completed_at?->toIso8601String(),
            'notes'            => $f->notes,
            'status'           => $f->status,
            'reminder_enabled' => $f->reminder_enabled,
            'assigned_to'      => $f->assigned_to,
            'assigned_user'    => $f->assignedTo ? ['id' => $f->assignedTo->id, 'name' => $f->assignedTo->name] : null,
            'created_at'       => $f->created_at->toIso8601String(),
        ];
    }
}
