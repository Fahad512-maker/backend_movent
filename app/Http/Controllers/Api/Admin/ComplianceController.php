<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CompanyPaymentGateway;
use App\Models\ComplianceActivityLog;
use App\Models\ComplianceCase;
use App\Models\ComplianceChecklistItem;
use App\Models\ComplianceComment;
use App\Models\ComplianceDocument;
use App\Models\ComplianceDocumentVersion;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceReview;
use App\Models\ComplianceTemplate;
use App\Models\ComplianceTemplateRequirement;
use App\Models\Deliverable;
use App\Services\AttachmentStorageService;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentReceipt;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAttachment;
use App\Models\ProjectComment;
use App\Models\ProjectDeliverySubmission;
use App\Models\ProjectTaskAttachment;
use App\Models\ProjectTeamMember;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ComplianceActivityLogService;
use App\Services\ComplianceCaseService;
use App\Services\ComplianceCaseStatusService;
use App\Services\EventMailService;
use App\Services\NotificationService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Phase 1: case/requirement/template CRUD + officer assignment. Phase 2
// (this file): document upload/versioning/review, case-level status
// actions, checklist, comments, activity log, dashboard, clients rollup.
// Company Admin has full Compliance access by default (structural — the
// `auth:admin` guard already bypasses every sub-user permission check, same
// as every other Admin controller in this codebase), so no inline can()
// checks here.
class ComplianceController extends Controller
{
    use ScopesToActiveCompany;

