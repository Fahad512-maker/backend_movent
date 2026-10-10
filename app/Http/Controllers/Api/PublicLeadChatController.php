<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Services\AttachmentStorageService;
use App\Services\SalesConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Lead-facing Sales Chat — the page behind the "Start Conversation" button
 * in the invite email (App\Mail\LeadSalesChatInviteMail), open with no login
 * at all. Sibling of Api\PublicInvoiceChatController, which does the same for
 * the public invoice payment page.
 *
 * WHAT THE LEAD CAN REACH, AND WHY IT IS ONLY THAT
 *
 * The URL carries leads.chat_token and nothing else — no lead id, so there is
 * no identifier to increment and no authorization decision that could be
 * bypassed by guessing one. Every endpoint here re-derives lead -> thread from
 * the token on each request rather than trusting anything the client sends:
 * even the per-message endpoints take the message id only AFTER the thread has
 * been resolved from the token, and scope the lookup to that thread, so an id
 * belonging to another lead's conversation is a 404 here.
 *
 * From that token the Lead reaches exactly one conversation and nothing else.
 * There is no endpoint on this controller for invoices, projects, clients,
 * other leads or any CRM data, and the payload deliberately carries no
 * internal ids (thread_id, lead_id, sender_id) — only what the page renders.
 *
 * THE ONE SHARP EDGE: THREAD MIGRATION
 *
 * When this lead's invoice is paid, PaymentProjectStartService::
 * migrateChatHistory() drains the 'sales' thread into the project's
 * 'project_group' thread and leaves a forwarding pointer. That project thread
 * is SHARED between staff and client and carries an internal slice — staff
 * talking among themselves. So wherever this controller reads messages it
 * filters to visibility='client' on a project thread, exactly as
 * Api\PublicInvoiceChatController and Api\Client\ProjectChatController already
 * do. Following the pointer is what keeps the Lead's original link working
 * after the deal converts; the filter is what stops it turning into a window
 * onto internal chat.
 */
