<?php

namespace App\Services;

use App\Mail\ProjectStartRequiresFullPaymentMail;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyDealSettings;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectTeamMember;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Services\LeadProjectTitleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reacts to a client's invoice payment by creating AND activating the
 * project — the "Deal workflow" half of App\Services\InvoicePaymentService,
 * kept separate so the payment service stays about money and this stays
 * about project kickoff.
 *
 * No Project row exists at all until a qualifying payment actually lands —
 * "New Project" mode on the invoice form only records project_title/
 * project_reference on the Invoice itself; nothing gets raised until here.
 * The Project is created already 'active' — folders made, owner assigned,
 * team notified, client told it's live — never an intermediate 'draft'/
 * 'unpaid' stub requiring a manual activation click. Driven entirely by the
 * tenant's Deal Workflow setting, which offers exactly two mutually
 * exclusive options (CompanyDealSettings::TRIGGERS):
 *
 * AFTER PARTIAL PAYMENT (project_creation_trigger = 'partial_payment')
 *   Any payment — part or full — creates the project immediately.
 *
 * AFTER FULL PAYMENT (project_creation_trigger = 'full_payment', the default)
 *   The same, but only once the invoice is settled in full. A part payment
 *   creates nothing yet and instead emails the client that the project
 *   begins after the balance is cleared.
 */