    private const DOC_MAX_KB = 10240;
    private const DOC_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin() { return auth('admin')->user(); }
    private function adminName(): string { return $this->admin()->name ?? 'Admin'; }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Compliance Officer (if assigned) is notified first; otherwise every
    // Company Admin is — mirrors ComplianceCaseService's own no-template
    // notification, just reusable for every Phase 2 mutation.
    private function notifyCase(ComplianceCase $case, string $type, string $title, string $message, ?string $documentName = null): void
    {
        // Email counterpart, same recipient rule as the branches below.
        // complianceDocument() only acts on the rejected/approved types, so
        // uploaded/resubmitted/expiry-set/case-status stay in-app only.
        if ($documentName) {
            EventMailService::complianceDocument(
                $case, $type, $documentName, $message,
                $case->compliance_officer_id
                    ? [['user_id' => $case->compliance_officer_id]]
                    : EventMailService::companyAdminRecipients($case->company_id),
            );
        }

        if ($case->compliance_officer_id) {
            NotificationService::send([
                'company_id'         => $case->company_id,
                'recipient_user_id'  => $case->compliance_officer_id,
                'actor_admin_id'     => $this->admin()->id,
                'module'             => 'compliance',
                'type'               => $type,
                'title'              => $title,
                'message'            => $message,
                'entity_type'        => 'ComplianceCase',
                'entity_id'          => $case->id,
                'url'                => "/compliance/projects/{$case->project_id}",
            ]);
        } else {
            NotificationService::notifyCompanyAdmins($case->company_id, $this->admin()->id, [
                'module'      => 'compliance',
                'type'        => $type,
                'title'       => $title,
                'message'     => $message,
                'entity_type' => 'ComplianceCase',
                'entity_id'   => $case->id,
                'url'         => "/admin/compliance/projects/{$case->project_id}",
            ]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId();

        $query = ComplianceCase::with([
            'project:id,name,reference,status,priority,project_manager_id,seller_id',
            'project.projectManager:id,name',
            'project.seller:id,name',
            'client:id,name',
            'complianceOfficer:id,name',
        ])
            ->where('company_id', $companyId)
            ->withCount('requirements');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($officerId = $request->input('compliance_officer_id')) {
            $query->where('compliance_officer_id', $officerId);
        }
        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('project', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $cases = $query->orderByDesc('id')->paginate($request->input('per_page', 20));

        // Project Attachments / Task Attachments / Team columns — separate
        // counts (NOT Compliance Documents, which already has its own
        // Download column) — see the User-guard mirror of this method for
        // the same grouped-count approach.
        $projectIds = collect($cases->items())->pluck('project_id');
        $projectAttachmentCounts = ProjectAttachment::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');
        $taskAttachmentCounts = ProjectTaskAttachment::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');
        $teamCounts = ProjectTeamMember::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        // Client Attachments — see the User-guard mirror of this method, and
        // isClientMessage()'s docblock, for the full design note (files the
        // client themselves sent in chat; a client sender is EITHER a
        // sender_id -> users.role_type='client' row, OR a guest_sender_name
        // carried over from a pre-project Sales chat by the
        // backfill_migrate_orphaned_sales_chat_to_projects migration).
        $clientAttachmentCounts = DB::table('chat_messages')
            ->join('chat_threads', 'chat_threads.id', '=', 'chat_messages.thread_id')
            ->leftJoin('users', 'users.id', '=', 'chat_messages.sender_id')
            ->where('chat_threads.linked_to_type', 'Project')
            ->whereIn('chat_threads.linked_to_id', $projectIds)
            ->whereIn('chat_threads.thread_type', ['project_client', 'project_group'])
            ->where(function ($q) {
                $q->where('users.role_type', 'client')->orWhereNotNull('chat_messages.guest_sender_name');
            })
            ->whereNotNull('chat_messages.attachment_path')
            ->where('chat_messages.is_deleted', false)
            ->selectRaw('chat_threads.linked_to_id as project_id, count(*) as c')
            ->groupBy('chat_threads.linked_to_id')
            ->pluck('c', 'project_id');

        // Payment receipts billed under each project — the other half of the
        // Client Files column, counted separately from chat attachments so
        // each number stays honest about what it counts and the frontend can
        // say which is which.
        $clientReceiptCounts = PaymentReceipt::join('invoices', 'invoices.id', '=', 'payment_receipts.invoice_id')
            ->whereIn('invoices.project_id', $projectIds)
            ->where('payment_receipts.company_id', $companyId)
            ->selectRaw('invoices.project_id as project_id, count(*) as c')
            ->groupBy('invoices.project_id')
            ->pluck('c', 'project_id');

        $invoiceCounts = Invoice::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        $finalDeliveryCounts = ProjectDeliverySubmission::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        // Comments column = general Project Comments (ProjectComment,
        // task_id null) — see the User-guard mirror of this method for the
        // full design note.
        $commentCounts = ProjectComment::whereIn('project_id', $projectIds)
            ->whereNull('task_id')
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        $cases->getCollection()->transform(function ($case) use ($projectAttachmentCounts, $taskAttachmentCounts, $teamCounts, $clientAttachmentCounts, $clientReceiptCounts, $invoiceCounts, $finalDeliveryCounts, $commentCounts) {
            $case->project_attachments_count = (int) ($projectAttachmentCounts[$case->project_id] ?? 0);
            $case->task_attachments_count = (int) ($taskAttachmentCounts[$case->project_id] ?? 0);
            $case->team_count = (int) ($teamCounts[$case->project_id] ?? 0);
            $case->client_attachments_count = (int) ($clientAttachmentCounts[$case->project_id] ?? 0);
            $case->client_receipts_count = (int) ($clientReceiptCounts[$case->project_id] ?? 0);
            $case->invoices_count = (int) ($invoiceCounts[$case->project_id] ?? 0);
            $case->final_delivery_count = (int) ($finalDeliveryCounts[$case->project_id] ?? 0);
            $case->comments_count = (int) ($commentCounts[$case->project_id] ?? 0);
            return $case;
        });

        return ApiResponse::success($cases);
    }

    public function show(int $id): JsonResponse
    {
        $case = ComplianceCase::with([
            'project:id,name,reference,status,priority,client_id,project_manager_id,seller_id',
            'project.seller:id,name,email',
            'client:id,name,email,phone',
            'complianceOfficer:id,name,email',
            'template:id,name',
            'requirements',
            'documents.versions',
        ])->where('company_id', $this->activeCompanyId())->find($id);

        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        return ApiResponse::success($case);
    }

    public function showByProject(int $projectId): JsonResponse
    {
        $project = Project::where('company_id', $this->activeCompanyId())->find($projectId);
        if (!$project) {
            return ApiResponse::error('Project not found.', 404);
        }

        $case = ComplianceCase::where('project_id', $projectId)->first();

        // Projects created before the Compliance Module existed (or that
        // fell through a swallowed exception on their creation path) have no
        // row yet — self-heal on first view instead of 404ing and pointing
        // the admin at a console command they may not have access to. Same
        // idempotent, never-throws call the backfill command itself uses.
        if (!$case) {
            $case = ComplianceCaseService::ensureCaseForProject($project);
        }

        if (!$case) {
            return ApiResponse::error('Failed to create a Compliance Case for this project.', 500);
        }

        $case->load([
            'project:id,name,reference,status,priority,client_id,project_manager_id,seller_id',
            'project.seller:id,name,email',
            'client:id,name,email,phone',
            'complianceOfficer:id,name,email',
            'template:id,name',
            'requirements',
            'documents.versions',
        ]);

        return ApiResponse::success($case);
    }

    // ---- Read-only project context (Chat/Attachments/Tasks/Deliverables) --
    // Surfaces data owned by other features (client chat, attachments,
    // tasks, deliverables) inside the Compliance case view. Deliberately
    // NOT routed through those features' own controllers — this is a
    // read-only compliance-audit view, scoped only by company + project,
    // with none of their write actions.

    private function complianceProject(int $projectId): ?Project
    {
        return Project::where('company_id', $this->activeCompanyId())->find($projectId);
    }

    // "Client Attachments" needs BOTH kinds of client-originated message:
    // (1) a currently-authenticated Client Portal user, sender_id -> a users
    // row with role_type='client' (the live send path — see
    // ProjectMessengerController's own senderIsClient check), and
    // (2) a pre-project Sales chat message carried over by the
    // 2026_08_29_110000_backfill_migrate_orphaned_sales_chat_to_projects
    // migration, which only ever had guest_sender_name (no users row existed
    // yet at send time) and was never touched again. Checking role_type
    // alone misses every migrated (2) message — which is exactly why real
    // client-sent files were showing up in chat but not in this listing.
    private function isClientMessage(ChatMessage $m): bool
    {
        return $m->sender?->role_type === 'client' || $m->guest_sender_name !== null;
    }

    // Lightweight project summary (budget/dates/description/team) for the
    // "Project Name" popup on the listing.
    public function projectOverview(int $projectId): JsonResponse
    {
        $project = $this->complianceProject($projectId);
        if (!$project) {
            return ApiResponse::error('Project not found.', 404);
        }

        $project->load(['client:id,name,email,phone', 'projectManager:id,name', 'seller:id,name', 'company:id,name']);

        return ApiResponse::success($project);
    }

    // The project's chat history actually lives in one of TWO places
    // depending on which feature a given project has used: the dedicated
    // 'project_client' thread (Api\*\ProjectClientChatController), or —
    // far more commonly in practice — the single per-project 'project_group'
    // thread that Api\*\ProjectMessengerController reads/writes (see
    // App\Services\ProjectChatService::threadFor()). Merge both so this
    // read-only card (and its CSV export) is complete regardless of which
    // one was used. Deliberately NOT filtered to visibility='client' — this
    // is an internal Compliance/audit view (never shown to the actual
    // client), so seller/admin/team-only ('internal') messages must show
    // here too, not just the slice that was ever relayed to the client.
    private function clientChatMessages(int $projectId)
    {
        $companyId = $this->activeCompanyId();

        $clientThreadMessages = ChatThread::where('company_id', $companyId)
            ->where('thread_type', 'project_client')
            ->where('linked_to_type', 'Project')
            ->where('linked_to_id', $projectId)
            ->with(['messages' => fn ($q) => $q->where('is_deleted', false), 'messages.sender:id,name,role_type', 'messages.senderAdmin:id,name'])
            ->first()?->messages ?? collect();

        $groupThreadMessages = ChatThread::where('company_id', $companyId)
            ->where('thread_type', 'project_group')
            ->where('linked_to_type', 'Project')
            ->where('linked_to_id', $projectId)
            ->with(['messages' => fn ($q) => $q->where('is_deleted', false), 'messages.sender:id,name,role_type', 'messages.senderAdmin:id,name'])
            ->first()?->messages ?? collect();

        return $clientThreadMessages->concat($groupThreadMessages)->sortBy('sent_at')->values();
    }

    public function projectClientChat(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        return ApiResponse::success(['messages' => $this->clientChatMessages($projectId)]);
    }

    public function projectChatExport(Request $request, int $projectId): StreamedResponse
    {
        $project = $this->complianceProject($projectId);
        if (!$project) {
            abort(404, 'Project not found.');
        }

        $messages = $this->clientChatMessages($projectId);

        // Optional ?start_date=/?end_date= (Y-m-d) narrows the export to the
        // same range the Chat popup's date filter is showing.
        if ($startDate = $request->input('start_date')) {
            $from = \Carbon\Carbon::parse($startDate)->startOfDay();
            $messages = $messages->filter(fn ($m) => $m->sent_at && $m->sent_at->gte($from));
        }
        if ($endDate = $request->input('end_date')) {
            $to = \Carbon\Carbon::parse($endDate)->endOfDay();
            $messages = $messages->filter(fn ($m) => $m->sent_at && $m->sent_at->lte($to));
        }

        $callback = function () use ($messages) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Sender', 'Message', 'Attachment']);
            foreach ($messages as $m) {
                fputcsv($out, [
                    optional($m->sent_at)->format('Y-m-d H:i'),
                    $m->sender?->name ?? $m->senderAdmin?->name ?? $m->guest_sender_name ?? 'Unknown',
                    $m->content,
                    $m->attachment_name,
                ]);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, "chat-export-project-{$projectId}.csv", ['Content-Type' => 'text/csv']);
    }

    // Files the CLIENT themselves sent in the project's chat — see the
    // User-guard mirror of this method for the full design note.
    public function projectClientAttachments(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $attachments = $this->clientChatMessages($projectId)
            ->filter(fn ($m) => $this->isClientMessage($m) && $m->attachment_path)
            ->values();

        return ApiResponse::success($attachments);
    }

    /**
     * Payment receipts for everything billed under this project — the other
     * half of Client Files.
     *
     * A receipt is a client-originated artifact in the sense compliance cares
     * about: it is the record of what the client actually paid, generated from
     * the payment itself rather than uploaded by anyone. Listed here rather
     * than only under Invoices so a reviewer looking at what came from the
     * client's side sees the proof of payment in the same place as the files
     * they sent.
     *
     * Read-only. Receipts are produced by the payment pipeline
     * (InvoicePaymentService::applyToInvoice) and nothing here creates,
     * re-renders or deletes one.
     */
    public function projectClientReceipts(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $receipts = PaymentReceiptService::forProject($projectId, $this->activeCompanyId())
            ->map(fn ($r) => PaymentReceiptService::card($r, "/admin/compliance/client-receipts/{$r->id}/image"))
            ->values();

        return ApiResponse::success($receipts);
    }

    /**
     * One receipt's image, streamed inline for the Client Files thumbnail.
     *
     * Three things have to agree before a byte is sent: the receipt belongs to
     * the active company, its invoice is billed to a project, and that project
     * is one this company's compliance module can see. The receipt id is the
     * only part the caller supplies, and on its own it addresses nothing.
     */
    public function projectClientReceiptImage(int $receiptId): StreamedResponse
    {
        $receipt = PaymentReceipt::where('id', $receiptId)
            ->where('company_id', $this->activeCompanyId())
            ->with('invoice:id,project_id,company_id')
            ->first();

        abort_unless(
            $receipt
                && $receipt->invoice?->project_id
                && $this->complianceProject($receipt->invoice->project_id)
                && $receipt->file_path
                && Storage::exists($receipt->file_path),
            404,
            'Receipt not found.'
        );

        return Storage::response($receipt->file_path, $receipt->file_name, [
            'Content-Type'        => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="' . $receipt->file_name . '"',
            // Never let a shared cache hold a tenant's receipt.
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }

    public function projectClientAttachmentDownload(int $messageId): StreamedResponse
    {
        $message = ChatMessage::with('sender:id,role_type')->whereHas(
            'thread',
            fn ($q) => $q->where('company_id', $this->activeCompanyId())
                ->where('linked_to_type', 'Project')
                ->whereIn('thread_type', ['project_client', 'project_group'])
        )->find($messageId);

        if (!$message || !$this->isClientMessage($message) || !$message->attachment_path || !$this->complianceProject($message->thread->linked_to_id)) {
            abort(404, 'Attachment not found.');
        }

        return Storage::download($message->attachment_path, $message->attachment_name ?? basename($message->attachment_path));
    }

    // General Chat (Api\Admin\GeneralChatController) is company-wide, not
    // tied to any project — there is no project link in the schema to filter
    // it by. Shown here as a read-only oversight list (every thread + its
    // last message) of the WHOLE company's General Chat, same as Admin's own
    // /admin/chat index, so a compliance reviewer isn't limited to threads
    // they personally participate in.
    public function projectGeneralChat(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $companyId = $this->activeCompanyId();

        $threads = ChatThread::where('company_id', $companyId)
            ->whereIn('thread_type', ['direct', 'group'])
            ->with('participants.user:id,name,role_type')
            ->orderByDesc('last_message_at')
            ->get();

        $lastMessages = ChatMessage::whereIn('thread_id', $threads->pluck('id'))
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderByDesc('sent_at')
            ->get()
            ->groupBy('thread_id')
            ->map(fn ($msgs) => $msgs->first());

        $result = $threads->map(function ($thread) use ($lastMessages) {
            $last = $lastMessages->get($thread->id);

            return [
                'id'              => $thread->id,
                'thread_type'     => $thread->thread_type,
                'title'           => $thread->thread_type === 'group' ? $thread->title : $thread->participants->pluck('user.name')->filter()->join(' & '),
                'participants'    => $thread->participants->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name, 'role' => $p->user?->role_type])->values(),
                'last_message_at' => $thread->last_message_at,
                'last_message'    => $last ? [
                    'content'      => $last->content,
                    'message_type' => $last->message_type,
                    'sender_name'  => $last->senderAdmin?->name ?? $last->sender?->name ?? 'Someone',
                    'sent_at'      => $last->sent_at,
                ] : null,
            ];
        });

        return ApiResponse::success($result->values());
    }

    public function projectAttachments(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $attachments = ProjectAttachment::where('project_id', $projectId)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    // Every task's attachments for a project, in one flat list (the
    // "Task Attachments" popup on the listing) — see the User-guard mirror
    // of this method for the shared design notes.
    public function projectTaskAttachments(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $attachments = ProjectTaskAttachment::where('project_id', $projectId)
            ->with(['task:id,title', 'uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    public function projectAttachmentDownload(int $attachmentId): StreamedResponse
    {
        $attachment = ProjectAttachment::where('company_id', $this->activeCompanyId())->find($attachmentId);
        if (!$attachment) {
            abort(404, 'Attachment not found.');
        }

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }

    public function projectTasks(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $tasks = Task::where('project_id', $projectId)
            ->with('assignedTo:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'project_id', 'title', 'status', 'priority', 'due_date', 'assigned_to', 'progress']);

        return ApiResponse::success($tasks);
    }

    // Comments + status-change history for one task — expanded on-demand
    // under a task row in the Relevant Task Status card. Not routed through
    // Api\*\ProjectCommentController (its internal/seller/PM visibility
    // tiers are unrelated to compliance access) or Api\*\TaskController
    // (project-team-scoped) — same rationale as the other project-context
    // methods in this file.
    public function projectTaskDetail(int $taskId): JsonResponse
    {
        $task = Task::whereHas('project', fn ($q) => $q->where('company_id', $this->activeCompanyId()))->find($taskId);
        if (!$task) {
            return ApiResponse::error('Task not found.', 404);
        }

        $comments = ProjectComment::where('task_id', $taskId)
            ->with(['authorAdmin:id,name', 'authorUser:id,name'])
            ->orderBy('created_at')
            ->get();

        $attachments = $task->attachments()
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(['comments' => $comments, 'activities' => $task->activities, 'attachments' => $attachments]);
    }

    public function taskAttachmentDownload(int $attachmentId): StreamedResponse
    {
        $attachment = ProjectTaskAttachment::where('company_id', $this->activeCompanyId())->find($attachmentId);
        if (!$attachment || !$this->complianceProject($attachment->project_id)) {
            abort(404, 'Attachment not found.');
        }

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }

    public function projectDeliverables(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $deliverables = Deliverable::where('project_id', $projectId)
            ->with(['uploadedBy:id,name', 'task:id,title'])
            ->orderByDesc('id')
            ->get();

        $deliveryHistory = ProjectDeliverySubmission::where('project_id', $projectId)
            ->with('deliveredByAdmin:id,name')
            ->orderByDesc('delivered_at')
            ->get();

        return ApiResponse::success(['deliverables' => $deliverables, 'delivery_history' => $deliveryHistory]);
    }

    public function deliverySubmissionDownload(int $deliveryId): StreamedResponse
    {
        $delivery = ProjectDeliverySubmission::whereHas('project', fn ($q) => $q->where('company_id', $this->activeCompanyId()))->find($deliveryId);
        if (!$delivery) {
            abort(404, 'Delivery not found.');
        }

        return AttachmentStorageService::download(
            $delivery->storage_type, $delivery->file_path, $delivery->file_name ?? 'delivery.zip',
            null, $delivery->google_drive_file_id, $this->activeCompanyId()
        );
    }

    public function projectBilling(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $invoices = Invoice::where('project_id', $projectId)
            ->with(['payments' => fn ($q) => $q
                ->with('companyGateway:id,label,gateway')
                ->orderByDesc('payment_date')])
            ->orderByDesc('created_at')
            ->get([
                'id', 'invoice_number', 'status', 'currency', 'subtotal', 'tax_amount',
                'discount_amount', 'total_amount', 'paid_amount', 'due_date', 'sent_at', 'project_id',
            ]);

        $this->nameGatewayOnPayments($invoices);

        return ApiResponse::success($invoices);
    }

    // Plain-text summary of one invoice — see the User-guard mirror of this
    // method for the full design note (no PDF generator is wired up
    // anywhere in this app yet).
    public function invoiceDownload(int $invoiceId): StreamedResponse
    {
        $invoice = Invoice::with('payments.companyGateway')->find($invoiceId);
        if (!$invoice || !$this->complianceProject($invoice->project_id)) {
            abort(404, 'Invoice not found.');
        }

        $this->nameGatewayOnPayments(collect([$invoice]));

        $lines = [
            "Invoice: {$invoice->invoice_number}",
            "Status: {$invoice->status}",
            "Subtotal: {$invoice->currency} {$invoice->subtotal}",
            "Tax: {$invoice->currency} {$invoice->tax_amount}",
            "Discount: {$invoice->currency} {$invoice->discount_amount}",
            "Total: {$invoice->currency} {$invoice->total_amount}",
            "Paid: {$invoice->currency} {$invoice->paid_amount}",
        ];
        if ($invoice->due_date) {
            $lines[] = "Due Date: {$invoice->due_date}";
        }
        if ($invoice->sent_at) {
            $lines[] = 'Sent At: ' . optional($invoice->sent_at)->format('Y-m-d H:i');
        }
        $lines[] = '';
        $lines[] = 'Payments:';
        if ($invoice->payments->isEmpty()) {
            $lines[] = '  None recorded.';
        } else {
            foreach ($invoice->payments as $p) {
                $method = $p->gateway_name ? "{$p->method} ({$p->gateway_name})" : $p->method;
                $lines[] = "  {$p->currency} {$p->amount} — {$method} — " . optional($p->payment_date)->format('Y-m-d') . " — {$p->status}";
            }
        }

        $content = implode("\n", $lines);
        $fileName = $this->zipSafeName($invoice->invoice_number) . '.txt';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $fileName, ['Content-Type' => 'text/plain']);
    }

    /**
     * Stamp a display-ready `gateway_name` on every payment of the given
     * invoices — "via gateway" alone tells a reviewer nothing about which
     * account actually took the money. Prefers the configured account label,
     * falling back to the gateway type. Null for bank_transfer/cash/cheque/
     * card, which have no gateway account behind them.
     *
     * @param  \Illuminate\Support\Collection<int, Invoice>  $invoices
     */
    private function nameGatewayOnPayments($invoices): void
    {
        foreach ($invoices as $invoice) {
            foreach ($invoice->payments as $payment) {
                $payment->gateway_name = $payment->method === 'gateway'
                    ? ($payment->companyGateway?->label
                        ?? CompanyPaymentGateway::GATEWAYS[$payment->gateway]
                        ?? ($payment->gateway ? ucfirst($payment->gateway) : null))
                    : null;
                $payment->unsetRelation('companyGateway');
            }
        }
    }

    public function projectTeam(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $team = ProjectTeamMember::where('project_id', $projectId)
            ->with('user:id,name,email,role_type')
            ->get(['id', 'project_id', 'user_id', 'role_in_project']);

        return ApiResponse::success($team);
    }

    public function projectComments(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        // General project-level discussion only — task-scoped comments are
        // already surfaced under each task's "Comments & History" expand in
        // the Relevant Task Status card, no need to duplicate them here.
        $comments = ProjectComment::where('project_id', $projectId)
            ->whereNull('task_id')
            ->with(['authorAdmin:id,name', 'authorUser:id,name'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success($comments);
    }

    public function projectTimesheets(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $timesheets = Timesheet::whereHas('task', fn ($q) => $q->where('project_id', $projectId))
            ->with(['task:id,title', 'user:id,name'])
            ->orderByDesc('log_date')
            ->get();

        return ApiResponse::success($timesheets);
    }

    // Complete project timeline — created/updated/status changes/team/
    // delivery (ProjectActivity, already written by every project lifecycle
    // action in Api\*\ProjectController but never surfaced in any UI until
    // now), task status changes (TaskActivity, one row per task's own
    // history), and invoice payment events (SystemAuditLog — payments are
    // recorded there, not on ProjectActivity). Merged and sorted so this one
    // card answers "when was X created/changed" for the whole project
    // without hunting through each task's own expand.
    public function projectHistory(int $projectId): JsonResponse
    {
        if (!$this->complianceProject($projectId)) {
            return ApiResponse::error('Project not found.', 404);
        }

        $projectEvents = ProjectActivity::where('project_id', $projectId)
            ->get(['id', 'description', 'causer_name', 'created_at'])
            ->map(fn ($a) => [
                'id'          => 'project-' . $a->id,
                'source'      => 'Project',
                'description' => $a->description,
                'causer_name' => $a->causer_name,
                'created_at'  => $a->created_at,
            ]);

        $taskIds = Task::where('project_id', $projectId)->pluck('id');
        $taskEvents = TaskActivity::whereIn('task_id', $taskIds)
            ->get(['id', 'description', 'causer_name', 'created_at'])
            ->map(fn ($a) => [
                'id'          => 'task-' . $a->id,
                'source'      => 'Task',
                'description' => $a->description,
                'causer_name' => $a->causer_name,
                'created_at'  => $a->created_at,
            ]);

        $invoiceIds = Invoice::where('project_id', $projectId)->pluck('id');
        $invoiceEvents = SystemAuditLog::where('entity_type', 'Invoice')
            ->whereIn('entity_id', $invoiceIds)
            ->whereIn('action', ['invoice_payment_submitted', 'invoice_payment_confirmed', 'invoice_payment_rejected'])
            ->get(['id', 'action', 'new_values', 'created_at'])
            ->map(fn ($l) => [
                'id'          => 'invoice-' . $l->id,
                'source'      => 'Invoice',
                'description' => $l->new_values['preview'] ?? str_replace('_', ' ', $l->action),
                'causer_name' => null,
                'created_at'  => $l->created_at,
            ]);

        $timeline = $projectEvents->concat($taskEvents)->concat($invoiceEvents)
            ->sortByDesc('created_at')
            ->values();

        return ApiResponse::success($timeline);
    }

    public function projectLead(int $projectId): JsonResponse
    {
        $project = $this->complianceProject($projectId);
        if (!$project) {
            return ApiResponse::error('Project not found.', 404);
        }

        if (!$project->lead_id) {
            return ApiResponse::success(null);
        }

        $lead = Lead::where('company_id', $this->activeCompanyId())
            ->with(['assignedTo:id,name,email', 'followUps' => fn ($q) => $q->orderByDesc('scheduled_at'), 'followUps.assignedTo:id,name'])
            ->find($project->lead_id);

        return ApiResponse::success($lead);
    }

    public function assignOfficer(Request $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::where('company_id', $companyId)->find($id);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        // Validates directly against `users` (active, role_type='compliance',
        // tied to this company via primary company_id OR an active
        // CompanyUserAssignment) instead of hard-requiring a
        // company_user_assignments row — that table isn't reliably populated
        // for every Compliance User (same gap already worked around for
        // Sellers in ProjectSellerAssignmentService), which was silently
        // rejecting valid officers the dropdown itself had just offered.
        $validated = $request->validate([
            'compliance_officer_id' => [
                'nullable', 'integer',
                function ($attribute, $value, $fail) use ($companyId) {
                    $valid = User::where('id', $value)
                        ->where('is_active', true)
                        ->where('role_type', 'compliance')
                        ->where(function ($q) use ($companyId) {
                            $q->where('company_id', $companyId)
                                ->orWhereHas('companyAssignments', fn ($a) => $a
                                    ->where('company_id', $companyId)
                                    ->where('status', 'active'));
                        })
                        ->exists();
                    if (!$valid) {
                        $fail('The selected compliance officer is invalid.');
                    }
                },
            ],
        ]);

        $case->update([
            'compliance_officer_id'        => $validated['compliance_officer_id'] ?? null,
            'officer_assigned_at'          => now(),
            'officer_assigned_by_admin_id' => $this->admin()->id,
            'officer_assigned_by_user_id'  => null,
        ]);

        return ApiResponse::success($case->fresh(), 'Compliance officer updated.');
    }

    // ---- Templates ----------------------------------------------------

    public function templatesIndex(): JsonResponse
    {
        $templates = ComplianceTemplate::where('company_id', $this->activeCompanyId())
            ->where('is_active', true)
            ->withCount('requirements')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($templates);
    }

    public function templatesStore(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId();

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_default'  => ['nullable', 'boolean'],
        ]);

        $template = DB::transaction(function () use ($validated, $companyId) {
            $template = ComplianceTemplate::create([
                'company_id'           => $companyId,
                'name'                 => $validated['name'],
                'description'          => $validated['description'] ?? null,
                'is_default'           => false,
                'created_by_admin_id'  => $this->admin()->id,
            ]);

            if (!empty($validated['is_default'])) {
                $this->makeDefaultTemplate($template);
            }

            return $template;
        });

        return ApiResponse::success($template, 'Compliance template created', 201);
    }

    public function templatesUpdate(Request $request, int $id): JsonResponse
    {
        $template = ComplianceTemplate::where('company_id', $this->activeCompanyId())->find($id);
        if (!$template) {
            return ApiResponse::error('Template not found.', 404);
        }

        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $template->update($validated);

        return ApiResponse::success($template->fresh(), 'Template updated');
    }

    public function templatesDestroy(int $id): JsonResponse
    {
        $template = ComplianceTemplate::where('company_id', $this->activeCompanyId())->find($id);
        if (!$template) {
            return ApiResponse::error('Template not found.', 404);
        }

        $template->delete();

        return ApiResponse::success(null, 'Template deleted');
    }

    public function templatesSetDefault(int $id): JsonResponse
    {
        $template = ComplianceTemplate::where('company_id', $this->activeCompanyId())->find($id);
        if (!$template) {
            return ApiResponse::error('Template not found.', 404);
        }

        DB::transaction(fn () => $this->makeDefaultTemplate($template));

        return ApiResponse::success($template->fresh(), 'Default template updated');
    }

    private function makeDefaultTemplate(ComplianceTemplate $template): void
    {
        ComplianceTemplate::where('company_id', $template->company_id)
            ->where('id', '!=', $template->id)
            ->update(['is_default' => false]);

        $template->update(['is_default' => true]);
    }

    public function templateRequirementsStore(Request $request, int $templateId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $template = ComplianceTemplate::where('company_id', $companyId)->find($templateId);
        if (!$template) {
            return ApiResponse::error('Template not found.', 404);
        }

        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:150'],
            'description'            => ['nullable', 'string'],
            'category'               => ['nullable', 'string', 'max:60'],
            'is_mandatory'           => ['nullable', 'boolean'],
            'requires_expiry'        => ['nullable', 'boolean'],
            'default_validity_days'  => ['nullable', 'integer', 'min:1'],
            'sort_order'             => ['nullable', 'integer', 'min:0'],
        ]);

        $requirement = ComplianceTemplateRequirement::create([
            'company_id'  => $companyId,
            'template_id' => $template->id,
            ...$validated,
        ]);

        return ApiResponse::success($requirement, 'Template requirement added', 201);
    }

    public function templateRequirementsUpdate(Request $request, int $id): JsonResponse
    {
        $requirement = ComplianceTemplateRequirement::where('company_id', $this->activeCompanyId())->find($id);
        if (!$requirement) {
            return ApiResponse::error('Template requirement not found.', 404);
        }

        $validated = $request->validate([
            'name'                   => ['sometimes', 'required', 'string', 'max:150'],
            'description'            => ['nullable', 'string'],
            'category'               => ['nullable', 'string', 'max:60'],
            'is_mandatory'           => ['nullable', 'boolean'],
            'requires_expiry'        => ['nullable', 'boolean'],
            'default_validity_days'  => ['nullable', 'integer', 'min:1'],
            'sort_order'             => ['nullable', 'integer', 'min:0'],
        ]);

        $requirement->update($validated);

        return ApiResponse::success($requirement->fresh(), 'Template requirement updated');
    }

    public function templateRequirementsDestroy(int $id): JsonResponse
    {
        $requirement = ComplianceTemplateRequirement::where('company_id', $this->activeCompanyId())->find($id);
        if (!$requirement) {
            return ApiResponse::error('Template requirement not found.', 404);
        }

        $requirement->delete();

        return ApiResponse::success(null, 'Template requirement removed');
    }

    // ---- Per-project requirements --------------------------------------

    // Adding/editing/removing a per-project requirement never writes back to
    // the template it may have come from — see ComplianceRequirement's
    // template_requirement_id, which is provenance-only.
    public function requirementsStore(Request $request, int $caseId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::where('company_id', $companyId)->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'description'      => ['nullable', 'string'],
            'category'         => ['nullable', 'string', 'max:60'],
            'is_mandatory'     => ['nullable', 'boolean'],
            'requires_expiry'  => ['nullable', 'boolean'],
            'expires_at'       => ['nullable', 'date'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
        ]);

        $requirement = ComplianceRequirement::create([
            'company_id'         => $companyId,
            'project_id'         => $case->project_id,
            'compliance_case_id' => $case->id,
            'source'             => 'custom',
            'status'             => 'pending',
            'added_by_admin_id'  => $this->admin()->id,
            ...$validated,
        ]);

        ComplianceCaseStatusService::recompute($case->fresh());

        return ApiResponse::success($requirement, 'Requirement added', 201);
    }

    public function requirementsUpdate(Request $request, int $id): JsonResponse
    {
        $requirement = ComplianceRequirement::where('company_id', $this->activeCompanyId())->find($id);
        if (!$requirement) {
            return ApiResponse::error('Requirement not found.', 404);
        }

        $validated = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:150'],
            'description'      => ['nullable', 'string'],
            'category'         => ['nullable', 'string', 'max:60'],
            'is_mandatory'     => ['nullable', 'boolean'],
            'requires_expiry'  => ['nullable', 'boolean'],
            'expires_at'       => ['nullable', 'date'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
        ]);

        $requirement->update($validated);
        ComplianceCaseStatusService::recompute($requirement->complianceCase);

        return ApiResponse::success($requirement->fresh(), 'Requirement updated');
    }

    public function requirementsDestroy(int $id): JsonResponse
    {
        $requirement = ComplianceRequirement::where('company_id', $this->activeCompanyId())->find($id);
        if (!$requirement) {
            return ApiResponse::error('Requirement not found.', 404);
        }

        $case = $requirement->complianceCase;
        $requirement->delete();
        ComplianceCaseStatusService::recompute($case);

        return ApiResponse::success(null, 'Requirement removed');
    }

    // ---- Documents ------------------------------------------------------

    public function documentsStore(Request $request, int $caseId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::with('project')->where('company_id', $companyId)->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . self::DOC_MAX_KB, 'mimes:' . self::DOC_MIMES],
            'compliance_requirement_id' => [
                'nullable', 'integer',
                Rule::exists('compliance_requirements', 'id')->where('compliance_case_id', $case->id),
            ],
        ]);

        $file = $validated['file'];
        $folder = ($case->project->storage_folder ?: 'companies/' . $companyId . '/projects/' . $case->project_id) . '/compliance';
        $path = $file->store($folder);

        $document = ComplianceDocument::create([
            'company_id'                => $companyId,
            'project_id'                => $case->project_id,
            'compliance_case_id'        => $case->id,
            'compliance_requirement_id' => $validated['compliance_requirement_id'] ?? null,
            'uploaded_by_admin_id'      => $this->admin()->id,
            'original_name'             => $file->getClientOriginalName(),
            'file_name'                 => $file->hashName(),
            'file_path'                 => $path,
            'file_type'                 => $file->getClientMimeType(),
            'file_size'                 => $file->getSize(),
            'status'                    => 'pending_review',
        ]);

        ComplianceDocumentVersion::create([
            'company_id'              => $companyId,
            'compliance_document_id'  => $document->id,
            'version_number'          => 1,
            'file_name'               => $document->file_name,
            'file_path'               => $document->file_path,
            'file_type'               => $document->file_type,
            'file_size'               => $document->file_size,
            'uploaded_by_admin_id'    => $this->admin()->id,
            'is_current'              => true,
            'status'                  => 'pending_review',
        ]);

        if (!empty($validated['compliance_requirement_id'])) {
            ComplianceRequirement::where('id', $validated['compliance_requirement_id'])->update(['status' => 'submitted']);
        }

        ComplianceActivityLogService::log(
            $case, 'document_uploaded', "{$this->adminName()} uploaded \"{$document->original_name}\".",
            'ComplianceDocument', $document->id, null, null, $this->admin()->id, null
        );
        $this->notifyCase($case, 'compliance_document_uploaded', 'Compliance document uploaded', "{$this->adminName()} uploaded \"{$document->original_name}\".");

        ComplianceCaseStatusService::recompute($case->fresh());

        return ApiResponse::success($document->fresh('versions'), 'Document uploaded', 201);
    }

