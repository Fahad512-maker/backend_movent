<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalPermission;
use App\Models\CompanyModule;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\EventMailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Mirrors InvoiceNotificationService's exact gating pattern (portal_access +
// user_id present, and the client's own portal hasn't hidden this module).
// Api\Client\SupportController already notifies staff/admins whenever a
// client raises a ticket or replies — this is the missing other direction:
// a client got no signal at all when staff/admin replied to their ticket.
class SupportNotificationService
{
    private const CATEGORY_LABELS = [
        'project_support'       => 'Project Support',
        'billing_payments'      => 'Billing & Payments',
        'technical_issue'       => 'Technical Issue',
        'general_inquiry'       => 'General Inquiry',
        'account_login'         => 'Account & Login',
        'file_document_request' => 'File / Document Request',
        'revision_request'      => 'Revision Request',
        'bug_report'            => 'Bug Report',
        'feature_request'       => 'Feature Request',
        'other'                 => 'Other',
    ];

    // Fired the moment Admin/staff assign a ticket to a staff member — that
    // person previously had no signal at all beyond stumbling on it in the
    // ticket list. $actorAdminId/$actorUserId identify whoever performed the
    // assignment (Admin\SupportController passes admin, User\SupportController
    // passes user) purely so a self-assign never notifies the assigner about
    // their own action; only one of them is ever set.
    public static function notifyAssignee(SupportTicket $ticket, int $assigneeUserId, string $actorName, ?int $actorAdminId = null, ?int $actorUserId = null): void
    {
        try {
            if ($actorUserId && (int) $actorUserId === (int) $assigneeUserId) {
                return;
            }

            $clientName = Client::where('user_id', $ticket->raised_by)->value('name') ?? 'a client';
            $categoryLabel = self::CATEGORY_LABELS[$ticket->category] ?? $ticket->category;

            $body = "Ticket #{$ticket->id} ({$categoryLabel}) from {$clientName} has been assigned to you by {$actorName}.";

            if ($ticket->category === SupportTicket::PROJECT_CATEGORY && $ticket->project_id) {
                $projectName = $ticket->project?->name;
                if ($projectName) {
                    $body .= " Project: {$projectName}.";
                }
            }

            Notification::create([
                'user_id'        => $assigneeUserId,
                'actor_admin_id' => $actorAdminId,
                'actor_user_id'  => $actorUserId,
                'company_id'     => $ticket->company_id,
                'type'           => 'support_ticket_assigned',
                'title'          => "New ticket assigned: #{$ticket->id}",
                'body'           => $body,
                'data'           => [
                    'ticket_id' => $ticket->id,
                    'link'      => "/support/{$ticket->id}",
                ],
            ]);

            EventMailService::supportTicketAssigned($ticket, $assigneeUserId, $actorName, $actorUserId);
        } catch (\Throwable $e) {
            Log::warning('[support-notify] Could not create assignment notification for ticket '
                . $ticket->id . ': ' . $e->getMessage());
        }
    }

