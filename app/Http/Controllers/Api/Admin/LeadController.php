<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Client;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Services\EventMailService;
use App\Services\LeadChatInviteService;
use App\Services\LeadProjectTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()   { return auth('admin')->user(); }
    private function adminName(): string { return $this->admin()->name ?? 'Admin'; }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Notifies the assigned staff Seller (in-app) — Company Admin already
    // knows about actions it takes itself, so unlike the User-guard
    // controller this never needs to notify Admin back.
    private function notifyUser(?int $userId, Lead $lead, string $type, string $title, string $body): void
    {
        if (!$userId) return;

        Notification::create([
            'user_id'    => $userId,
            'company_id' => $lead->company_id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
        ]);

        // "Lead Assigned" by email too — covers store(), update()'s
        // reassignment AND transfer(), since all of them route through here.
        // No actor id: a Company Admin isn't a `users` row, so they can never
        // be the assignee themselves.
        if (in_array($type, ['lead_assigned', 'lead_transferred'], true)) {
            EventMailService::leadAssigned($lead, $userId, $this->adminName());
        }
    }

    // Admin-created leads get the same "New Lead Created" fan-out the staff
    // controller already does (Api\User\LeadController::
    // notifyLeadManagersOfNewLead()) — every Lead Manager in the company,
    // who all hold canViewAllCompanyLeads oversight of the pipeline. Company
    // Admins are excluded here: the acting Admin already knows, and the
    // SystemAuditLog entry covers any co-Admin's bell.
    private function notifyLeadManagersOfNewLead(Lead $lead): void
    {
        $leadManagerIds = User::where('company_id', $lead->company_id)
            ->where('role_type', 'lead_manager')
            ->pluck('id');

        foreach ($leadManagerIds as $managerId) {
            Notification::create([
                'user_id'    => $managerId,
                'company_id' => $lead->company_id,
                'type'       => 'lead_created',
                'title'      => 'New lead created',
                'body'       => "{$this->adminName()} created a new lead \"{$lead->name}\".",
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }

        EventMailService::leadCreated(
            $lead,
            $this->adminName(),
            $leadManagerIds->map(fn ($id) => ['user_id' => $id])->all(),
        );
    }

    // Mirrors Api\User\LeadController::syncFollowUp() — the plain "Next
    // Follow-up Date/Time" fields on the shared lead create/edit form only
    // ever wrote to leads.next_followup_date/time, never to the separate
    // follow_ups table that the Follow-up queue and lead detail follow-up
    // list actually read from. created_by is left null here — Company
    // Admin isn't a `users` row, so there's no valid FK to store.
    private function syncFollowUp(Lead $lead, string $date, ?string $time): void
    {
        FollowUp::create([
            'lead_id'     => $lead->id,
            'company_id'  => $lead->company_id,
            'assigned_to' => $lead->assigned_to,
            'type'        => 'other',
            'scheduled_at'=> $date . ' ' . ($time ?: '09:00') . ':00',
            'status'      => 'pending',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $q = Lead::whereIn('company_id', $companyIds)
            ->with(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name'])
            ->orderByDesc('created_at');

        // Company-Wise Dashboard Filtering — defaults to the active company
        // (matches ProjectController/ClientController::index()'s identical
        // default), narrowed further by an explicit ?company_id= override.
        //
        // ?company_id=all is the Leads page's own "All Companies" option and
        // means every company this Admin owns — i.e. exactly the whereIn()
        // above, with no further narrowing. Without this case the page's "All
        // Companies" fell through to the else-branch and stayed silently
        // scoped to whichever single company the topbar switcher was on, so a
        // lead moved to another company looked like it had vanished.
        if ($request->input('company_id') !== 'all') {
            if ($request->filled('company_id')) {
                $cid = (int) $request->company_id;
                if (in_array($cid, $companyIds, true)) {
                    $q->where('company_id', $cid);
                } else {
                    // A company this Admin doesn't own must return NOTHING,
                    // never fall back to the whereIn() above — same guard as
                    // Api\Admin\ClientController::index().
                    $q->whereRaw('1 = 0');
                }
            } else {
                $q->whereIn('company_id', $this->activeCompanyIds());
            }
        }
        // Converted leads stay listed here (they are shown with a "Client"
        // status in the UI rather than hidden). Their stored status remains
        // 'won' — leads.status has no 'client' value, and Sales Dashboard
        // won-counts/revenue, LeadDealService and the pipeline all key off
        // 'won', so "Client" is a display label only, never a stored one.
        // ?status=converted narrows to just them; converted_at is the
        // reliable signal, set by both conversion paths (on payment via
        // LeadDealService::markWonFromPayment(), and by hand via convert()).
        if ($request->input('status') === 'converted') {
            $q->whereNotNull('converted_at');
        } elseif ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('priority'))    $q->where('priority',    $request->priority);
        if ($request->filled('source'))      $q->where('source',      $request->source);
        if ($request->filled('assigned_to')) $q->where('assigned_to', $request->assigned_to);
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            // company_name is the PROSPECT's own organisation; the tenant
            // Company the lead sits under is the `company` relation (the 🏢
            // column on the Leads listing). Searching one of them and getting
            // nothing back is indistinguishable from "no such lead", so the
            // box covers both — matched by name against the joined companies
            // row, since the listing shows a name, never the id.
            //
            // Only when this Admin owns more than one company, mirroring the
            // frontend's isMultiCompanyAdmin rule (frontend/app/leads/
            // page.tsx): with a single company the 🏢 column is hidden and
            // every lead would match its own company's name, which would turn
            // a typo into "all leads" instead of "no leads".
            $searchesTenantCompany = count($companyIds) > 1;
            $q->where(function ($x) use ($s, $searchesTenantCompany) {
                $x->where('name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('company_name', 'like', $s)
                    ->orWhere('phone', 'like', $s);
                if ($searchesTenantCompany) {
                    $x->orWhereHas('company', fn($c) => $c->where('name', 'like', $s));
                }
            });
        }

        return ApiResponse::success(['leads' => $q->get()->map(fn($l) => $this->format($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id'         => ['required', 'integer', 'in:' . implode(',', $companyIds)],
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
            'assigned_to'        => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Same company-scoped duplicate guard as Api\Admin\ClientController::
        // store() — a Lead with this email already exists for this company.
        if (!empty($validated['email']) && Lead::where('company_id', $validated['company_id'])->where('email', $validated['email'])->exists()) {
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

        $validated['status']   ??= 'new';
        $validated['priority'] ??= 'medium';

        // Records the Admin as the author. created_by stays null on purpose —
        // it FKs to `users` and a Company Admin has no row there, which is
        // exactly why "Created By" was blank for every Admin-raised lead.
        $validated['created_by_admin_id'] = $this->admin()->id;

        $lead = Lead::create($validated);
        $lead->logActivity('created', "Lead \"{$lead->name}\" created", $this->adminName());

        if (!empty($validated['next_followup_date'])) {
            $this->syncFollowUp($lead, $validated['next_followup_date'], $validated['next_followup_time'] ?? null);
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

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name']);

        return ApiResponse::success($this->format($lead), 'Lead created', 201);
    }

    // ── The Lead's own no-login Sales Chat link ──────────────────────────────
    //
    // The invite goes out automatically when the lead is created (see
    // store()); these three exist for everything after that — a bounced
    // address that has since been corrected, a lead created before this
    // feature shipped, or a link that was forwarded and needs killing.
    //
    // Company Admin reaches every lead in their own companies. The staff-side
    // equivalents (Api\User\LeadController) are additionally scoped to the
    // leads that user can actually see.

    // GET /admin/leads/{id}/chat-invite — the link's current state, so the
    // Lead screen can show whether an invite was ever sent and offer the URL
    // for manual sharing (a Seller pasting it into WhatsApp, say).
    public function chatInvite(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        return ApiResponse::success($this->chatInviteState($lead));
    }

    // POST /admin/leads/{id}/chat-invite — resend. `regenerate` kills the old
    // link first; without it the Lead keeps whatever link they already have,
    // so a plain resend never leaves two links in circulation with one dead.
    public function sendChatInvite(Request $request, int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())
            ->with('assignedTo:id,name')
            ->findOrFail($id);

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

    // DELETE /admin/leads/{id}/chat-invite — kills the link outright, with no
    // replacement. The conversation itself is untouched: staff keep it in the
    // Sales Chat tab, and sending a fresh invite later drops the Lead back
    // into the same thread with all its history.
    public function revokeChatInvite(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $lead->revokeChatToken();
        $lead->logActivity('note_added', 'Sales Chat link revoked', $this->adminName());

        return ApiResponse::success($this->chatInviteState($lead->fresh()), 'Chat link revoked');
    }

    /**
     * GET /admin/leads/{id}/ai-project-title
     *
     * Company Admin counterpart to Api\User\LeadController::aiProjectTitle().
     * A suggested Project Title read out of this lead's Sales Chat, for the
     * Project Title field on Create Invoice — `title: null` whenever the
     * conversation does not actually say what is being built, so the screen
     * offers nothing rather than a plausible-looking guess.
     *
     * Cached per lead, keyed on the exact transcript/attachments so a
     * reload with no new chat activity never re-calls the AI; a message
     * sent a moment ago still changes the key and is reflected immediately.
     * See LeadProjectTitleService::suggest().
     */
    public function aiProjectTitle(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

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
        // client:id,lead_id,name — lead_id (the hasOne FK) must be in the
        // restricted column list, or Eloquent can't match the loaded Client
        // row back to this Lead and $lead->client silently resolves to
        // null even when a real Client row exists (format()'s 'client_id'
        // then always came back null for an already-converted lead).
        $lead = Lead::whereIn('company_id', $this->companyIds())
            ->with(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name', 'client:id,lead_id,name', 'followUps.assignedTo:id,name', 'activities'])
            ->findOrFail($id);

        $data         = $this->format($lead);
        $data['has_invoice'] = $lead->invoices()->exists();
        // Once this lead has a Project, its chat conversation moved there —
        // see App\Services\PaymentProjectStartService::migrateChatHistory()
        // — so the frontend can point the Sales Chat tab at Project Chat
        // instead of the old, now-abandoned Lead-anchored thread.
        $data['chat_project_id'] = $lead->projects()->latest()->value('id');
        $data['follow_ups']  = $lead->followUps->map(fn($f) => $this->formatFollowUp($f))->values();
        $data['activities']  = $lead->activities->map(fn($a) => [
            'id'          => $a->id,
            'type'        => $a->type,
            'description' => $a->description,
            'causer_name' => $a->causer_name,
            'meta'        => $a->meta,
            'created_at'  => $a->created_at->toIso8601String(),
        ])->values();

        return ApiResponse::success($data);
    }

    // GET /admin/leads/{id}/project-eligibility — mirrors
    // Api\User\LeadController::projectEligibility().
    public function projectEligibility(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        return ApiResponse::success(\App\Services\DealEligibilityService::summary($lead));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

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
            'assigned_to'        => ['nullable', 'integer', 'exists:users,id'],
        ]);

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

        // Same sync as store() — see syncFollowUp()'s comment. Guarded on an
        // actual change so re-saving the form without touching these fields
        // doesn't spawn a duplicate entry every time.
        $newFollowupDate = array_key_exists('next_followup_date', $validated) ? $validated['next_followup_date'] : $oldFollowupDate;
        $newFollowupTime = array_key_exists('next_followup_time', $validated) ? $validated['next_followup_time'] : $oldFollowupTime;
        if ($newFollowupDate && ($newFollowupDate !== $oldFollowupDate || $newFollowupTime !== $oldFollowupTime)) {
            $this->syncFollowUp($lead, $newFollowupDate, $newFollowupTime);
        }

        // Log meaningful changes
        $name = $this->adminName();
        if (isset($validated['status']) && $validated['status'] !== $old['status']) {
            $lead->logActivity('status_changed', "Status changed from {$old['status']} to {$validated['status']}", $name,
                ['from' => $old['status'], 'to' => $validated['status']]);
            $this->notifyUser($lead->assigned_to, $lead, 'lead_status_changed', 'Lead status changed',
                "Lead \"{$lead->name}\" status changed to \"{$validated['status']}\".");
        }
        if (isset($validated['assigned_to']) && $validated['assigned_to'] !== $old['assigned_to']) {
            $assignee = $validated['assigned_to'] ? User::find($validated['assigned_to'])?->name : 'unassigned';
            $lead->logActivity('assigned', "Lead assigned to {$assignee}", $name, ['to' => $assignee]);
            $this->notifyUser($validated['assigned_to'], $lead, 'lead_assigned', 'New lead assigned',
                "You were assigned lead \"{$lead->name}\".");
        }
        if (isset($validated['notes']) && $validated['notes'] !== $old['notes'] && $validated['notes']) {
            $lead->logActivity('note_added', 'Notes updated', $name);
        }

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name']);
        return ApiResponse::success($this->format($lead));
    }

    // PATCH /admin/leads/{id}/company — moves a lead created under the wrong
    // Company (the CompanySelector dropdown was on the wrong one at creation
    // time) to another Company this same Admin owns. Deliberately narrow:
    // company_id is denormalized onto several lead-scoped tables that never
    // get re-synced automatically (follow_ups, lead_activities, the sales
    // ChatThread), and once an Invoice/Project exists the blast radius grows
    // to payments, tasks, storage folders, team/permission scoping — far
    // beyond a "fix my mistake" action. So this only ever runs on a lead
    // that hasn't produced any of that yet; anything further along must be
    // deleted and recreated under the right Company instead.
    public function updateCompany(Request $request, int $id): JsonResponse
    {
        // Looking up the lead itself still uses every owned company — it may
        // legitimately live under one that's since been suspended. Only the
        // destination it can move TO is restricted to an operational one.
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $this->operationalCompanyIds())],
        ]);
        $newCompanyId = (int) $validated['company_id'];

        if ($newCompanyId === $lead->company_id) {
            return ApiResponse::error('This lead is already in that company.', 422);
        }

        if ($lead->invoices()->exists() || $lead->projects()->exists()) {
            return ApiResponse::error('This lead has an invoice or project and can no longer be moved to another company.', 422);
        }

        if ($lead->status === 'won') {
            return ApiResponse::error('This lead has already been won and can no longer be moved.', 422);
        }

        $oldCompanyId = $lead->company_id;
        $oldCompanyName = \App\Models\Company::find($oldCompanyId)?->name ?? "Company #{$oldCompanyId}";
        $newCompanyName = \App\Models\Company::find($newCompanyId)?->name ?? "Company #{$newCompanyId}";

        // assigned_to/transferred_to only make sense pointing at a user who
        // actually belongs to the NEW company (User::scopeOfCompany() covers
        // both a single-company user and a multi-company assignment) —
        // otherwise the lead would show as "assigned to" someone who can
        // never see it and never appears in that company's own pickers.
        // created_by is left untouched: it's a historical fact ("who made
        // this"), never a live access grant — Lead access is always gated by
        // company_id first, so a stale created_by can't leak anything.
        $clearedAssignee = false;
        \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $newCompanyId, &$clearedAssignee) {
            if ($lead->assigned_to && !User::ofCompany($newCompanyId)->whereKey($lead->assigned_to)->exists()) {
                $lead->assigned_to = null;
                $clearedAssignee = true;
            }
            if ($lead->transferred_to && !User::ofCompany($newCompanyId)->whereKey($lead->transferred_to)->exists()) {
                $lead->transferred_to = null;
            }

            $lead->company_id = $newCompanyId;
            $lead->save();

            \App\Models\FollowUp::where('lead_id', $lead->id)->update(['company_id' => $newCompanyId]);
            \App\Models\LeadActivity::where('lead_id', $lead->id)->update(['company_id' => $newCompanyId]);
            \App\Models\ChatThread::where('thread_type', 'sales')
                ->where('linked_to_type', 'Lead')
                ->where('linked_to_id', $lead->id)
                ->update(['company_id' => $newCompanyId]);
        });

        $lead->logActivity('company_changed', "Moved from \"{$oldCompanyName}\" to \"{$newCompanyName}\"" . ($clearedAssignee ? ' (unassigned — assignee is not part of the new company)' : ''), $this->adminName(),
            ['from' => $oldCompanyName, 'to' => $newCompanyName]);

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name']);
        return ApiResponse::success($this->format($lead), 'Lead moved to the new company.');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);
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
        $lead->logActivity($type, "Status changed from {$old} to {$request->status}", $this->adminName(),
            ['from' => $old, 'to' => $request->status]);

        $notifyType = $request->status === 'lost' ? 'lead_lost' : 'lead_status_changed';
        $this->notifyUser($lead->assigned_to, $lead, $notifyType,
            $request->status === 'lost' ? 'Lead lost' : 'Lead status changed',
            "Lead \"{$lead->name}\" status changed to \"{$request->status}\".");

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name']);
        return ApiResponse::success($this->format($lead));
    }

    public function destroy(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        // An invoice permanently references this lead (invoices.lead_id) and
        // is real billing/payment history — deleting the lead out from under
        // it would leave that record pointing at nothing. The lead must stay
        // until every invoice raised against it is gone too.
        if ($lead->invoices()->exists()) {
            return ApiResponse::error('This lead has an invoice raised against it and cannot be deleted.', 422);
        }

        $lead->delete();
        return ApiResponse::success(null, 'Lead deleted');
    }

    public function convert(int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($lead->client()->exists()) {
            return ApiResponse::error('Lead already converted to a client', 422);
        }

        // Same company-scoped duplicate guard as Api\User\LeadController::convert().
        if (!empty($lead->email) && Client::where('company_id', $lead->company_id)->where('email', $lead->email)->exists()) {
            return ApiResponse::error('Client with this email already exists.', 422);
        }

        $client = Client::create([
            'company_id'   => $lead->company_id,
            'name'         => $lead->name,
            'email'        => $lead->email,
            'phone'        => $lead->phone,
            'company_name' => $lead->company_name,
            'notes'        => $lead->notes,
            'status'       => 'active',
            'lead_id'      => $lead->id,
            'created_by_admin_id' => $this->admin()->id,
        ]);

        $lead->update(['status' => 'won', 'converted_at' => now()]);
        $lead->logActivity('converted', "Lead converted to client \"{$client->name}\"", $this->adminName(),
            ['client_id' => $client->id]);

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

    // POST /admin/leads/{id}/transfer — mirrors Api\User\LeadController::transfer().
    public function transfer(Request $request, int $id): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($id);

        if ($lead->status === 'won') {
            return ApiResponse::error('Won leads cannot be transferred.', 422);
        }

        $validated = $request->validate([
            'to_user_id' => ['required', 'integer'],
            'reason'     => ['nullable', 'string', 'max:1000'],
        ]);

        $fromUserId = $lead->assigned_to;
        $toUserId   = $validated['to_user_id'];

        if ($fromUserId === $toUserId) {
            return ApiResponse::error('Lead is already assigned to this user.', 422);
        }

        // A lead can be transferred to a Seller (the normal case) or a Lead
        // Manager (who owns/redistributes leads directly per
        // RoleDefaultPermissions — canTransferLeads/canAssignLeadOwner are
        // granted to that role by default) — never any other internal role.
        // Mirrors companyUsers() above exactly, so this picker can never
        // offer a choice this rejects. ofCompany() (not a raw company_id
        // match) so a Seller/Lead Manager assigned to more than one company
        // is still a valid target when this lead's company is their
        // secondary one.
        $isValidTarget = User::ofCompany($lead->company_id)
            ->where('id', $toUserId)
            ->where('is_active', true)
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->exists();
        if (!$isValidTarget) {
            return ApiResponse::error('Selected user is not an eligible transfer target at this company.', 422);
        }

        LeadTransfer::create([
            'lead_id'                   => $lead->id,
            'company_id'                => $lead->company_id,
            'from_user_id'              => $fromUserId,
            'to_user_id'                => $toUserId,
            'transferred_by_admin_name' => $this->adminName(),
            'reason'                    => $validated['reason'] ?? null,
        ]);

        $lead->update(['assigned_to' => $toUserId, 'transferred_to' => $toUserId, 'transferred_at' => now()]);

        $toName = User::find($toUserId)?->name ?? 'Unknown';
        $lead->logActivity('transferred', "Lead transferred to {$toName}" . (!empty($validated['reason']) ? " — {$validated['reason']}" : ''),
            $this->adminName(), ['from' => $fromUserId, 'to' => $toUserId, 'reason' => $validated['reason'] ?? null]);
        $this->notifyUser($toUserId, $lead, 'lead_transferred', 'Lead transferred to you',
            "Lead \"{$lead->name}\" was transferred to you" . (!empty($validated['reason']) ? " — {$validated['reason']}" : '') . '.');

        $lead->load(['assignedTo:id,name', 'createdBy:id,name', 'createdByAdmin:id,name', 'company:id,name']);
        return ApiResponse::success($this->format($lead), 'Lead transferred');
    }

    // GET /admin/leads/company-users?company_id= — picker list for the
    // Transfer Lead modal (Admin manages multiple companies, so the target
    // company must be specified). Sellers and Lead Managers only — mirrors
    // transfer()'s validation exactly.
    public function companyUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $this->operationalCompanyIds())],
        ]);

        $users = User::ofCompany($data['company_id'])
            ->where('is_active', true)
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return ApiResponse::success($users);
    }

    // Pipeline: update status via drag-and-drop
    public function pipeline(Request $request): JsonResponse
    {
        $leads = Lead::whereIn('company_id', $this->activeCompanyIds())
            ->whereNotIn('status', ['won', 'lost'])
            ->with(['assignedTo:id,name', 'company:id,name'])
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
            // The COMPANY THIS LEAD BELONGS TO (a tenant of this Admin) —
            // not to be confused with 'company_name' below, which is the
            // prospect's own organisation typed into the lead form. Sent so
            // the Leads listing can show, at a glance, which company a lead
            // sits under after it's been moved between them (see
            // updateCompany()).
            'company'            => $lead->company ? ['id' => $lead->company->id, 'name' => $lead->company->name] : null,
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
            // Falls back to the Company Admin: an Admin-raised lead has
            // created_by null (Admin is not a `users` row), which read as "—"
            // on every "Created By" surface. `type` disambiguates the id —
            // it comes from a different table in each case.
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
