<?php

namespace App\Services;

use App\Mail\EventNotificationMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\ComplianceCase;
use App\Models\ComplianceDocument;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\MailDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Email counterpart to the app's in-app notifications.
 *
 * Every method here is called right next to the Notification::create() (or
 * NotificationService::send()) that already fires for the same event, so the
 * bell and the inbox never disagree.
 *
 * fanOut() hands each recipient to App\Support\MailDelivery, which sends
 * inline by default and only queues where a worker is genuinely draining the
 * queue. Inline is the default because the app has to run on hosting with no
 * shell — there, queueing delivers nothing at all.
 *
 * The cost of inline is real and worth knowing: a wide fan-out (a client chat
 * message reaches the account manager plus every Company Admin) is one SMTP
 * round-trip per recipient, in series, inside that request. If that becomes a
 * problem, set QUEUE_NOTIFICATION_MAIL=true and run a worker — no code
 * changes needed.
 *
 * Task Assigned and Project Assigned are NOT here — they have their own
 * dedicated Mailables in AssignmentMailService, which came first.
 *
 * Recipient specs accepted by every method's fan-out:
 *   ['user_id' => 5]                 staff/client-portal user (users table)
 *   ['admin_id' => 2]                Company Admin (company_admins table)
 *   ['email' => '…', 'name' => '…']  a bare address (external client/customer)
 * Each may carry 'path' => '/admin/…' to override the action button's target
 * for that recipient — Admin and staff have different frontend routes for the
 * same entity, and one event usually goes to both.
 */
class EventMailService
{
    // ── Leads ─────────────────────────────────────────────────────────────

    /** New Lead Created — notifies whoever owns lead intake (Lead Managers + Company Admins). */
    public static function leadCreated(Lead $lead, string $actorName, array $recipients): void
    {
        self::fanOut($recipients, $lead->company_id, [
            'subject'     => "New lead created: {$lead->name}",
            'badge'       => '🎯 New Lead',
            'heading'     => 'A new lead has been created',
            'intro'       => "{$actorName} created a new lead. Details are below.",
            'rows'        => self::leadRows($lead),
            'actionLabel' => 'View Lead',
            'path'        => "/leads/{$lead->id}",
            'adminPath'   => "/admin/leads/{$lead->id}",
        ]);
    }

    /** Lead Assigned — notifies the new owner of the lead. */
    public static function leadAssigned(Lead $lead, ?int $assigneeId, string $actorName, ?int $actorUserId = null): void
    {
        if (!$assigneeId) return;

        self::fanOut([['user_id' => $assigneeId]], $lead->company_id, [
            'subject'     => "Lead assigned to you: {$lead->name}",
            'badge'       => '🎯 Lead Assigned',
            'heading'     => 'A lead has been assigned to you',
            'intro'       => "{$actorName} assigned you the lead below. It's now yours to follow up.",
            'rows'        => self::leadRows($lead),
            'actionLabel' => 'View Lead',
            'path'        => "/leads/{$lead->id}",
            'adminPath'   => "/admin/leads/{$lead->id}",
        ], $actorUserId);
    }

    private static function leadRows(Lead $lead): array
    {
        return [
            'Lead'           => $lead->name,
            'Company'        => $lead->company_name,
            'Email'          => $lead->email,
            'Phone'          => $lead->phone,
            'Source'         => $lead->source ? self::label($lead->source) : null,
            'Status'         => self::label((string) $lead->status),
            'Priority'       => self::label((string) $lead->priority),
            'Estimated value' => $lead->estimated_value ? number_format((float) $lead->estimated_value, 2) : null,
            'Date & time'    => self::stamp(),
        ];
    }

    // ── Project lifecycle ─────────────────────────────────────────────────

