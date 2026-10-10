<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\CompanyUserAssignment;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use App\Services\SupportNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    // canViewClientSupport/canManageClientSupport are granted under the
    // 'client' catalog module (see App\Services\ModuleCatalog) alongside
    // canViewClients/canManageClientAccess etc.
    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'client')
            ->where('permission_key', $permKey)
            ->exists();
    }

    private function ticket(int $id): SupportTicket
    {
        return SupportTicket::where('company_id', $this->user()->company_id)->findOrFail($id);
    }

    // GET /user/support
    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewClientSupport') && !$this->can('canManageClientSupport')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $q = SupportTicket::where('company_id', $this->user()->company_id)
            ->with(['raisedBy:id,name', 'assignedTo:id,name', 'project:id,name']);

        if ($request->filled('status'))     $q->where('status', $request->status);
        if ($request->filled('category'))   $q->where('category', $request->category);
        if ($request->filled('priority'))   $q->where('priority', $request->priority);
        if ($request->filled('project_id')) $q->where('project_id', $request->project_id);
        if ($request->filled('search'))     $q->where('subject', 'like', '%' . $request->search . '%');

        $tickets = $q->orderByDesc('created_at')
            ->get(['id', 'company_id', 'raised_by', 'assigned_to', 'subject', 'category', 'status', 'priority', 'project_id', 'created_at', 'resolved_at']);

        return ApiResponse::success($tickets);
    }

    // GET /user/support/{id}
    public function show(int $id): JsonResponse
    {
        if (!$this->can('canViewClientSupport') && !$this->can('canManageClientSupport')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $ticket = $this->ticket($id)->load([
            'raisedBy:id,name', 'assignedTo:id,name',
            'project:id,name,status', 'invoice:id,invoice_number,total_amount,status',
        ]);

        $replies = SupportTicketReply::where('ticket_id', $id)
            ->with(['repliedBy:id,name,role_type', 'repliedByAdmin:id,name'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success([
            'ticket'  => $ticket,
            'replies' => $replies,
        ]);
    }

    // POST /user/support/{id}/reply
    public function reply(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageClientSupport')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $ticket = $this->ticket($id);

        $request->validate([
            'message'    => 'nullable|required_without:attachment|string|max:5000',
            'attachment' => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($ticket->company_id, 10240)],
        ]);

        $attachmentName = null;
        $storageFields  = [];
        if ($request->hasFile('attachment')) {
            $file   = $request->file('attachment');
            $stored = AttachmentStorageService::store($file, $ticket->company_id, 'support', 'support-attachments', 'public');
            if (!$stored) {
                return AttachmentStorageService::errorResponse($ticket->company_id);
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
            'replied_by'      => $this->user()->id,
            'message'         => $request->message,
            'attachment_name' => $attachmentName,
        ]));

        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        SupportNotificationService::notifyClientOnReply($ticket, $reply, $this->user()->name ?? 'Support Team');

        return ApiResponse::success($reply, 'Reply added');
    }

    // GET /user/support/{id}/attachment — the ticket's own (description-time)
    // attachment.
    public function downloadAttachment(int $id): StreamedResponse
    {
        $ticket = $this->ticket($id);

        if (!$this->can('canViewClientSupport') && !$this->can('canManageClientSupport')) {
            abort(403, 'Permission denied');
        }

        if (!$ticket->attachment_path && $ticket->storage_type !== 'google_drive') {
            abort(404, 'Attachment not found');
        }

        return AttachmentStorageService::download(
            $ticket->storage_type ?? 'internal', $ticket->attachment_path, $ticket->attachment_name ?? 'attachment',
            null, $ticket->google_drive_file_id, $ticket->company_id, 'public'
        );
    }

    // GET /user/support/{id}/replies/{replyId}/attachment
    public function downloadReplyAttachment(int $id, int $replyId): StreamedResponse
    {
        $ticket = $this->ticket($id);

        if (!$this->can('canViewClientSupport') && !$this->can('canManageClientSupport')) {
            abort(403, 'Permission denied');
        }

        $reply = SupportTicketReply::where('ticket_id', $ticket->id)->findOrFail($replyId);

        if (!$reply->attachment_path && $reply->storage_type !== 'google_drive') {
            abort(404, 'Attachment not found');
        }

        return AttachmentStorageService::download(
            $reply->storage_type ?? 'internal', $reply->attachment_path, $reply->attachment_name ?? 'attachment',
            null, $reply->google_drive_file_id, $ticket->company_id, 'public'
        );
    }

    // PATCH /user/support/{id}/assign
    public function assign(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageClientSupport')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $ticket = $this->ticket($id);

        $validated = $request->validate(['user_id' => 'nullable|integer']);

        if (!empty($validated['user_id'])) {
            // Checking the static users.company_id column here would reject
            // any multi-company staff member whose primary company differs
            // from this ticket's — even though they're a genuine, active
            // member of it via company_user_assignments. Validate against
            // that instead (same fix as Admin\SupportController::assign()).
            $exists = CompanyUserAssignment::where('user_id', $validated['user_id'])
                ->where('company_id', $ticket->company_id)
                ->where('status', 'active')
                ->exists();
            if (!$exists) return ApiResponse::error('Selected user is not part of this company.', 422);
        }

        $ticket->update(['assigned_to' => $validated['user_id'] ?? null]);

        if (!empty($validated['user_id'])) {
            SupportNotificationService::notifyAssignee($ticket, (int) $validated['user_id'], $this->user()->name ?? 'Support Team', null, $this->user()->id);
        }

        return ApiResponse::success($ticket->fresh(['assignedTo:id,name']), 'Ticket assignment updated');
    }

    // PATCH /user/support/{id}/status
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageClientSupport')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $ticket = $this->ticket($id);

        $validated = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)]]);

        $ticket->update([
            'status'      => $validated['status'],
            'resolved_at' => in_array($validated['status'], ['resolved', 'closed']) ? now() : null,
        ]);

        SupportNotificationService::notifyClientOnStatusChange($ticket, $validated['status'], $this->user()->name ?? 'Support Team');

        return ApiResponse::success($ticket->fresh(), 'Ticket status updated');
    }
}