class PublicLeadChatController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB, same cap as every other chat surface
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    /**
     * Token -> Lead. A revoked link nulls the column, and `whereNotNull` is
     * what stops an empty/absent token matching every such row — without it,
     * a request for token "" would resolve to whichever revoked lead happened
     * to come first.
     */
    private function resolveLead(string $token): ?Lead
    {
        if ($token === '') {
            return null;
        }

        return Lead::whereNotNull('chat_token')
            ->where('chat_token', $token)
            ->with('assignedTo:id,name')
            ->first();
    }

    /**
     * This lead's live conversation, or null when nobody has said anything
     * yet. Read-only: merely opening the page must never bring a thread into
     * existence, the same existingThreadFor()-to-display /
     * threadFor()-to-send split Api\PublicInvoiceChatController makes.
     */
    private function existingThread(Lead $lead): ?ChatThread
    {
        $salesThread = ChatThread::where('thread_type', 'sales')
            ->where('linked_to_type', 'Lead')
            ->where('linked_to_id', $lead->id)
            ->first();

        if (!$salesThread) {
            return null;
        }

        return SalesConversationResolver::resolve($salesThread, 'Lead', $lead->id);
    }

    /** Messages of a thread, already narrowed to what a Lead may see. */
    private function visibleMessages(ChatThread $thread): array
    {
        $query = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false);

        // See the class docblock: a project thread carries an internal slice
        // that must never reach this unauthenticated page. A 'sales' thread
        // has no internal slice, so it needs no filter.
        if ($thread->thread_type === 'project_group') {
            $query->where('visibility', 'client');
        }

        return $query->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get()
            ->map(fn ($m) => $this->formatMessage($m))
            ->values()
            ->toArray();
    }

    // GET /public/sales-chat/{token}
    public function show(string $token): JsonResponse
    {
        $lead = $this->resolveLead($token);
        if (!$lead) {
            return ApiResponse::error('This conversation link is no longer valid.', 404);
        }

        $thread = $this->existingThread($lead);

        return ApiResponse::success([
            'lead_name'    => $lead->name,
            'company_name' => $this->companyName($lead),
            'seller_name'  => $lead->assignedTo?->name,
            'messages'     => $thread ? $this->visibleMessages($thread) : [],
        ]);
    }

    // POST /public/sales-chat/{token}/messages — throttled at the route level;
    // this is an unauthenticated write endpoint.
    public function store(Request $request, string $token): JsonResponse
    {
        $lead = $this->resolveLead($token);
        if (!$lead) {
            return ApiResponse::error('This conversation link is no longer valid.', 404);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'file'    => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($lead->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        // Identical firstOrCreate key Api\User\SalesChatController and
        // Api\Admin\SalesChatController use, so the Lead's first message lands
        // in the very thread the Seller already reads at
        // /leads/{id}?tab=chat — never a second, parallel conversation.
        // created_by stays null: a guest is not a `users` row.
        $salesThread = ChatThread::firstOrCreate(
            ['thread_type' => 'sales', 'linked_to_type' => 'Lead', 'linked_to_id' => $lead->id],
            ['company_id' => $lead->company_id, 'title' => $lead->name]
        );

        $thread = SalesConversationResolver::resolve($salesThread, 'Lead', $lead->id, create: true);

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            // Same 'public-chat' root Api\PublicInvoiceChatController uses, so
            // a guest upload is never confusable with one a logged-in staff
            // member made.
            $folder = 'companies/' . $lead->company_id . '/public-chat/lead-' . $lead->id;
            $stored = AttachmentStorageService::store($file, $lead->company_id, 'chat', $folder);
            if (!$stored) {
                return AttachmentStorageService::errorResponse($lead->company_id);
            }
            $attachmentName = $stored->originalName;
            $messageType    = str_starts_with($stored->mimeType ?? '', 'image/') ? 'image' : 'file';
            // ChatMessage's own columns are attachment_path/attachment_size,
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

        $message = ChatMessage::create(array_merge($storageFields, [
            'thread_id'         => $thread->id,
            // No sender_id/sender_admin_id: that null pair is what marks a
            // message as the guest's own, both for rendering and for the
            // ownership check in deleteMessage() below.
            'guest_sender_name' => $lead->name,
            'content'           => $validated['content'] ?? null,
            'message_type'      => $messageType,
            // Always the client-visible slice, so this message stays readable
            // if the conversation later migrates into a project thread.
            'visibility'        => 'client',
            'attachment_name'   => $attachmentName,
            'sent_at'           => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($lead, $thread, $message);

        return ApiResponse::success($this->formatMessage($message), 'Message sent', 201);
    }

    // GET /public/sales-chat/{token}/messages/{messageId}/attachment — the
    // thread is re-derived from the token rather than trusting messageId
    // alone, so an id guessed from a DIFFERENT lead's thread can never be
    // downloaded through this link.
    public function downloadAttachment(string $token, int $messageId): StreamedResponse|JsonResponse
    {
        $lead = $this->resolveLead($token);
        if (!$lead) {
            return ApiResponse::error('This conversation link is no longer valid.', 404);
        }

        $thread = $this->existingThread($lead);
        if (!$thread) {
            return ApiResponse::error('Attachment not found', 404);
        }

        $query = ChatMessage::where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->where('is_deleted', false)
            ->whereNotNull('attachment_path');

        // Same internal-slice guard as visibleMessages() — an attachment a
        // staff member posted internally must not be downloadable here just
        // because it shares a thread.
        if ($thread->thread_type === 'project_group') {
            $query->where('visibility', 'client');
        }

        $message = $query->first();
        if (!$message) {
            return ApiResponse::error('Attachment not found', 404);
        }

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $lead->company_id
        );
    }

    // DELETE /public/sales-chat/{token}/messages/{messageId} — the Lead may
    // only ever delete their OWN messages: sender_id and sender_admin_id are
    // both null exclusively on a message store() above created, so that pair
    // doubles as "belongs to the Lead, not staff". A Seller's reply can never
    // be touched from here.
    public function deleteMessage(string $token, int $messageId): JsonResponse
    {
        $lead = $this->resolveLead($token);
        if (!$lead) {
            return ApiResponse::error('This conversation link is no longer valid.', 404);
        }

        $thread = $this->existingThread($lead);
        if (!$thread) {
            return ApiResponse::error('Message not found', 404);
        }

        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->whereNull('sender_id')
            ->whereNull('sender_admin_id')
            ->where('is_deleted', false)
            ->first();

        if (!$message) {
            return ApiResponse::error('Message not found', 404);
        }

        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    private function companyName(Lead $lead): string
    {
        $name = Company::find($lead->company_id)?->invoicingProfile()['name'];

        return trim((string) $name) ?: (string) config('app.name');
    }

    private function notifyAndLog(Lead $lead, ChatThread $thread, ChatMessage $message): void
    {
        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        // assigned_to, NOT transferred_to: a transfer sets both to the new
        // owner (Api\User\LeadController::transfer()), but a later plain
        // reassignment moves only assigned_to — leaving transferred_to
        // pointing at whoever held the lead two owners ago. Preferring it
        // would route the Lead's message to someone no longer on the deal.
        $recipientId = $lead->assigned_to;

        // ?tab=chat — frontend/app/leads/[id]/page.tsx supports this deep
        // link; without it the notification lands on the Details tab and the
        // new message is easy to miss entirely.
        if ($recipientId) {
            Notification::create([
                'user_id'    => $recipientId,
                'company_id' => $lead->company_id,
                'type'       => 'lead_sales_chat_message',
                'title'      => 'New message from a lead',
                'body'       => "{$lead->name}: {$preview}",
                'data'       => [
                    'lead_id'     => $lead->id,
                    'message_id'  => $message->id,
                    'sender_name' => $lead->name,
                    'link'        => "/leads/{$lead->id}?tab=chat",
                ],
            ]);
        }

        // No `user_id` actor — the Admin-bell fallback every other chat
        // surface writes alongside its Notification row, so Company Admin sees
        // it even when the lead has no assignee to notify directly.
        SystemAuditLog::create([
            'company_id'  => $lead->company_id,
            'user_id'     => null,
            'action'      => 'lead_sales_chat_message_sent',
            'module_key'  => 'sales',
            'entity_type' => 'Lead',
            'entity_id'   => $lead->id,
            'new_values'  => [
                'thread_id'  => $thread->id,
                'message_id' => $message->id,
                'preview'    => $preview,
                'sender'     => $lead->name,
            ],
        ]);
    }

    // No raw internal ids (thread_id, lead_id, sender_id) reach the public
    // page beyond the message's own id — needed as a React key and to build
    // the attachment download link.
    private function formatMessage(ChatMessage $m): array
    {
        return [
            'id'      => $m->id,
            'content' => $m->content,
            // toIso8601String(), NOT toDateTimeString(): the latter emits a
            // bare "2026-08-28 11:20:07" with no timezone, which the browser's
            // new Date() parses as LOCAL time — showing every message off by
            // the viewer's UTC offset.
            'sent_at'         => $m->sent_at->toIso8601String(),
            'sender_name'     => $m->sender?->name ?? $m->senderAdmin?->name ?? $m->guest_sender_name ?? 'Someone',
            'is_guest'        => is_null($m->sender_id) && is_null($m->sender_admin_id),
            'attachment_name' => $m->attachment_name,
        ];
    }
}