    /**
     * Project Activated / Completed / Approved — driven by the single
     * notifyLifecycle() choke point in both ProjectControllers, so the
     * $type filter here is what decides which lifecycle events earn an
     * email (closed/reopened deliberately stay in-app only).
     */
    public static function projectLifecycle(Project $project, string $type, string $body, array $recipients): void
    {
        $meta = [
            'project_activated'       => ['🚀 Project Activated', 'A project has been activated', 'Project activated'],
            'project_completed'       => ['✅ Project Completed', 'A project has been completed', 'Project completed'],
            'project_approved_locked' => ['🔒 Project Approved', 'A project has been approved and locked', 'Project approved'],
        ][$type] ?? null;

        if (!$meta) return;

        [$badge, $heading, $subjectPrefix] = $meta;

        self::fanOut($recipients, $project->company_id, [
            'subject'     => "{$subjectPrefix}: {$project->name}",
            'badge'       => $badge,
            'heading'     => $heading,
            'intro'       => $body,
            'rows'        => [
                'Project'    => $project->name,
                'Reference'  => $project->reference,
                'Client'     => $project->client?->name,
                'Status'     => self::label((string) $project->status),
                'Priority'   => self::label((string) $project->priority),
                'Deadline'   => $project->deadline?->format('d M Y'),
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'View Project',
            'path'        => "/projects/{$project->id}",
            'adminPath'   => "/admin/projects/{$project->id}",
        ]);
    }

    // ── Tasks ─────────────────────────────────────────────────────────────

    /** Task Overdue — from the scheduled tasks:notify-overdue command. */
    public static function taskOverdue(Task $task, Project $project, array $recipients): void
    {
        $daysLate = $task->due_date ? $task->due_date->diffInDays(now()) : null;

        self::fanOut($recipients, $project->company_id, [
            'subject'     => "Task overdue: {$task->task_number} - {$task->title}",
            'badge'       => '⏰ Task Overdue',
            'heading'     => 'A task has passed its due date',
            'intro'       => "Task {$task->task_number} on \"{$project->name}\" is past its due date and still open.",
            'rows'        => [
                'Task'        => $task->title,
                'Task number' => $task->task_number,
                'Project'     => $project->name,
                'Assigned to' => $task->assignedTo?->name,
                'Status'      => Task::STATUS_LABELS[$task->status] ?? $task->status,
                'Priority'    => self::label((string) $task->priority),
                'Due date'    => $task->due_date?->format('d M Y'),
                'Days overdue' => $daysLate !== null ? (string) max(1, (int) $daysLate) : null,
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'View Task',
            // Guard-agnostic redirector — see AssignmentMailService for why.
            'path'        => "/task/{$task->id}",
        ]);
    }

    // ── Invoices ──────────────────────────────────────────────────────────

    /** Invoice Overdue — from the scheduled invoices:mark-overdue command. */
    public static function invoiceOverdue(Invoice $invoice, array $recipients): void
    {
        $outstanding = (float) $invoice->total_amount - (float) $invoice->paid_amount;

        self::fanOut($recipients, $invoice->company_id, [
            'subject'     => "Invoice overdue: {$invoice->invoice_number}",
            'badge'       => '⚠️ Invoice Overdue',
            'heading'     => 'An invoice is now overdue',
            'intro'       => "Invoice {$invoice->invoice_number} passed its due date without being paid in full.",
            'rows'        => [
                'Invoice number' => $invoice->invoice_number,
                'Client'         => $invoice->client?->name ?? $invoice->customer_name,
                'Project'        => $invoice->project?->name ?? $invoice->project_title,
                'Total'          => $invoice->currency . ' ' . number_format((float) $invoice->total_amount, 2),
                'Paid so far'    => $invoice->currency . ' ' . number_format((float) $invoice->paid_amount, 2),
                'Outstanding'    => $invoice->currency . ' ' . number_format($outstanding, 2),
                'Due date'       => $invoice->due_date?->format('d M Y'),
                'Date & time'    => self::stamp(),
            ],
            'actionLabel' => 'View Invoice',
            'path'        => "/invoices/{$invoice->id}",
            'adminPath'   => "/admin/invoices/{$invoice->id}",
        ]);
    }

    // ── Compliance ────────────────────────────────────────────────────────

    /**
     * Compliance Document Rejected / Completed (approved) — driven by the
     * notifyCase() choke point in both ComplianceControllers. Other
     * compliance notification types (uploaded/resubmitted/expiry set/case
     * status) stay in-app only.
     */
    public static function complianceDocument(ComplianceCase $case, string $type, string $documentName, string $message, array $recipients): void
    {
        $meta = [
            'compliance_document_rejected' => ['❌ Document Rejected', 'A compliance document was rejected', 'Document rejected'],
            'compliance_document_approved' => ['✅ Document Approved', 'A compliance document was approved', 'Document approved'],
        ][$type] ?? null;

        if (!$meta) return;

        [$badge, $heading, $subjectPrefix] = $meta;

        self::fanOut($recipients, $case->company_id, [
            'subject'     => "{$subjectPrefix}: {$documentName}",
            'badge'       => $badge,
            'heading'     => $heading,
            'intro'       => $message,
            'rows'        => [
                'Document'   => $documentName,
                'Project'    => $case->project?->name,
                'Client'     => $case->client?->name,
                'Case status' => self::label((string) $case->status),
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'View Compliance',
            'path'        => "/compliance/projects/{$case->project_id}",
            'adminPath'   => "/admin/compliance/projects/{$case->project_id}",
        ]);
    }

    /** Compliance Document Expiring — from the scheduled compliance:notify-expiring command. */
    public static function complianceDocumentExpiring(ComplianceCase $case, ComplianceDocument $document, int $daysLeft, array $recipients): void
    {
        $when = $daysLeft <= 0 ? 'has expired' : ($daysLeft === 1 ? 'expires tomorrow' : "expires in {$daysLeft} days");

        self::fanOut($recipients, $case->company_id, [
            'subject'     => "Compliance document {$when}: {$document->original_name}",
            'badge'       => '📅 Document Expiring',
            'heading'     => 'A compliance document needs renewing',
            'intro'       => "\"{$document->original_name}\" {$when}. Upload a new version to keep this case compliant.",
            'rows'        => [
                'Document'   => $document->original_name,
                'Project'    => $case->project?->name,
                'Client'     => $case->client?->name,
                'Expires on' => $document->expires_at?->format('d M Y'),
                'Days left'  => (string) max(0, $daysLeft),
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'View Compliance',
            'path'        => "/compliance/projects/{$case->project_id}",
            'adminPath'   => "/admin/compliance/projects/{$case->project_id}",
        ]);
    }

    // ── Support tickets ───────────────────────────────────────────────────

    /** Support Ticket Assigned — notifies the agent who now owns the ticket. */
    public static function supportTicketAssigned(SupportTicket $ticket, ?int $assigneeId, string $actorName, ?int $actorUserId = null): void
    {
        if (!$assigneeId) return;

        self::fanOut([['user_id' => $assigneeId]], $ticket->company_id, [
            'subject'     => "Ticket assigned to you: #{$ticket->id} - {$ticket->subject}",
            'badge'       => '🎫 Ticket Assigned',
            'heading'     => 'A support ticket has been assigned to you',
            'intro'       => "{$actorName} assigned you ticket #{$ticket->id}.",
            'rows'        => self::ticketRows($ticket),
            'actionLabel' => 'View Ticket',
            'path'        => "/support/{$ticket->id}",
            'adminPath'   => "/admin/support/{$ticket->id}",
        ], $actorUserId);
    }

    /**
     * Critical Support Ticket — a high/urgent ticket the moment it is raised.
     * Separate from the ordinary "new ticket" notification so it can go wider
     * (every Company Admin) and read as an escalation, not an FYI.
     */
    public static function supportTicketCritical(SupportTicket $ticket, string $raisedByName, array $recipients): void
    {
        if (!in_array($ticket->priority, ['high', 'urgent'], true)) return;

        self::fanOut($recipients, $ticket->company_id, [
            'subject'     => strtoupper((string) $ticket->priority) . " ticket raised: #{$ticket->id} - {$ticket->subject}",
            'badge'       => '🚨 Critical Ticket',
            'heading'     => 'A ' . $ticket->priority . '-priority ticket was raised',
            'intro'       => "{$raisedByName} raised ticket #{$ticket->id} at {$ticket->priority} priority. It needs attention.",
            'rows'        => self::ticketRows($ticket, $raisedByName),
            'actionLabel' => 'View Ticket',
            'path'        => "/support/{$ticket->id}",
            'adminPath'   => "/admin/support/{$ticket->id}",
        ]);
    }

    /** Support Ticket Resolved — notifies whoever raised it. */
    public static function supportTicketResolved(SupportTicket $ticket, string $actorName, array $recipients): void
    {
        self::fanOut($recipients, $ticket->company_id, [
            'subject'     => "Ticket resolved: #{$ticket->id} - {$ticket->subject}",
            'badge'       => '✅ Ticket Resolved',
            'heading'     => 'Your support ticket has been resolved',
            'intro'       => "{$actorName} marked ticket #{$ticket->id} as resolved. If the issue is still there, reply on the ticket and it'll be reopened.",
            'rows'        => self::ticketRows($ticket),
            'actionLabel' => 'View Ticket',
            'path'        => "/client/support/{$ticket->id}",
        ]);
    }

    private static function ticketRows(SupportTicket $ticket, ?string $raisedByName = null): array
    {
        return [
            'Ticket number' => '#' . $ticket->id,
            'Subject'       => $ticket->subject,
            'Category'      => self::label((string) $ticket->category),
            'Priority'      => self::label((string) $ticket->priority),
            'Status'        => self::label((string) $ticket->status),
            'Raised by'     => $raisedByName,
            'Project'       => $ticket->project?->name,
            'Date & time'   => self::stamp(),
        ];
    }

    // ── Client messages ───────────────────────────────────────────────────

    /**
     * Important Client Message — a message the CLIENT sent into a project
     * conversation. Staff-authored messages stay in-app only: emailing every
     * chat line in both directions would turn the inbox into a firehose.
     */
    public static function clientMessage(Project $project, string $clientName, string $preview, string $chatPath, array $recipients): void
    {
        self::fanOut($recipients, $project->company_id, [
            'subject'     => "New client message on {$project->name}",
            'badge'       => '💬 Client Message',
            'heading'     => 'A client sent a message',
            'intro'       => "{$clientName} posted a message on \"{$project->name}\".",
            'rows'        => [
                'Project'     => $project->name,
                'From'        => $clientName,
                'Message'     => $preview,
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'Open Chat',
            'path'        => $chatPath,
        ]);
    }

    /**
     * The same event on the direct Sales Chat, which has no project behind it
     * — a client messaging their account manager rather than a project team.
     */
    public static function clientDirectMessage(int $companyId, int $clientId, string $clientName, string $preview, array $recipients): void
    {
        self::fanOut($recipients, $companyId, [
            'subject'     => "New client message from {$clientName}",
            'badge'       => '💬 Client Message',
            'heading'     => 'A client sent a message',
            'intro'       => "{$clientName} sent a message on Sales Chat.",
            'rows'        => [
                'From'        => $clientName,
                'Message'     => $preview,
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'Open Chat',
            'path'        => "/clients/{$clientId}",
            'adminPath'   => "/admin/clients/{$clientId}",
        ]);
    }

    /**
     * A client asked for one of the company's services from their portal
     * ("Grow Your Business With Us").
     *
     * Emailed as well as belled because this is the one client action nobody
     * is waiting for: an invoice or a support ticket is expected, an existing
     * client asking to buy more work is not, and a bell icon nobody happens
     * to look at is how that goes cold. The Lead it raised is linked so the
     * recipient lands on something actionable rather than a read-only notice.
     *
     * Two questions this has to answer before the reader opens anything:
     *
     *   WHO approached   name and company, plus the email and phone needed to
     *                    actually reply — the recipient is about to quote for
     *                    this person, and hunting for their number in the CRM
     *                    is how a warm lead cools.
     *   WHAT they want   the service they asked for, plus anything else of
     *                    theirs still open: a client who asked about SEO on
     *                    Monday and hosting today is one conversation, and
     *                    quoting each in isolation misreads them.
     *
     * Takes the Client rather than primitives because four of these rows
     * come straight off that record and every caller already holds it.
     * $alsoOpen stays a plain array of names — the caller decides what
     * "still open" means, this only prints it. The single entry point for
     * every client-portal service ask, "Grow Your Business With Us" and the
     * AI Suggested IT Services widget alike (see
     * App\Services\ClientServiceRequestService).
     */
    public static function serviceRequested(
        Client $client,
        string $serviceName,
        string $intentLabel,
        ?string $message,
        ?string $preferredDate,
        ?int $leadId,
        array $alsoOpen,
        array $recipients,
    ): void {
        // array_filter: a quote request has no preferred date and often no
        // message — an empty "Message: —" row reads like something failed.
        // Same for a client with no phone or company name on file, and for
        // the "Also open" row when this is their only live request.
        $rows = array_filter([
            'Client'         => $client->name,
            'Company'        => $client->company_name,
            'Email'          => $client->email,
            'Phone'          => $client->phone,
            'Service'        => $serviceName,
            'Also open'      => $alsoOpen ? implode(', ', $alsoOpen) : null,
            'Request'        => $intentLabel,
            'Preferred date' => $preferredDate,
            'Message'        => $message,
            'Date & time'    => self::stamp(),
        ], fn ($v) => $v !== null && $v !== '');

        self::fanOut($recipients, $client->company_id, [
            'subject'     => "{$intentLabel}: {$serviceName} — {$client->name}",
            'badge'       => '✨ Service Request',
            'heading'     => 'A client asked about one of your services',
            'intro'       => "{$client->name} requested {$serviceName} from their Client Portal.",
            'rows'        => $rows,
            // The Lead is the actionable record; without one (a company with
            // no Sales module) the button would go nowhere, so it is dropped.
            'actionLabel' => $leadId ? 'Open Lead' : null,
            'path'        => $leadId ? "/leads/{$leadId}" : null,
            'adminPath'   => $leadId ? "/admin/leads/{$leadId}" : null,
        ]);
    }

    // ── Users, companies, roles ───────────────────────────────────────────

    /**
     * User Created (invite path) — the invite link used to be copy-paste only,
     * returned in the API response for the Admin to forward by hand. Direct
     * account creation already emails UserWelcomeMail with credentials.
     */
    public static function userInvited(User $user, string $inviteUrl, int $companyId, string $invitedByName): void
    {
        self::fanOut([['user_id' => $user->id, 'force' => true]], $companyId, [
            'subject'     => 'You have been invited to ' . self::companyName($companyId),
            'badge'       => '✉️ Invitation',
            'heading'     => "You've been invited to join",
            'intro'       => "{$invitedByName} invited you to " . self::companyName($companyId) . ". Use the button below to set your password and activate your account.",
            'rows'        => [
                'Name'        => $user->name,
                'Email'       => $user->email,
                'Role'        => $user->custom_role_label ?: self::label((string) $user->role_type),
                'Invite valid until' => $user->invite_expires_at?->format('d M Y, h:i A'),
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'Accept Invitation',
            'absoluteUrl' => $inviteUrl,
            'footNote'    => 'This invitation link expires in 7 days. If it has already lapsed, ask your administrator to resend it.',
        ]);
    }

    /** Company Assignment — an existing user gets access to another company. */
    public static function companyAssigned(User $user, int $companyId, string $actorName): void
    {
        self::fanOut([['user_id' => $user->id, 'force' => true]], $companyId, [
            'subject'     => 'You now have access to ' . self::companyName($companyId),
            'badge'       => '🏢 Company Access',
            'heading'     => "You've been added to a company",
            'intro'       => "{$actorName} gave you access to " . self::companyName($companyId) . ". Pick it from the company selector after you sign in.",
            'rows'        => [
                'Company'     => self::companyName($companyId),
                'Your role'   => $user->custom_role_label ?: self::label((string) $user->role_type),
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'Open Dashboard',
            'path'        => '/dashboard',
        ]);
    }

    /** Role Assignment — a user's role (and with it their permissions) changed. */
    public static function roleAssigned(User $user, int $companyId, string $oldRoleLabel, string $newRoleLabel, string $actorName): void
    {
        self::fanOut([['user_id' => $user->id, 'force' => true]], $companyId, [
            'subject'     => "Your role has been updated to {$newRoleLabel}",
            'badge'       => '🔑 Role Updated',
            'heading'     => 'Your role has changed',
            'intro'       => "{$actorName} changed your role at " . self::companyName($companyId) . ". Your available modules and permissions may have changed with it.",
            'rows'        => [
                'Company'     => self::companyName($companyId),
                'Previous role' => $oldRoleLabel,
                'New role'    => $newRoleLabel,
                'Date & time' => self::stamp(),
            ],
            'actionLabel' => 'Open Dashboard',
            'path'        => '/dashboard',
        ]);
    }

    // ── Trial / Subscription lifecycle ───────────────────────────────────
    // Unlike every event above, these are keyed to a CompanyAdmin (tenant),
    // not a Company row — a CompanyAdmin can own several Companies, and
    // trial/subscription state lives on the Admin, shared across all of
    // them. sendToAdmin() below bypasses fanOut()'s single-company-name
    // lookup and mails the Admin directly, using their own business_name.

    public static function trialStarted(CompanyAdmin $admin, int $durationDays): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Your Movent CRM trial has started',
            'badge'       => '🎉 Trial Started',
            'heading'     => 'Your free trial is live',
            'intro'       => "Your {$durationDays}-day free trial has started. Every module is available while you explore Movent CRM.",
            'rows'        => [
                'Trial started' => now()->format('d M Y'),
                'Trial ends'    => $admin->trial_ends_at?->format('d M Y'),
            ],
            'actionLabel' => 'Open Dashboard',
            'path'        => '/admin/dashboard',
        ]);
    }

    public static function trialEndingSoon(CompanyAdmin $admin, int $daysLeft): void
    {
        self::sendToAdmin($admin, [
            'subject'     => "Your trial ends in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's'),
            'badge'       => '⏳ Trial Ending Soon',
            'heading'     => 'Your free trial is ending soon',
            'intro'       => "Your Movent CRM trial ends in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ". Activate a subscription to keep uninterrupted access.",
            'rows'        => [
                'Trial ends' => $admin->trial_ends_at?->format('d M Y'),
                'Days left'  => (string) $daysLeft,
            ],
            'actionLabel' => 'Choose a Plan',
            'path'        => '/admin/plan',
        ]);
    }

    public static function trialExpired(CompanyAdmin $admin): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Your Movent CRM trial has expired',
            'badge'       => '⌛ Trial Expired',
            'heading'     => 'Your free trial has ended',
            'intro'       => 'Your free trial has expired. Please activate a subscription to continue using Movent CRM.',
            'rows'        => [
                'Trial ended' => $admin->trial_ends_at?->format('d M Y'),
            ],
            'actionLabel' => 'Activate Subscription',
            'path'        => '/admin/plan',
        ]);
    }

    public static function gracePeriodStarted(CompanyAdmin $admin): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Grace period started for your Movent CRM account',
            'badge'       => '⏳ Grace Period',
            'heading'     => "You're in a grace period",
            'intro'       => 'Your access continues for a short grace period. Activate a subscription before it ends to avoid suspension.',
            'rows'        => [
                'Grace period ends' => $admin->grace_period_ends_at?->format('d M Y'),
            ],
            'actionLabel' => 'Choose a Plan',
            'path'        => '/admin/plan',
        ]);
    }

    public static function accountSuspended(CompanyAdmin $admin, string $reason): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Your Movent CRM account has been suspended',
            'badge'       => '🚫 Account Suspended',
            'heading'     => 'Your account has been suspended',
            'intro'       => "Your company account and all its users have been suspended. Reason: {$reason}. No data has been deleted — activate a subscription to restore access.",
            'rows'        => [
                'Suspended on' => now()->format('d M Y'),
                'Reason'       => $reason,
            ],
            'actionLabel' => 'Reactivate Account',
            'path'        => '/admin/plan',
        ]);
    }

    public static function accountReactivated(CompanyAdmin $admin): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Your Movent CRM account has been reactivated',
            'badge'       => '✅ Account Reactivated',
            'heading'     => 'Welcome back!',
            'intro'       => 'Your subscription is active — your company and all its users have been restored, with every previous record kept exactly as it was.',
            'rows'        => [
                'Reactivated on' => now()->format('d M Y'),
                'Plan'           => $admin->package?->name,
            ],
            'actionLabel' => 'Open Dashboard',
            'path'        => '/admin/dashboard',
        ]);
    }

    public static function subscriptionActivated(CompanyAdmin $admin): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Your Movent CRM subscription is active',
            'badge'       => '🎉 Subscription Activated',
            'heading'     => 'Subscription activated',
            'intro'       => "Your {$admin->package?->name} subscription is now active. Thanks for subscribing to Movent CRM!",
            'rows'        => [
                'Plan'         => $admin->package?->name,
                'Billing cycle' => $admin->package ? self::label((string) $admin->package->billing_cycle) : null,
                'Renews on'    => $admin->subscription_ends_at?->format('d M Y'),
            ],
            'actionLabel' => 'Open Dashboard',
            'path'        => '/admin/dashboard',
        ]);
    }

    public static function renewalDue(CompanyAdmin $admin, int $daysLeft): void
    {
        self::sendToAdmin($admin, [
            'subject'     => "Your subscription renews in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's'),
            'badge'       => '🔔 Renewal Due',
            'heading'     => 'Your subscription is renewing soon',
            'intro'       => "Your {$admin->package?->name} subscription renews in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . '.',
            'rows'        => [
                'Plan'        => $admin->package?->name,
                'Renewal date' => $admin->subscription_ends_at?->format('d M Y'),
                'Days left'   => (string) $daysLeft,
            ],
            'actionLabel' => 'Manage Subscription',
            'path'        => '/admin/plan',
        ]);
    }

    public static function paymentSuccessful(CompanyAdmin $admin, string $amount): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Payment received — Movent CRM',
            'badge'       => '💳 Payment Successful',
            'heading'     => 'Payment received',
            'intro'       => "We've received your payment of {$amount}. Thank you!",
            'rows'        => [
                'Amount'    => $amount,
                'Plan'      => $admin->package?->name,
                'Date'      => now()->format('d M Y'),
            ],
            'actionLabel' => 'View Subscription',
            'path'        => '/admin/plan',
        ]);
    }

    public static function paymentFailed(CompanyAdmin $admin, ?string $reason = null): void
    {
        self::sendToAdmin($admin, [
            'subject'     => 'Payment failed — Movent CRM',
            'badge'       => '⚠️ Payment Failed',
            'heading'     => "Your payment didn't go through",
            'intro'       => 'We were unable to process your subscription payment.' . ($reason ? " Reason: {$reason}." : '') . ' Please try again to avoid a service interruption.',
            'rows'        => array_filter([
                'Plan'   => $admin->package?->name,
                'Reason' => $reason,
                'Date'   => now()->format('d M Y'),
            ]),
            'actionLabel' => 'Retry Payment',
            'path'        => '/admin/plan',
        ]);
    }

    // Sends straight to a CompanyAdmin's own email, bypassing fanOut()'s
    // Company-name lookup (there may be several Companies, or — very briefly,
    // mid-registration — none yet). Uses the Admin's own business_name/name
    // as the "company" shown in the email instead.
    private static function sendToAdmin(CompanyAdmin $admin, array $payload): void
    {
        if (!$admin->email) return;

        try {
            $url = $payload['path'] ? self::frontendUrl($payload['path']) : null;

            MailDelivery::to($admin->email, new EventNotificationMail(
                subjectLine:   $payload['subject'],
                badge:         $payload['badge'],
                heading:       $payload['heading'],
                intro:         $payload['intro'],
                rows:          $payload['rows'] ?? [],
                companyName:   trim((string) ($admin->business_name ?: $admin->name)) ?: (string) config('app.name'),
                recipientName: self::displayName($admin->name),
                actionLabel:   $url ? ($payload['actionLabel'] ?? 'Open') : null,
                actionUrl:     $url,
                footNote:      $payload['footNote'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::warning('[event-mail] subscription mail delivery failed', [
                'admin_id' => $admin->id,
                'subject'  => $payload['subject'] ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ── Fan-out plumbing ──────────────────────────────────────────────────

    /**
     * Resolves each recipient spec to a real address and sends one email per
     * recipient — never a single mail with many To: addresses, which would
     * leak every recipient to every other one.
     *
     * $payload keys: subject, badge, heading, intro, rows, actionLabel, and
     * one of path (staff route, with optional adminPath for admin_id
     * recipients) or absoluteUrl (already-built link, e.g. an invite token
     * URL). Optional footNote.
     */
    private static function fanOut(array $recipients, ?int $companyId, array $payload, ?int $actorUserId = null): void
    {
        if (!$companyId) return;

        $companyName = self::companyName($companyId);
        $seenEmails  = [];

        foreach ($recipients as $spec) {
            try {
                [$email, $name, $isAdmin] = self::resolveRecipient($spec, $actorUserId);
                if (!$email) continue;

                // A user who qualifies twice (e.g. both PM and team member)
                // must still get exactly one email for the event.
                $key = strtolower($email);
                if (isset($seenEmails[$key])) continue;
                $seenEmails[$key] = true;

                $url = $payload['absoluteUrl'] ?? null;
                if (!$url) {
                    $path = $spec['path']
                        ?? ($isAdmin ? ($payload['adminPath'] ?? $payload['path'] ?? null) : ($payload['path'] ?? null));
                    $url = $path ? self::frontendUrl($path) : null;
                }

                MailDelivery::to($email, new EventNotificationMail(
                    subjectLine:   $payload['subject'],
                    badge:         $payload['badge'],
                    heading:       $payload['heading'],
                    intro:         $payload['intro'],
                    rows:          $payload['rows'] ?? [],
                    companyName:   $companyName,
                    recipientName: $name,
                    actionLabel:   $url ? ($payload['actionLabel'] ?? 'Open') : null,
                    actionUrl:     $url,
                    footNote:      $payload['footNote'] ?? null,
                ));
            } catch (\Throwable $e) {
                Log::warning('[event-mail] delivery failed', [
                    'subject' => $payload['subject'] ?? null,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array{0: ?string, 1: string, 2: bool} [email, display name, isCompanyAdmin] */
    private static function resolveRecipient(array $spec, ?int $actorUserId): array
    {
        if (!empty($spec['email'])) {
            return [$spec['email'], self::displayName($spec['name'] ?? null), false];
        }

        if (!empty($spec['admin_id'])) {
            $admin = CompanyAdmin::find($spec['admin_id']);
            return [$admin?->email, self::displayName($admin?->name), true];
        }

        if (!empty($spec['user_id'])) {
            if ($actorUserId !== null && (int) $spec['user_id'] === (int) $actorUserId) {
                return [null, '', false];
            }

            $user = User::find($spec['user_id']);
            // An invited or suspended account still needs its invite/company/
            // role email — those are exactly the messages that tell someone
            // their account exists. Every other event skips inactive users.
            if ($user && !$user->is_active && empty($spec['force'])) {
                return [null, '', false];
            }

            return [$user?->email, self::displayName($user?->name), false];
        }

        return [null, '', false];
    }

    /** Every Company Admin who owns $companyId, as recipient specs. */
    public static function companyAdminRecipients(int $companyId): array
    {
        return CompanyAdmin::whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->pluck('id')
            ->map(fn ($id) => ['admin_id' => $id])
            ->all();
    }

    private static function companyName(int $companyId): string
    {
        try {
            $name = Company::find($companyId)?->invoicingProfile()['name'] ?? null;
        } catch (\Throwable) {
            $name = null;
        }

        return trim((string) $name) ?: (string) config('app.name');
    }

    private static function displayName(?string $name): string
    {
        return trim((string) $name) ?: 'there';
    }

    private static function frontendUrl(string $path): string
    {
        return FrontendUrl::to($path);
    }

    // 'partially_paid' → 'Partially paid', 'urgent' → 'Urgent'
    private static function label(string $value): string
    {
        return $value === '' ? '' : ucfirst(str_replace('_', ' ', $value));
    }

    private static function stamp(): string
    {
        return now()->format('d M Y, h:i A');
    }
}
