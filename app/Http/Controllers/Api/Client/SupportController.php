<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use App\Services\EventMailService;
use App\Services\NotificationService;
use App\Services\SupportNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends Controller
{
    use ScopesToPermittedClient;

    public function index(Request $request): JsonResponse
    {
        // Was previously the only Api\Client\* controller that never checked
        // portal_access (let alone the 'support' module toggle) — a client
        // whose ENTIRE portal was disabled by Admin could still use Support.
        $client = $this->permittedClient($request, 'support');

        $tickets = SupportTicket::where('raised_by', $request->user()->id)
            ->where('company_id', $client->company_id)
            ->with(['project:id,name'])
            ->orderByDesc('created_at')
            ->get(['id', 'subject', 'category', 'status', 'priority', 'project_id', 'created_at', 'resolved_at']);

        return ApiResponse::success($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->permittedClient($request, 'support');

        $request->validate([
            'subject'            => 'required|string|max:255',
            'category'           => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority'           => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'description'        => 'nullable|string|max:5000',
            'attachment'         => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($client->company_id, 10240)],
            'project_id'         => ['required_if:category,' . SupportTicket::PROJECT_CATEGORY, 'nullable', 'integer'],
            'invoice_id'         => 'nullable|integer',
            'payment_reference'  => 'nullable|string|max:255',
        ]);

        $project = null;
        if ($request->filled('project_id')) {
            $projectIds = $this->permittedClientIds($request, 'projects');
            $project = Project::whereIn('client_id', $projectIds)
                ->notDraft()
                ->find($request->project_id);

            if (!$project) {
                return ApiResponse::error('Selected project was not found on your account.', 422);
            }
        }

        $invoiceId = null;
        if ($request->filled('invoice_id')) {
            // The invoice reference is always optional (unlike project_id for
            // Project Support), so a client whose portal doesn't have the
            // 'invoices' module enabled must still be able to raise the
            // ticket — just without the invoice attached — rather than
            // getting a 403 for the whole request (permittedClientIds()
            // aborts when a module isn't enabled).
            try {
                $invoiceIds = $this->permittedClientIds($request, 'invoices');
                $invoice = Invoice::whereIn('client_id', $invoiceIds)
                    ->whereNotIn('status', ['draft', 'cancelled'])
                    ->find($request->invoice_id);
                $invoiceId = $invoice?->id;
            } catch (\Throwable $e) {
                $invoiceId = null;
            }
        }

        $attachmentName = null;
        $storageFields  = [];
        if ($request->hasFile('attachment')) {
            $file   = $request->file('attachment');
            $stored = AttachmentStorageService::store($file, $client->company_id, 'support', 'support-attachments', 'public');
            if (!$stored) {
                return AttachmentStorageService::errorResponse($client->company_id);
            }
            $attachmentName = $stored->originalName;
            // SupportTicket's own columns are attachment_path/attachment_size,
            // not locationAttributes()'s file_path — mapped explicitly rather
            // than array_merge()'d, since Eloquent would otherwise silently
            // drop the mismatched key with no error.
            $storageFields = [
                'storage_type'           => $stored->storageType,
                'attachment_path'        => $stored->filePath,
                'attachment_size'        => $stored->sizeBytes,
                'google_drive_file_id'   => $stored->googleDriveFileId,
                'google_drive_folder_id' => $stored->googleDriveFolderId,
            ];
        }

        $ticket = SupportTicket::create(array_merge($storageFields, [
            'company_id'         => $client->company_id,
            'raised_by'          => $request->user()->id,
            'subject'            => $request->subject,
            'category'           => $request->category,
            'priority'           => $request->priority,
            'description'        => $request->description,
            'status'             => 'open',
            'attachment_name'    => $attachmentName,
            'project_id'         => $project?->id,
            'invoice_id'         => $invoiceId,
            'payment_reference'  => $request->payment_reference,
        ]));

        // Urgent tickets are the only tenant support traffic the platform
        // owner sees — everything below that priority is the company's own to
        // handle, and putting it in the Super Admin's bell would bury the
        // platform events it exists for.
        if ($ticket->priority === 'urgent') {
            \App\Services\SuperAdminNotificationService::supportEscalated(
                $ticket->id,
                $ticket->subject,
                $client->company?->name,
                $client->company_id
            );
        }

        $staffNote = null;
        if ($project && $request->category === SupportTicket::PROJECT_CATEGORY) {
            if (in_array($project->status, SupportTicket::AUTO_REOPEN_PROJECT_STATUSES, true)) {
                $fromStatus = $project->status;
                $project->reopen(
                    "Auto-reopened: client raised support ticket #{$ticket->id} ({$ticket->subject}).",
                    null,
                    $client->user_id
                );
                $project->logActivity(
                    'reopened',
                    "Project automatically reopened — {$client->name} raised support ticket #{$ticket->id} ({$ticket->subject}).",
                    $client->name,
                    ['from_status' => $fromStatus, 'auto_reopened_by_ticket_id' => $ticket->id]
                );
                $staffNote = " Project \"{$project->name}\" was automatically reopened because of this ticket.";
            } elseif ($project->status === 'approved_locked') {
                $staffNote = " Project \"{$project->name}\" is locked (Approved & Locked) — you may need to reopen it manually before working on this ticket.";
            }
        }

        $this->notifyStaff($ticket, "New support ticket: {$ticket->subject}", $client->name . ' raised a new support ticket.' . ($staffNote ?? ''));
        SupportNotificationService::notifyClientOnCreate($ticket);

        // "Critical Support Ticket" — a high/urgent ticket escalates by email
        // to the same staff the bell notification above reached, plus every
        // Company Admin. supportTicketCritical() itself no-ops for a
        // low/medium ticket, so ordinary tickets stay in-app only.
        EventMailService::supportTicketCritical($ticket, $client->name, array_merge(
            $this->staffRecipients($ticket),
            EventMailService::companyAdminRecipients($ticket->company_id),
        ));

        return ApiResponse::success($ticket, 'Support ticket raised successfully', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->permittedClient($request, 'support');

        $ticket = SupportTicket::where('id', $id)
            ->where('raised_by', $request->user()->id)
            ->with([
                'raisedBy:id,name',
                'assignedTo:id,name',
                'project:id,name,status',
                'invoice:id,invoice_number,total_amount,status',
            ])
            ->firstOrFail();

        $replies = SupportTicketReply::where('ticket_id', $id)
            ->with(['repliedBy:id,name,role_type', 'repliedByAdmin:id,name'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success([
            'ticket'  => $ticket,
            'replies' => $replies,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $client = $this->permittedClient($request, 'support');

        $request->validate([
            'message'    => 'nullable|required_without:attachment|string|max:5000',
            'attachment' => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($client->company_id, 10240)],
        ]);

        $ticket = SupportTicket::where('id', $id)
            ->where('raised_by', $request->user()->id)
            ->firstOrFail();

        if (in_array($ticket->status, ['resolved', 'closed'])) {
            return ApiResponse::error('This ticket is closed. Please raise a new ticket.', 422);
        }

        $attachmentName = null;
        $storageFields  = [];
        if ($request->hasFile('attachment')) {
            $file   = $request->file('attachment');
            $stored = AttachmentStorageService::store($file, $client->company_id, 'support', 'support-attachments', 'public');
            if (!$stored) {
                return AttachmentStorageService::errorResponse($client->company_id);
            }
            $attachmentName = $stored->originalName;
            // SupportTicketReply's own columns are attachment_path/
            // attachment_size, not locationAttributes()'s file_path — mapped
            // explicitly rather than array_merge()'d, since Eloquent would
            // otherwise silently drop the mismatched key with no error.
            $storageFields = [
                'storage_type'           => $stored->storageType,
                'attachment_path'        => $stored->filePath,
                'attachment_size'        => $stored->sizeBytes,
                'google_drive_file_id'   => $stored->googleDriveFileId,
                'google_drive_folder_id' => $stored->googleDriveFolderId,
            ];
        }

        $reply = SupportTicketReply::create(array_merge($storageFields, [
            'ticket_id'       => $id,
            'replied_by'      => $request->user()->id,
            'message'         => $request->message,
            'attachment_name' => $attachmentName,
        ]));

        $this->notifyStaff($ticket, "New reply on ticket: {$ticket->subject}", $client->name . ': ' . Str::limit($request->message, 120));

        return ApiResponse::success($reply, 'Reply added');
    }

    // GET /client/support/{id}/attachment — the ticket's own (description-
    // time) attachment. Same company-isolated ownership check as show().
    public function downloadAttachment(Request $request, int $id): StreamedResponse
    {
        $client = $this->permittedClient($request, 'support');

        $ticket = SupportTicket::where('id', $id)
            ->where('raised_by', $request->user()->id)
            ->firstOrFail();

        if (!$ticket->attachment_path && $ticket->storage_type !== 'google_drive') {
            abort(404, 'Attachment not found');
        }

        return AttachmentStorageService::download(
            $ticket->storage_type ?? 'internal', $ticket->attachment_path, $ticket->attachment_name ?? 'attachment',
            null, $ticket->google_drive_file_id, $client->company_id, 'public'
        );
    }

    // GET /client/support/{id}/replies/{replyId}/attachment
    public function downloadReplyAttachment(Request $request, int $id, int $replyId): StreamedResponse
    {
        $client = $this->permittedClient($request, 'support');

        $ticket = SupportTicket::where('id', $id)
            ->where('raised_by', $request->user()->id)
            ->firstOrFail();

        $reply = SupportTicketReply::where('ticket_id', $ticket->id)->findOrFail($replyId);

        if (!$reply->attachment_path && $reply->storage_type !== 'google_drive') {
            abort(404, 'Attachment not found');
        }

        return AttachmentStorageService::download(
            $reply->storage_type ?? 'internal', $reply->attachment_path, $reply->attachment_name ?? 'attachment',
            null, $reply->google_drive_file_id, $client->company_id, 'public'
        );
    }

    // Notify the assigned staff member if the ticket has one, otherwise every
    // staff member holding canManageClientSupport — plus every Company Admin
    // (admins always see it via their notification bell, no permission needed).
    // The ticket's own assignee if it has one, else every staff member
    // holding canManageClientSupport — shared by notifyStaff() and the
    // critical-ticket email so the two never target different people.
    private function staffRecipients(SupportTicket $ticket): array
    {
        if ($ticket->assigned_to) {
            return [['user_id' => $ticket->assigned_to]];
        }

        return UserCompanyPermission::where('company_id', $ticket->company_id)
            ->where('module_key', 'client')
            ->where('permission_key', 'canManageClientSupport')
            ->pluck('user_id')
            ->unique()
            ->map(fn ($uid) => ['user_id' => $uid])
            ->values()
            ->all();
    }

    private function notifyStaff(SupportTicket $ticket, string $title, string $body): void
    {
        $recipients = collect($this->staffRecipients($ticket));

        NotificationService::sendToMany($recipients->all(), [
            'company_id'  => $ticket->company_id,
            'type'        => 'support_ticket',
            'module'      => 'client',
            'title'       => $title,
            'message'     => $body,
            'entity_type' => 'SupportTicket',
            'entity_id'   => $ticket->id,
            'url'         => "/support/{$ticket->id}",
        ]);

        NotificationService::notifyCompanyAdmins($ticket->company_id, null, [
            'type'        => 'support_ticket',
            'module'      => 'client',
            'title'       => $title,
            'message'     => $body,
            'entity_type' => 'SupportTicket',
            'entity_id'   => $ticket->id,
            'url'         => "/admin/support/{$ticket->id}",
        ]);
    }
}