    // Resubmission after a reject/request_resubmission — never overwrites
    // history, see the compliance_document_versions migration comment.
    public function documentsAddVersion(Request $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $document = ComplianceDocument::with('complianceCase.project')->where('company_id', $companyId)->find($id);
        if (!$document) {
            return ApiResponse::error('Document not found.', 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . self::DOC_MAX_KB, 'mimes:' . self::DOC_MIMES],
        ]);

        $case = $document->complianceCase;
        $file = $validated['file'];
        $folder = ($case->project->storage_folder ?: 'companies/' . $companyId . '/projects/' . $case->project_id) . '/compliance';
        $path = $file->store($folder);
        $nextVersion = (int) $document->versions()->max('version_number') + 1;

        DB::transaction(function () use ($document, $file, $path, $nextVersion, $companyId) {
            $document->versions()->where('is_current', true)->update(['is_current' => false]);

            ComplianceDocumentVersion::create([
                'company_id'             => $companyId,
                'compliance_document_id' => $document->id,
                'version_number'         => $nextVersion,
                'file_name'              => $file->hashName(),
                'file_path'              => $path,
                'file_type'              => $file->getClientMimeType(),
                'file_size'              => $file->getSize(),
                'uploaded_by_admin_id'   => $this->admin()->id,
                'is_current'             => true,
                'status'                 => 'pending_review',
            ]);

            $document->update([
                'original_name' => $file->getClientOriginalName(),
                'file_name'     => $file->hashName(),
                'file_path'     => $path,
                'file_type'     => $file->getClientMimeType(),
                'file_size'     => $file->getSize(),
                'status'        => 'pending_review',
            ]);
        });