class PaymentProjectStartService
{
    public static function handle(Invoice $invoice): void
    {
        try {
            // A standalone invoice ("Without Project" at creation, see
            // Api\{Admin,User}\InvoiceController::store()) explicitly opts out
            // of this whole automation — it is a plain billing invoice with no
            // deal/project behind it, and paying it must only ever update the
            // invoice/payment records. Checked first, before anything else,
            // so it can't be reached by any branch below.
            if ($invoice->is_standalone) {
                return;
            }

            $settings = self::settingsFor($invoice);
            if (!$settings) {
                return;
            }

            if ($settings->startsOnPartialPayment()) {
                self::createActiveProject($invoice);
                return;
            }

            // Full-payment mode: settled invoices start the project; a shortfall
            // gets the client a nudge instead.
            if ($invoice->status === 'paid') {
                self::createActiveProject($invoice);
            } elseif ($invoice->status === 'partially_paid') {
                self::emailClientFullPaymentRequired($invoice);
            }
        } catch (\Throwable $e) {
            // Never let project kickoff break the payment it rode in on — same
            // swallow-and-log posture as InvoicePaymentService::notifyStakeholders().
            Log::warning('[project-start] invoice ' . $invoice->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Deal settings are keyed to the COMPANY that raised the invoice, the
     * same per-company scoping CompanyPaymentGateway uses — so two companies
     * under one Company Admin can legitimately run different project-creation
     * triggers.
     */
    private static function settingsFor(Invoice $invoice): ?CompanyDealSettings
    {
        return CompanyDealSettings::forInvoice($invoice);
    }

    private static function createActiveProject(Invoice $invoice): ?Project
    {
        // Backward-compatible path for a project already sitting at 'unpaid'
        // from before project creation moved entirely behind payment —
        // promotes it instead of raising a second Project. The status guard
        // is also the idempotency check: a second payment on an
        // already-active project finds it past 'unpaid' and does nothing.
        if ($invoice->project_id) {
            $project = Project::find($invoice->project_id);
            if (!$project || $project->status !== 'unpaid') {
                return null;
            }

            $project->update([
                'status'     => 'active',
                // Only backfilled if this pre-existing 'unpaid' project never
                // had one set — never overwrites a start_date someone already
                // chose manually before payment came in.
                'start_date' => $project->start_date ?? now()->toDateString(),
                'source' => $invoice->status === 'paid'
                    ? 'paid_invoice_auto_start'
                    : 'partial_paid_invoice_auto_start',
            ]);

            $creator = self::activeUser($invoice->created_by, $invoice->company_id);
            self::assignOwner($project, $invoice, $creator);
            self::migrateChatHistory($project, $invoice);
            self::finishActivation($project, $invoice);

            return $project;
        }

        $creator = self::activeUser($invoice->created_by, $invoice->company_id);

        $project = Project::create([
            'company_id' => $invoice->company_id,
            'client_id'  => $invoice->client_id,
            'lead_id'    => $invoice->lead_id,
            // projects.invoice_id = the invoice this project ORIGINATED from,
            // the opposite direction to invoices.project_id set just below.
            'invoice_id' => $invoice->id,
            'reference'  => self::nextProjectReference(),
            'name'       => self::projectName($invoice),
            'status'     => 'active',
            // The project's work starts the moment payment activates it —
            // was never set here at all before, leaving every auto-created
            // project's Start Date permanently blank.
            'start_date' => now()->toDateString(),
            'source'     => $invoice->status === 'paid'
                ? 'paid_invoice_auto_start'
                : 'partial_paid_invoice_auto_start',
            // Whichever guard raised the invoice, so "Created By" names the
            // real person instead of sitting blank.
            'created_by'          => $creator?->id,
            'created_by_admin_id' => $invoice->created_by_admin_id,
        ]);

        // Complete the back-link, exactly as the manual handoff does.
        $invoice->update(['project_id' => $project->id]);

        self::assignOwner($project, $invoice, $creator);
        self::migrateChatHistory($project, $invoice);
        self::finishActivation($project, $invoice);

        return $project;
    }

    /**
     * Before this Project existed, the public "Chat with Seller" widget
     * (App\Services\InvoiceChatContext) anchored the conversation to a
     * 'sales' ChatThread keyed by the invoice's Client, Lead, or (for a
     * fully guest invoice) the Invoice itself. Now that a Project exists,
     * InvoiceChatContext routes every FUTURE message into this Project's own
     * 'project_group' thread instead — so without this, everything sent
     * before the client paid became permanently unreachable the moment
     * payment created the project, reading as "the old chat disappeared."
     * Moves the messages themselves (not a copy) into the project's thread,
     * preserving sender attribution and timestamps exactly.
     */
    private static function migrateChatHistory(Project $project, Invoice $invoice): void
    {
        try {
            // A Lead that converts to a Client before payment leaves the
            // invoice with BOTH client_id and lead_id set — the actual
            // pre-payment conversation may be sitting under either key
            // depending on when it was sent, so every candidate must be
            // checked and migrated, not just the first one found. Checking
            // only client_id (dropping the lead_id branch once client_id
            // appeared) silently stranded the Lead-anchored thread whenever
            // it had the real messages and the Client-anchored one was empty
            // or didn't exist yet.
            $candidates = [];
            if ($invoice->client_id) {
                $candidates[] = ['Client', $invoice->client_id];
            }
            if ($invoice->lead_id) {
                $candidates[] = ['Lead', $invoice->lead_id];
            }
            if (!$candidates) {
                $candidates[] = ['Invoice', $invoice->id];
            }

            $projectThread = null;

            foreach ($candidates as [$linkedType, $linkedId]) {
                $salesThread = ChatThread::where('thread_type', 'sales')
                    ->where('linked_to_type', $linkedType)
                    ->where('linked_to_id', $linkedId)
                    ->first();

                if (!$salesThread) {
                    continue;
                }

                $projectThread ??= ProjectChatService::threadFor($project);

                // Forwarding address, set whether or not this thread holds any
                // messages. Without it the STAFF side never follows the move:
                // Api\User\SalesChatController and Api\Admin\SalesChatController
                // resolve thread_type='sales', so a Seller/Lead Manager/Admin
                // saw an empty Sales Chat here and every reply they sent landed
                // on a thread the client — whose portal and invoice link both
                // read the project thread — no longer reads. Recorded at the
                // only moment the mapping is actually known; a Client with
                // several projects can't have it recomputed afterwards. An
                // empty thread still needs it: a client who simply never
                // chatted before paying would otherwise leave staff replying
                // into the dead thread forever. See ChatThread::activeThread().
                $salesThread->update(['merged_into_thread_id' => $projectThread->id]);

                if (!ChatMessage::where('thread_id', $salesThread->id)->exists()) {
                    continue;
                }

                ChatMessage::where('thread_id', $salesThread->id)->update(['thread_id' => $projectThread->id]);

                if ($salesThread->last_message_at && (!$projectThread->last_message_at || $salesThread->last_message_at->gt($projectThread->last_message_at))) {
                    $projectThread->update(['last_message_at' => $salesThread->last_message_at]);
                    $projectThread->refresh();
                }
            }
        } catch (\Throwable $e) {
            // Same swallow-and-log posture as the rest of this class — a
            // migration failure must never undo the project/payment that
            // triggered it; worst case the old messages just stay on the
            // (now-unused) sales thread instead of moving.
            Log::warning('[project-start] project ' . $project->id . ' chat history migration: ' . $e->getMessage());
        }
    }

    /**
     * Who this auto-started project belongs to. Before this, an auto-created
     * project landed with created_by, seller_id and project_manager_id all
     * null — it belonged to nobody, showed "Unassigned", and never appeared
     * in the project list of the person whose deal it was.
     *
     * First choice is the invoice's own creator, which covers every sub-user
     * who can raise one — a Seller, a Project Manager, or a Manager holding
     * the invoicing permission.
     *
     * A Company Admin-raised invoice has no such user: Admin isn't a `users`
     * row, and project_manager_id/seller_id are `users` FKs, so the Admin
     * literally cannot be assigned. Rather than leave the project ownerless,
     * it falls to whoever the DEAL belongs to — the lead's current owner
     * (transferred_to, else assigned_to) or the client's account manager.
     * That is the person the Admin raised the invoice on behalf of. The same
     * fallback catches an invoice whose creator has since been deactivated;
     * assigning work to a disabled account would hide the project from
     * everyone.
     */
    private static function ownerFor(Invoice $invoice, ?User $creator): ?User
    {
        if ($creator) {
            return $creator;
        }

        $lead = $invoice->lead_id ? Lead::find($invoice->lead_id) : null;

        $candidates = [
            // A transferred lead's CURRENT owner is transferred_to; the
            // original assignee is only the fallback.
            $lead?->transferred_to,
            $lead?->assigned_to,
            $invoice->client_id ? Client::where('id', $invoice->client_id)->value('account_manager') : null,
        ];

        foreach ($candidates as $candidateId) {
            if ($owner = self::activeUser($candidateId, $invoice->company_id)) {
                return $owner;
            }
        }

        return null;
    }

    // ofCompany() (not a raw company_id match) so a multi-company user still
    // resolves here when this invoice's company is their SECONDARY one — a
    // plain company_id check silently returned null for them, which left the
    // auto-created project's created_by (and its owner assignment) blank
    // even though a real person raised the invoice. Same fix as
    // Api\Admin\SupportController::assign() and Api\User\ProjectController::
    // assignProjectManager() already apply for the same reason.
    private static function activeUser(?int $userId, int $companyId): ?User
    {
        return $userId
            ? User::ofCompany($companyId)
                ->where('id', $userId)
                ->where('is_active', true)
                ->first(['id', 'name', 'role_type'])
            : null;
    }

    /**
     * Hands the freshly created project to its owner.
     *
     * A Seller owner goes through ProjectSellerAssignmentService::assign() —
     * the same path Admin's "Assign Seller" action and the manual handoff
     * use — so seller_id, the project_seller_assignments history row, the
     * audit log, the project chat membership and the "Project assigned to
     * you" notification all happen exactly as they would for a hand-assigned
     * seller. NotificationService's own self-skip means the seller who raised
     * the invoice themselves isn't notified twice (they already get the
     * draft-awaiting-activation one); a seller who inherited an Admin-raised
     * invoice IS told, since nothing else would have told them.
     *
     * Any other owner (Project Manager, Manager) just takes project_manager_id,
     * exactly as Api\User\ProjectController::store() defaults the creator to
     * PM when they don't name someone else.
     *
     * Either way the owner also needs a ProjectTeamMember row, or
     * visibleProjects()'s team-membership leg never actually grants them
     * access to the project they own — the same reason store() writes that
     * row straight after creating a project.
     */
    private static function assignOwner(Project $project, Invoice $invoice, ?User $creator): void
    {
        $owner = self::ownerFor($invoice, $creator);

        if (!$owner) {
            return;
        }

        try {
            $sellerService = app(ProjectSellerAssignmentService::class);
            $seller = $owner->role_type === 'seller'
                ? $sellerService->assignableSeller($project->company_id, $owner->id)
                : null;

            if ($seller) {
                $sellerService->assign(
                    $project,
                    $seller,
                    "Auto-assigned from invoice {$invoice->invoice_number}",
                    $creator?->id,
                    $creator ? null : $invoice->created_by_admin_id,
                    $creator?->name ?? 'Company Admin'
                );
                $project->refresh();
            }

            // assign() already fills project_manager_id when it was empty;
            // this covers every other owner, and a Seller whose account no
            // longer passes assignableSeller() (display only either way —
            // isPM()/isInternalStaff() hard-exclude role_type='seller').
            if (!$project->project_manager_id) {
                $project->update(['project_manager_id' => $owner->id]);
            }

            // holdingRole(): see the identical tagging (and its reasoning) in
            // Api\User\ProjectController::store().
            $ownerIsSeller = User::where('id', $project->project_manager_id)->holdingRole($project->company_id, 'seller')->exists();
            ProjectTeamMember::updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $project->project_manager_id],
                ['role_in_project' => 'project_manager', 'team' => $ownerIsSeller ? 'sales' : 'production', 'assigned_by' => $creator?->id]
            );
        } catch (\Throwable $e) {
            // Same swallow-and-log posture as handle() — a project that got
            // created must never be undone by a follow-up write failing.
            Log::warning('[project-start] project ' . $project->id . ' owner assign: ' . $e->getMessage());
        }
    }

    /**
     * Finishes what Api\*\ProjectController::activate() used to do by hand:
     * create the project's storage folders, log it, and tell everyone it's
     * live — PM/team/production/seller (App\Services\ProjectCompletionService
     * ::notificationTargetUserIds(), the same fan-out list complete/close/
     * reopen already use), every Company Admin, and the Client Portal user if
     * one exists (ProjectCompletionService::notifyClientOfActivation()).
     * Each write is individually guarded — one bad recipient must not cost
     * the others their notification, nor break the activation that called
     * this.
     */
    private static function finishActivation(Project $project, Invoice $invoice): void
    {
        if ($project->folders()->count() === 0) {
            self::createProjectFolders($project);
        }

        $who     = $invoice->client?->name ?? $invoice->customer_name ?? 'The client';
        $paidAll = $invoice->status === 'paid';
        $title   = $paidAll ? 'Client paid in full — project activated'
                            : 'Client made a payment — project activated';
        $body    = "{$who} paid invoice {$invoice->invoice_number}. Project \"{$project->name}\" was automatically activated.";

        $project->logActivity('activated', $body, 'System');

        SystemAuditLog::create([
            'company_id' => $project->company_id, 'user_id' => null,
            'action' => 'activated', 'module_key' => 'project_management',
            'entity_type' => 'Project', 'entity_id' => $project->id,
        ]);

        // Every project must have a Compliance Case — never allowed to
        // undo/block the activation that triggered it, see ComplianceCaseService.
        try {
            \App\Services\ComplianceCaseService::ensureCaseForProject($project);
        } catch (\Throwable $e) {
            Log::warning('[project-start] project ' . $project->id . ' compliance case: ' . $e->getMessage());
        }

        $completionService = app(ProjectCompletionService::class);

        // The people who actually made this happen, on top of the standing
        // project fan-out (PM / team / production / seller).
        //
        // Whoever raised the invoice and got it paid is the reason this project
        // exists, and until now they were told nothing unless they also
        // happened to be the project's PM or seller — which the person who
        // merely raised the invoice often is not. invoices.created_by is the
        // staff user; an Admin-raised invoice carries created_by_admin_id
        // instead and is covered by the Company Admin notification below.
        //
        // projects.created_by is included for the same reason: on the
        // invoice-first flow the project is a stub someone raised earlier, and
        // that person is waiting to hear it went live.
        $recipientIds = collect($completionService->notificationTargetUserIds($project))
            ->merge([$invoice->created_by, $project->created_by])
            ->filter()
            ->unique();

        // Re-checked against users so a stale created_by (a hard-deleted
        // account) cannot 500 the activation — same reasoning as
        // notificationTargetUserIds()'s own closing lookup.
        $recipientIds = User::whereIn('id', $recipientIds)->pluck('id');

        foreach ($recipientIds as $userId) {
            try {
                Notification::create([
                    'user_id'    => $userId,
                    'company_id' => $invoice->company_id,
                    'type'       => 'project_activated',
                    'title'      => $title,
                    'body'       => $body,
                    'data'       => [
                        'project_id' => $project->id,
                        'invoice_id' => $invoice->id,
                        'link'       => "/projects/{$project->id}",
                    ],
                ]);
            } catch (\Throwable) {
            }
        }

        // Company Admin isn't a `users` row, so a plain Notification::create()
        // can't reach them — same reason notifyStakeholders() routes through
        // NotificationService for admins.
        try {
            NotificationService::notifyCompanyAdmins($invoice->company_id, null, [
                'module'      => 'project_management',
                'type'        => 'project_activated',
                'title'       => $title,
                'message'     => $body,
                'entity_type' => 'Project',
                'entity_id'   => $project->id,
                'url'         => "/admin/projects/{$project->id}",
            ]);
        } catch (\Throwable) {
        }

        try {
            $companyName = Company::find($project->company_id)?->invoicingProfile()['name'] ?? config('app.name');
            $completionService->notifyClientOfActivation($project, $companyName);
        } catch (\Throwable) {
        }
    }

    // Mirrors Api\Admin\ProjectController::createProjectFolders() /
    // Api\User\ProjectController::createProjectFolders() exactly — same
    // system subfolder set, same ProjectFolder + real-directory pairing,
    // same cleanup-on-failure. Duplicated the same way those two already
    // duplicate each other rather than forcing a new shared abstraction
    // across three call sites for what's otherwise a self-contained helper.
    private static function createProjectFolders(Project $project): void
    {
        $systemFolders = [
            'documents', 'tasks', 'production', 'compliance', 'invoices',
            'client-files', 'internal-notes', 'deliverables', 'revisions', 'timesheets',
        ];

        $slug = Str::slug($project->name) ?: 'project';
        $rootPath = "companies/{$project->company_id}/projects/{$project->id}-{$slug}";

        $createdDirs = [];

        try {
            if (!Storage::exists($rootPath)) {
                Storage::makeDirectory($rootPath);
                $createdDirs[] = $rootPath;
            }

            $project->update(['storage_folder' => $rootPath]);

            foreach ($systemFolders as $name) {
                $folderPath = "{$rootPath}/{$name}";

                if (!Storage::exists($folderPath)) {
                    Storage::makeDirectory($folderPath);
                    $createdDirs[] = $folderPath;
                }

                ProjectFolder::firstOrCreate(
                    ['project_id' => $project->id, 'name' => $name, 'parent_folder_id' => null],
                    ['folder_path' => $folderPath, 'is_system' => true, 'created_by' => null]
                );
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($createdDirs) as $dir) {
                Storage::deleteDirectory($dir);
            }
            Log::warning('[project-start] project ' . $project->id . ' folder creation: ' . $e->getMessage());
        }
    }

    /**
     * Only the name is stored, so it has to be the most meaningful one
     * available. Tries an AI-generated name first — App\Services\
     * LeadProjectTitleService::suggestProjectName() reads the lead's
     * confirmed requirements, Sales Chat and invoice details, in that
     * priority order, and returns null rather than a guess when it can't
     * support one. Wrapped locally (on top of handle()'s own outer
     * try/catch) so a slow/failed AI call degrades straight to the existing
     * chain rather than ever blocking project creation: project_title (the
     * title the seller typed on the invoice itself, "New Project" mode),
     * then the deal's own proposed title, then what the invoice said it was
     * for, and finally a named fallback so a project is never created blank.
     */
    private static function projectName(Invoice $invoice): string
    {
        try {
            $aiName = \App\Services\LeadProjectTitleService::suggestProjectName($invoice);
            if (is_string($aiName) && trim($aiName) !== '') {
                return trim($aiName);
            }
        } catch (\Throwable $e) {
            Log::warning('[project-start] AI project name failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
        }

        $candidates = [
            $invoice->project_title,
            $invoice->lead?->proposed_project_title,
            $invoice->invoice_purpose,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $who = $invoice->client?->name ?? $invoice->customer_name;

        return $who ? "Project — {$who}" : "Project — {$invoice->invoice_number}";
    }

    // Mirrors Api\User\ProjectController::nextProjectReference() — same
    // PRJ-{year}-{seq} series, so an auto-started project is indistinguishable
    // from a hand-created one in the reference sequence.
    //
    // withTrashed() on BOTH queries is essential, not defensive: Project is
    // soft-deleting, so a deleted project's row — and its reference — stays in
    // the table behind projects_reference_unique. Without it the uniqueness
    // probe reports "free" for a reference the index still holds, the loop exits
    // on the first candidate, and the INSERT dies on a duplicate key.
    private static function nextProjectReference(): string
    {
        $year = now()->year;
        $last = Project::withTrashed()
            ->whereYear('created_at', $year)
            ->where('reference', 'like', "PRJ-{$year}-%")
            ->latest('id')
            ->value('reference');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        do {
            $reference = sprintf('PRJ-%d-%04d', $year, $seq++);
        } while (Project::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    private static function emailClientFullPaymentRequired(Invoice $invoice): void
    {
        $email = $invoice->client?->email ?? $invoice->customer_email;
        if (!$email) {
            return;
        }

        $company     = Company::find($invoice->company_id);
        $companyName = $company?->invoicingProfile()['name'] ?? config('app.name');

        Mail::to($email)->send(new ProjectStartRequiresFullPaymentMail($invoice, $companyName));
    }
}