    // Confirmation sent to the client the moment their own ticket is
    // created — Api\Client\SupportController::store() already notifies
    // staff/admins; this is the "we got it" signal back to the client.
    public static function notifyClientOnCreate(SupportTicket $ticket): void
    {
        try {
            $client = Client::where('user_id', $ticket->raised_by)->first();

            if (!$client || !$client->portal_access || !$client->user_id) {
                return;
            }

            if (!self::clientSeesSupport($client)) {
                return;
            }

            Notification::create([
                'user_id'    => $ticket->raised_by,
                'company_id' => $ticket->company_id,
                'type'       => 'support_ticket_created',
                'title'      => "Ticket received: {$ticket->subject}",
                'body'       => "Your ticket #{$ticket->id} has been received. Our team will respond shortly.",
                'data'       => [
                    'ticket_id' => $ticket->id,
                    'link'      => "/client/support/{$ticket->id}",
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[support-notify] Could not create ticket-received notification for ticket '
                . $ticket->id . ': ' . $e->getMessage());
        }
    }

    // Fired whenever Admin/staff change a ticket's status (open/in_progress/
    // on_hold/resolved/closed) — the client previously only heard about
    // replies, never a status-only change (e.g. straight to Resolved with
    // no reply message).
    public static function notifyClientOnStatusChange(SupportTicket $ticket, string $newStatus, string $actorName): void
    {
        try {
            // "Support Ticket Resolved" by email goes out BEFORE the portal
            // gating below, on purpose: a client with no portal login (or
            // with Support hidden in their portal) is exactly the one for
            // whom email is the only channel that reaches them at all.
            if ($newStatus === 'resolved') {
                EventMailService::supportTicketResolved($ticket, $actorName, [['user_id' => $ticket->raised_by]]);
            }

            $client = Client::where('user_id', $ticket->raised_by)->first();

            if (!$client || !$client->portal_access || !$client->user_id) {
                return;
            }

            if (!self::clientSeesSupport($client)) {
                return;
            }

            $wording = [
                'open'        => 'reopened',
                'in_progress' => 'marked as In Progress',
                'on_hold'     => 'put on hold',
                'resolved'    => 'resolved',
                'closed'      => 'closed',
            ][$newStatus] ?? "updated to \"{$newStatus}\"";

            Notification::create([
                'user_id'    => $ticket->raised_by,
                'company_id' => $ticket->company_id,
                'type'       => 'support_ticket_status',
                'title'      => "Ticket status updated: {$ticket->subject}",
                'body'       => "{$actorName} {$wording} your ticket #{$ticket->id}.",
                'data'       => [
                    'ticket_id' => $ticket->id,
                    'link'      => "/client/support/{$ticket->id}",
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[support-notify] Could not create status-change notification for ticket '
                . $ticket->id . ': ' . $e->getMessage());
        }
    }

    public static function notifyClientOnReply(SupportTicket $ticket, SupportTicketReply $reply, string $repliedByName): void
    {
        try {
            // SupportTicket.raised_by IS the client's own portal login id
            // (see Api\Client\SupportController::store()) — no client_id
            // column on the ticket itself, unlike Invoice.
            $client = Client::where('user_id', $ticket->raised_by)->first();

            // portal_access + user_id together are what "has a portal login"
            // means — see ClientPortalService::createOrUpdatePortalUser().
            if (!$client || !$client->portal_access || !$client->user_id) {
                return;
            }

            if (!self::clientSeesSupport($client)) {
                return;
            }

            $preview = $reply->message
                ? Str::limit($reply->message, 120)
                : ($reply->attachment_name ? "Sent an attachment: {$reply->attachment_name}" : 'Sent a reply');

            Notification::create([
                'user_id'    => $ticket->raised_by,
                'company_id' => $ticket->company_id,
                'type'       => 'support_ticket_reply',
                'title'      => "New reply on ticket: {$ticket->subject}",
                'body'       => "{$repliedByName}: {$preview}",
                'data'       => [
                    'ticket_id' => $ticket->id,
                    // The frontend bell reads data.link, not the url column —
                    // see frontend/components/layout/Navbar.tsx's mapping.
                    'link'      => "/client/support/{$ticket->id}",
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[support-notify] Could not create client portal notification for ticket '
                . $ticket->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether this client's portal actually shows the Support section.
     * Mirrors Api\Client\AuthController::permissions()'s 'support' entry
     * exactly: unlike invoices/payments/etc., Support has no dedicated
     * purchasable module of its own — buying 'client_portal' alone is
     * enough — so only that module (not a 'support' CompanyModule row,
     * which doesn't exist) is checked here.
     */
    private static function clientSeesSupport(Client $client): bool
    {
        $companyHasModule = CompanyModule::where('company_id', $client->company_id)
            ->where('module_key', 'client_portal')
            ->where('is_enabled', true)
            ->exists();

        if (!$companyHasModule) {
            return false;
        }

        $toggle = ClientPortalPermission::where('client_id', $client->id)
            ->where('module_key', 'support')
            ->value('is_enabled');

        return $toggle === null ? true : (bool) $toggle;
    }
}