        if ($document->compliance_requirement_id) {
            ComplianceRequirement::where('id', $document->compliance_requirement_id)->update(['status' => 'submitted']);
        }

        ComplianceActivityLogService::log(
            $case, 'document_resubmitted', "{$this->adminName()} uploaded a new version (v{$nextVersion}) of \"{$document->original_name}\".",
            'ComplianceDocument', $document->id, null, null, $this->admin()->id, null
        );
        $this->notifyCase($case, 'compliance_document_resubmitted', 'Compliance document resubmitted', "A new version of \"{$document->original_name}\" was uploaded.");

        ComplianceCaseStatusService::recompute($case->fresh());

        return ApiResponse::success($document->fresh('versions'), 'New version uploaded');
    }

    public function documentsDownload(int $id): StreamedResponse
    {
        $document = ComplianceDocument::where('company_id', $this->activeCompanyId())->find($id);
        if (!$document) {
            abort(404, 'Document not found.');
        }

        return Storage::download($document->file_path, $document->original_name);
    }

    // Bundles EVERYTHING downloadable for one project: Compliance Documents,
    // Project Attachments, and Task Attachments — see the User-guard mirror
    // of this method for the shared design notes.
    public function projectDocumentsZip(int $projectId)
    {
        $project = $this->complianceProject($projectId);
        if (!$project) {
            abort(404, 'Project not found.');
        }

        $case = ComplianceCase::where('company_id', $this->activeCompanyId())->where('project_id', $projectId)->first();

        $items = $this->collectProjectZipItems($projectId, $case?->id);

        return $this->streamZip($items, $this->zipSafeName($project->name) . '-compliance-documents.zip', fn ($item) => $item->folder);
    }

    // Bundles every project's Compliance Documents + Project Attachments +
    // Task Attachments into one ZIP, grouped into a per-project folder.
    // Company Admin has no visibility scoping (sees the whole company),
    // unlike the User-guard mirror. Optional ?client_id= narrows this to
    // one client's projects.
    public function allProjectsZip(Request $request)
    {
        $query = ComplianceCase::with('project:id,name')->where('company_id', $this->activeCompanyId());
        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        $cases = $query->get();

        $items = collect();
        foreach ($cases as $case) {
            $projectName = $case->project?->name ? $this->zipSafeName($case->project->name) : "project-{$case->project_id}";
            foreach ($this->collectProjectZipItems($case->project_id, $case->id) as $item) {
                $item->folder = $projectName . '/' . $item->folder;
                $items->push($item);
            }
        }

        return $this->streamZip($items, 'all-compliance-documents.zip', fn ($item) => $item->folder);
    }

    // Shared by projectDocumentsZip()/allProjectsZip() — see the User-guard
    // mirror of this method for the shared design notes.
    private function collectProjectZipItems(int $projectId, ?int $caseId): \Illuminate\Support\Collection
    {
        $items = collect();

        $project = Project::with(['lead', 'client:id,name', 'projectManager:id,name', 'seller:id,name'])->find($projectId);

        if ($caseId) {
            foreach (ComplianceDocument::where('compliance_case_id', $caseId)->get() as $d) {
                $items->push((object) ['file_path' => $d->file_path, 'original_name' => $d->original_name, 'folder' => 'Compliance Documents']);
            }
        }
        foreach (ProjectAttachment::where('project_id', $projectId)->get() as $a) {
            $items->push((object) ['file_path' => $a->file_path, 'original_name' => $a->original_name, 'folder' => 'Project Attachments']);
        }
        foreach (ProjectTaskAttachment::where('project_id', $projectId)->get() as $a) {
            $items->push((object) ['file_path' => $a->file_path, 'original_name' => $a->original_name, 'folder' => 'Task Attachments']);
        }
        foreach (ProjectDeliverySubmission::where('project_id', $projectId)->get() as $d) {
            $items->push((object) ['file_path' => $d->file_path, 'original_name' => $d->file_name, 'folder' => 'Final Delivery']);
        }

        // Client Attachments — actual files the client themselves sent in
        // the project's chat (see isClientMessage()'s docblock).
        $clientMessages = $this->clientChatMessages($projectId);
        foreach ($clientMessages->filter(fn ($m) => $this->isClientMessage($m) && $m->attachment_path) as $m) {
            $items->push((object) [
                'file_path'     => $m->attachment_path,
                'original_name' => $m->attachment_name ?: basename($m->attachment_path),
                'folder'        => 'Client Attachments',
            ]);
        }

        // Chat transcript, Comments, Team — generated text files (not
        // stored uploads), added via the 'content' key streamZip() checks
        // for instead of reading from Storage.
        if ($clientMessages->isNotEmpty()) {
            $transcript = $clientMessages->map(function ($m) {
                $sender = $m->sender?->name ?? $m->senderAdmin?->name ?? $m->guest_sender_name ?? 'Unknown';
                $date = optional($m->sent_at)->format('Y-m-d H:i');
                $body = $m->content ?: ($m->attachment_name ? "[Attachment: {$m->attachment_name}]" : '');
                return "[{$date}] {$sender}: {$body}";
            })->implode("\n");
            $items->push((object) ['content' => $transcript, 'original_name' => 'chat-transcript.txt', 'folder' => 'Chat']);
        }

        // Comments = general Project Comments (ProjectComment, task_id
        // null) — see the User-guard mirror of this method for the full
        // design note.
        $comments = ProjectComment::where('project_id', $projectId)->whereNull('task_id')
            ->with(['authorAdmin:id,name', 'authorUser:id,name'])->orderBy('created_at')->get();
        if ($comments->isNotEmpty()) {
            $text = $comments->map(function ($c) {
                $author = $c->authorAdmin?->name ?? $c->authorUser?->name ?? 'Unknown';
                $date = optional($c->created_at)->format('Y-m-d H:i');
                return "[{$date}] {$author}:\n{$c->body}";
            })->implode("\n\n");
            $items->push((object) ['content' => $text, 'original_name' => 'comments.txt', 'folder' => 'Comments']);
        }

        $team = ProjectTeamMember::where('project_id', $projectId)->with('user:id,name,email')->get();
        if ($team->isNotEmpty()) {
            $text = $team->map(fn ($m) => trim(($m->user?->name ?? 'Unknown') . " — {$m->role_in_project}" . ($m->user?->email ? " ({$m->user->email})" : '')))->implode("\n");
            $items->push((object) ['content' => $text, 'original_name' => 'team.txt', 'folder' => 'Team']);
        }

        // Timesheet — logged via Task, not directly against the project, so
        // reached through Task::project_id rather than a direct project_id
        // column on Timesheet itself.
        $timesheets = Timesheet::whereHas('task', fn ($q) => $q->where('project_id', $projectId))
            ->with(['user:id,name', 'task:id,title'])->orderBy('log_date')->get();
        if ($timesheets->isNotEmpty()) {
            $text = $timesheets->map(fn ($t) => sprintf(
                '%s — %s — %s hrs — %s%s',
                optional($t->log_date)->format('Y-m-d'),
                $t->user?->name ?? 'Unknown',
                number_format((float) $t->hours_logged, 2),
                $t->task?->title ?? 'Untitled Task',
                $t->notes ? " — {$t->notes}" : ''
            ))->implode("\n");
            $totalHours = number_format((float) $timesheets->sum('hours_logged'), 2);
            $items->push((object) ['content' => "Total Hours: {$totalHours}\n\n{$text}", 'original_name' => 'timesheets.txt', 'folder' => 'Timesheet']);
        }

        $invoices = Invoice::where('project_id', $projectId)->orderByDesc('created_at')->get();
        if ($invoices->isNotEmpty()) {
            $text = $invoices->map(fn ($i) => "{$i->invoice_number} — {$i->status} — Total: {$i->currency} {$i->total_amount} — Paid: {$i->currency} {$i->paid_amount}"
                . ($i->due_date ? " — Due: {$i->due_date}" : ''))->implode("\n");
            $items->push((object) ['content' => $text, 'original_name' => 'invoices.txt', 'folder' => 'Invoices']);
        }

        // Lead — the original Sales lead this project was converted from, if
        // any (Project::lead_id is nullable — a project can also be created
        // directly against a Client with no Lead history at all).
        if ($project?->lead) {
            $lead = $project->lead;
            $lines = [
                "Name: {$lead->name}",
                'Email: ' . ($lead->email ?: '—'),
                'Phone: ' . ($lead->phone ?: '—'),
                'Company: ' . ($lead->company_name ?: '—'),
                'Source: ' . ($lead->source ?: '—'),
                "Status: {$lead->status}",
                'Priority: ' . ($lead->priority ?: '—'),
                'Estimated Value: ' . ($lead->estimated_value !== null ? number_format((float) $lead->estimated_value, 2) : '—'),
            ];
            if ($lead->deal_reference) {
                $lines = array_merge($lines, [
                    '',
                    "Deal Reference: {$lead->deal_reference}",
                    'Proposed Project Title: ' . ($lead->proposed_project_title ?: '—'),
                    'Service Category: ' . ($lead->service_category ?: '—'),
                    'Scope Summary: ' . ($lead->scope_summary ?: '—'),
                    'Quotation Reference: ' . ($lead->quotation_reference ?: '—'),
                    'Expected Start: ' . (optional($lead->expected_start_date)->format('Y-m-d') ?: '—'),
                    'Expected End: ' . (optional($lead->expected_end_date)->format('Y-m-d') ?: '—'),
                ]);
            }
            if ($lead->notes) {
                $lines = array_merge($lines, ['', 'Notes:', $lead->notes]);
            }
            $items->push((object) ['content' => implode("\n", $lines), 'original_name' => 'lead.txt', 'folder' => 'Lead']);
        }

        // Project Report — a single self-contained summary of the project
        // (status/dates/people/task breakdown), so the ZIP is understandable
        // without also having the app open.
        if ($project) {
            $taskCounts = Task::where('project_id', $projectId)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $deliverableCount = Deliverable::where('project_id', $projectId)->count();
            $teamCount = $team->count();

            $lines = [
                "Project: {$project->name}",
                'Reference: ' . ($project->reference ?: '—'),
                "Status: {$project->status}",
                'Priority: ' . ($project->priority ?: '—'),
                'Budget: ' . ($project->budget !== null ? number_format((float) $project->budget, 2) : '—'),
                'Start Date: ' . (optional($project->start_date)->format('Y-m-d') ?: '—'),
                'Deadline: ' . (optional($project->deadline)->format('Y-m-d') ?: '—'),
                'Client: ' . ($project->client?->name ?? '—'),
                'Project Manager: ' . ($project->projectManager?->name ?? '—'),
                'Seller: ' . ($project->seller?->name ?? '—'),
                '',
                'Description:',
                $project->description ?: '—',
                '',
                'Tasks: ' . $taskCounts->sum(),
            ];
            foreach ($taskCounts as $status => $count) {
                $lines[] = "  - {$status}: {$count}";
            }
            $lines[] = "Deliverables: {$deliverableCount}";
            $lines[] = "Team Members: {$teamCount}";
            $lines[] = 'Timesheet Hours: ' . number_format((float) $timesheets->sum('hours_logged'), 2);
            $lines[] = 'Invoices: ' . $invoices->count();
            $lines[] = 'Compliance Status: ' . ($caseId ? (ComplianceCase::find($caseId)?->status ?? '—') : '—');

            $items->push((object) ['content' => implode("\n", $lines), 'original_name' => 'project-report.txt', 'folder' => 'Project Report']);
        }

        return $items;
    }

    private function zipSafeName(string $name): string
    {
        return trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $name)) ?: 'untitled';
    }

    // Shared by projectDocumentsZip()/allProjectsZip() — see the User-guard
    // mirror of this method for the shared design notes.
    private function streamZip($documents, string $downloadName, ?callable $folderFor = null)
    {
        if ($documents->isEmpty()) {
            abort(404, 'No documents to download.');
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $zipPath = $tmpDir . '/' . uniqid('compliance-', true) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $usedNames = [];
        $added = 0;
        foreach ($documents as $document) {
            // Generated text (chat transcript / comments / team) carries its
            // own content instead of a Storage-backed file_path.
            $isGenerated = isset($document->content);
            if (!$isGenerated && (!$document->file_path || !Storage::exists($document->file_path))) {
                continue;
            }
            $folder = $folderFor ? $folderFor($document) . '/' : '';
            $baseName = $document->original_name ?: basename($document->file_path ?? 'file');
            $name = $folder . $baseName;
            $suffix = 1;
            while (in_array($name, $usedNames, true)) {
                $name = $folder . pathinfo($baseName, PATHINFO_FILENAME) . " ({$suffix})." . pathinfo($baseName, PATHINFO_EXTENSION);
                $suffix++;
            }
            $usedNames[] = $name;
            $zip->addFromString($name, $isGenerated ? $document->content : Storage::get($document->file_path));
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);
            abort(404, 'No documents to download.');
        }

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }

    public function documentsReview(Request $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $document = ComplianceDocument::with('complianceCase')->where('company_id', $companyId)->find($id);
        if (!$document) {
            return ApiResponse::error('Document not found.', 404);
        }

        $validated = $request->validate([
            'action'     => ['required', 'in:approve,reject,request_resubmission,set_expiry'],
            'reason'     => [Rule::requiredIf(in_array($request->input('action'), ['reject', 'request_resubmission'])), 'nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $case = $document->complianceCase;
        $oldStatus = $document->status;
        $newStatus = match ($validated['action']) {
            'approve'              => 'approved',
            'reject'                => 'rejected',
            'request_resubmission'  => 'resubmission_requested',
            'set_expiry'             => $document->status,
        };

        $updates = ['status' => $newStatus];
        if ($validated['action'] === 'set_expiry') {
            $updates['expires_at'] = $validated['expires_at'] ?? null;
        }
        $document->update($updates);

        if ($document->compliance_requirement_id) {
            $reqUpdates = $validated['action'] === 'set_expiry'
                ? ['expires_at' => $validated['expires_at'] ?? null]
                : ['status' => $newStatus];
            ComplianceRequirement::where('id', $document->compliance_requirement_id)->update($reqUpdates);
        }

        ComplianceReview::create([
            'company_id'                => $companyId,
            'project_id'                => $case->project_id,
            'compliance_case_id'        => $case->id,
            'compliance_requirement_id' => $document->compliance_requirement_id,
            'compliance_document_id'    => $document->id,
            'action'                    => $validated['action'],
            'reason'                    => $validated['reason'] ?? null,
            'old_status'                => $oldStatus,
            'new_status'                => $newStatus,
            'performed_by_admin_id'     => $this->admin()->id,
        ]);

        ComplianceActivityLogService::log(
            $case, 'document_' . $validated['action'], "{$this->adminName()} marked \"{$document->original_name}\" as {$validated['action']}.",
            'ComplianceDocument', $document->id, ['status' => $oldStatus], ['status' => $newStatus], $this->admin()->id, null
        );

        $notifTypes = [
            'approve'              => 'compliance_document_approved',
            'reject'                => 'compliance_document_rejected',
            'request_resubmission'  => 'compliance_resubmission_requested',
            'set_expiry'             => 'compliance_document_expiry_set',
        ];
        $reasonSuffix = !empty($validated['reason']) ? ' Reason: ' . $validated['reason'] : '';
        $this->notifyCase($case, $notifTypes[$validated['action']], 'Compliance document ' . $validated['action'], "\"{$document->original_name}\" was {$validated['action']}.{$reasonSuffix}", $document->original_name);

        ComplianceCaseStatusService::recompute($case->fresh());

        return ApiResponse::success($document->fresh(['versions', 'reviews']), 'Document review updated');
    }

    // ---- Case-level status actions --------------------------------------

    // Distinct from documentsReview() above: mark_under_review/on_hold/
    // reject/resume operate on the CASE as a whole, not a single document —
    // matches the state machine ComplianceCaseStatusService already
    // implements (on_hold/rejected are manual-only, never auto-set/cleared).
    public function caseStatusUpdate(Request $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::where('company_id', $companyId)->find($id);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:mark_under_review,on_hold,resume,reject'],
            'reason' => [Rule::requiredIf(in_array($request->input('action'), ['on_hold', 'reject'])), 'nullable', 'string', 'max:1000'],
        ]);

        $oldStatus = $case->status;

        switch ($validated['action']) {
            case 'mark_under_review':
                $case->update(['status' => 'under_review', 'started_review_at' => now()]);
                break;
            case 'on_hold':
                $case->update(['status' => 'on_hold', 'on_hold_at' => now(), 'on_hold_reason' => $validated['reason']]);
                break;
            case 'reject':
                $case->update(['status' => 'rejected', 'rejected_at' => now(), 'rejected_reason' => $validated['reason']]);
                break;
            case 'resume':
                $case->update(['status' => 'pending']);
                ComplianceCaseStatusService::recompute($case->fresh());
                break;
        }

        $case = $case->fresh();
        $reasonSuffix = !empty($validated['reason']) ? ' Reason: ' . $validated['reason'] : '';
        ComplianceActivityLogService::log(
            $case, 'case_status_' . $validated['action'], "{$this->adminName()} set case status to {$validated['action']}.{$reasonSuffix}",
            'ComplianceCase', $case->id, ['status' => $oldStatus], ['status' => $case->status], $this->admin()->id, null
        );
        $this->notifyCase($case, 'compliance_status_changed', 'Compliance status changed', "Compliance case status changed to {$case->status}.{$reasonSuffix}");

        return ApiResponse::success($case, 'Case status updated');
    }

    // ---- Checklist --------------------------------------------------------

    public function checklistIndex(int $caseId): JsonResponse
    {
        $case = ComplianceCase::where('company_id', $this->activeCompanyId())->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        return ApiResponse::success($case->checklistItems);
    }

    // Idempotent — skips any mandatory requirement that already has a linked
    // checklist item, so it's safe to call more than once as requirements
    // are added over time.
    public function checklistGenerate(int $caseId): JsonResponse
    {
        $case = ComplianceCase::with('requirements')->where('company_id', $this->activeCompanyId())->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $existingReqIds = $case->checklistItems()->whereNotNull('compliance_requirement_id')->pluck('compliance_requirement_id')->toArray();
        $created = 0;

        foreach ($case->requirements->where('is_mandatory', true) as $requirement) {
            if (in_array($requirement->id, $existingReqIds, true)) {
                continue;
            }
            ComplianceChecklistItem::create([
                'company_id'                => $case->company_id,
                'project_id'                => $case->project_id,
                'compliance_case_id'        => $case->id,
                'compliance_requirement_id' => $requirement->id,
                'label'                     => $requirement->name,
                'sort_order'                => $requirement->sort_order,
            ]);
            $created++;
        }

        ComplianceActivityLogService::log(
            $case, 'checklist_generated', "{$this->adminName()} generated {$created} checklist item(s) from requirements.",
            null, null, null, null, $this->admin()->id, null
        );

        return ApiResponse::success($case->fresh()->checklistItems, "{$created} checklist item(s) generated");
    }

    public function checklistStore(Request $request, int $caseId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::where('company_id', $companyId)->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $validated = $request->validate([
            'label'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $item = ComplianceChecklistItem::create([
            'company_id'         => $companyId,
            'project_id'         => $case->project_id,
            'compliance_case_id' => $case->id,
            ...$validated,
        ]);

        return ApiResponse::success($item, 'Checklist item added', 201);
    }

    public function checklistToggle(int $id): JsonResponse
    {
        $item = ComplianceChecklistItem::where('company_id', $this->activeCompanyId())->find($id);
        if (!$item) {
            return ApiResponse::error('Checklist item not found.', 404);
        }

        $checked = !$item->is_checked;
        $item->update([
            'is_checked'           => $checked,
            'checked_by_admin_id'  => $checked ? $this->admin()->id : null,
            'checked_by_user_id'   => null,
            'checked_at'           => $checked ? now() : null,
        ]);

        return ApiResponse::success($item->fresh(), 'Checklist item updated');
    }

    // ---- Comments -----------------------------------------------------

    public function commentsIndex(int $caseId): JsonResponse
    {
        $case = ComplianceCase::where('company_id', $this->activeCompanyId())->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        return ApiResponse::success($case->comments()->with(['authorAdmin:id,name', 'authorUser:id,name'])->get());
    }

    public function commentsStore(Request $request, int $caseId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $case = ComplianceCase::where('company_id', $companyId)->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = ComplianceComment::create([
            'company_id'         => $companyId,
            'project_id'         => $case->project_id,
            'compliance_case_id' => $case->id,
            'body'               => $validated['body'],
            'author_admin_id'    => $this->admin()->id,
        ]);

        ComplianceActivityLogService::log(
            $case, 'comment_added', "{$this->adminName()} added a comment.", 'ComplianceComment', $comment->id, null, null, $this->admin()->id, null
        );

        return ApiResponse::success($comment->load(['authorAdmin:id,name']), 'Comment added', 201);
    }

    public function commentsDestroy(int $id): JsonResponse
    {
        $comment = ComplianceComment::where('company_id', $this->activeCompanyId())->find($id);
        if (!$comment) {
            return ApiResponse::error('Comment not found.', 404);
        }

        $comment->delete();

        return ApiResponse::success(null, 'Comment removed');
    }

    // ---- Activity log ---------------------------------------------------

    public function activityIndex(int $caseId): JsonResponse
    {
        $case = ComplianceCase::where('company_id', $this->activeCompanyId())->find($caseId);
        if (!$case) {
            return ApiResponse::error('Compliance case not found.', 404);
        }

        $activity = ComplianceActivityLog::where('compliance_case_id', $caseId)
            ->with(['actorAdmin:id,name', 'actorUser:id,name'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return ApiResponse::success($activity);
    }

    // ---- Dashboard + Clients rollup -------------------------------------

    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $clientId = $request->input('client_id');
        $scoped = fn ($query) => $clientId ? $query->where('client_id', $clientId) : $query;

        $counts = $scoped(ComplianceCase::where('company_id', $companyId))
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $statuses = ['not_started', 'pending', 'under_review', 'compliant', 'on_hold', 'rejected'];
        $byStatus = collect($statuses)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]);

        return ApiResponse::success([
            'total'              => $scoped(ComplianceCase::where('company_id', $companyId))->count(),
            'by_status'          => $byStatus,
            'unassigned_officer' => $scoped(ComplianceCase::where('company_id', $companyId))->whereNull('compliance_officer_id')->count(),
        ]);
    }

    public function clientsIndex(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId();

        $query = Client::where('company_id', $companyId);
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        $clients = $query->get(['id', 'name', 'email', 'phone']);

        $caseCounts = ComplianceCase::where('company_id', $companyId)
            ->whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, status, count(*) as c')
            ->groupBy('client_id', 'status')
            ->get()
            ->groupBy('client_id');

        $result = $clients->map(function ($client) use ($caseCounts) {
            $rows = $caseCounts->get($client->id, collect());
            $byStatus = $rows->pluck('c', 'status');

            return [
                'id'            => $client->id,
                'name'          => $client->name,
                'email'         => $client->email,
                'phone'         => $client->phone,
                'total_projects' => (int) $rows->sum('c'),
                'compliant'     => (int) ($byStatus['compliant'] ?? 0),
                'pending'       => (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['not_started'] ?? 0),
                'under_review'  => (int) ($byStatus['under_review'] ?? 0),
                'on_hold'       => (int) ($byStatus['on_hold'] ?? 0),
                'rejected'      => (int) ($byStatus['rejected'] ?? 0),
            ];
        });

        if ($status = $request->input('status')) {
            $result = $result->filter(fn ($r) => ($r[$status] ?? 0) > 0)->values();
        }

        return ApiResponse::success($result->values());
    }

    public function clientShow(int $clientId): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $client = Client::where('company_id', $companyId)->find($clientId);
        if (!$client) {
            return ApiResponse::error('Client not found.', 404);
        }

        $projects = Project::where('company_id', $companyId)->where('client_id', $clientId)
            ->with(['complianceCase.complianceOfficer:id,name', 'complianceCase.requirements', 'projectManager:id,name', 'seller:id,name'])
            ->get(['id', 'name', 'reference', 'status', 'project_manager_id', 'seller_id', 'client_id', 'deadline']);

        $projectsData = $projects->map(function ($p) {
            $case = $p->complianceCase;
            $reqs = $case?->requirements ?? collect();

            return [
                'id'                     => $p->id,
                'name'                   => $p->name,
                'reference'              => $p->reference,
                'status'                 => $p->status,
                'project_manager'        => $p->projectManager ? ['id' => $p->projectManager->id, 'name' => $p->projectManager->name] : null,
                'seller'                 => $p->seller ? ['id' => $p->seller->id, 'name' => $p->seller->name] : null,
                'compliance_status'      => $case?->status ?? 'not_started',
                'compliance_officer'     => $case?->complianceOfficer ? ['id' => $case->complianceOfficer->id, 'name' => $case->complianceOfficer->name] : null,
                'requirements_total'     => $reqs->count(),
                'requirements_approved'  => $reqs->where('status', 'approved')->count(),
                'requirements_pending'   => $reqs->whereIn('status', ['pending', 'submitted'])->count(),
                'requirements_rejected'  => $reqs->where('status', 'rejected')->count(),
                'deadline'               => $p->deadline,
            ];
        });

        return ApiResponse::success(['client' => $client, 'projects' => $projectsData]);
    }
}
