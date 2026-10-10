<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Services\AttachmentStorageService;
use App\Services\InvoiceChatContext;
use App\Services\ProjectChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// "Chat with Seller" on the public, no-login invoice/payment page
// (Api\PublicInvoiceController's counterpart) — authenticated purely by the
// invoice's own payment_token, exactly like every other public/* endpoint.
// Once the invoice has a Project, this writes into that Project's own
// 'project_group' thread (visibility='client' slice — same one staff read at
// /projects/{id}/chat and Api\Client\ProjectChatController already exposes
// to a logged-in Client). Before a Project exists, it falls back to the
// 'sales' thread Api\User\SalesChatController / Api\Admin\SalesChatController
// / Api\Client\ChatController already read/write for that Lead or Client.
// Either way, if the client later gets Client Portal access (or a Project
// gets created from the Lead), they see this exact conversation continue —
// never a duplicate thread. See App\Services\InvoiceChatContext for the
// Project/Client/Lead/Seller resolution.
class PublicInvoiceChatController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB, same cap as every other chat surface
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function resolveInvoice(string $token): ?Invoice
    {
        return Invoice::where('payment_token', $token)
            ->with(['client', 'lead', 'project'])
            ->first();
    }

    // Mirrors Api\PublicInvoiceController's own token/status checks exactly.
    private function invalid(?Invoice $invoice): ?JsonResponse
    {
        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }
        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }
        if ($invoice->status === 'cancelled') {
            return ApiResponse::error('This invoice is no longer active', 422);
        }
        return null;
    }

    // Read-only lookup for a given context — never creates a thread just
    // because someone opened the drawer and sent nothing; only store()
    // below actually creates one (via threadFor()/firstOrCreate()).
    private function existingThread(array $context): ?ChatThread
    {
        if ($context['threadType'] === 'project_group') {
            return ProjectChatService::existingThreadFor($context['project']);
        }

        return ChatThread::where('thread_type', 'sales')
            ->where('linked_to_type', $context['linkedType'])
            ->where('linked_to_id', $context['linkedId'])
            ->first();
    }

    // Resolves the token -> invoice -> context -> thread chain shared by
    // show()/store()/downloadAttachment(), returning null (already-written
    // error response) on any failure.
    private function resolveContextAndThread(string $token): array
    {
        $invoice = $this->resolveInvoice($token);
        if ($err = $this->invalid($invoice)) {
            return [null, null, null, $err];
        }

        $context = InvoiceChatContext::resolve($invoice);
        if (!$context) {
            return [$invoice, null, null, ApiResponse::error('Chat is not available for this invoice.', 404)];
        }

        return [$invoice, $context, $this->existingThread($context), null];
    }

    // GET /public/invoices/{token}/chat
    public function show(string $token): JsonResponse
    {
        $invoice = $this->resolveInvoice($token);
        if ($err = $this->invalid($invoice)) return $err;

        $context = InvoiceChatContext::resolve($invoice);
        if (!$context) {
            return ApiResponse::success(['available' => false, 'seller_name' => null, 'messages' => []]);
        }

        $thread = $this->existingThread($context);

        $query = $thread
            ? ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)
            : null;

        // Project Chat is a shared internal+client thread — only the
        // client-visible slice may ever reach this public, unauthenticated
        // page (mirrors Api\Client\ProjectChatController::index() exactly).
        // The 'sales' thread has no internal slice to begin with, so no
        // filter is needed there.
        if ($query && $context['threadType'] === 'project_group') {
            $query->where('visibility', 'client');
        }

        $messages = $query
            ? $query->with(['sender:id,name', 'senderAdmin:id,name'])->orderBy('sent_at')->get()->map(fn ($m) => $this->formatMessage($m))->values()
            : [];

        return ApiResponse::success([
            'available'   => true,
            'seller_name' => $context['sellerId'] ? User::find($context['sellerId'])?->name : null,
            'messages'    => $messages,
        ]);
    }

    // POST /public/invoices/{token}/chat/messages — throttled at the route
    // level (this is the first genuinely public, unauthenticated write
    // endpoint in the app).
    public function store(Request $request, string $token): JsonResponse
    {
        [$invoice, $context, , $err] = $this->resolveContextAndThread($token);
        if ($err) return $err;

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'file'    => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($invoice->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        if ($context['threadType'] === 'project_group') {
            // Identical resolver Api\User\ProjectMessengerController /
            // Api\Client\ProjectChatController use — lands in the same row.
            $thread = ProjectChatService::threadFor($context['project']);
        } else {
            // Identical firstOrCreate key Api\User\SalesChatController /
            // Api\Admin\SalesChatController / Api\Client\ChatController use.
            $thread = ChatThread::firstOrCreate(
                ['thread_type' => 'sales', 'linked_to_type' => $context['linkedType'], 'linked_to_id' => $context['linkedId']],
                ['company_id' => $invoice->company_id, 'title' => $context['title']]
            );
        }

        $guestName = $invoice->client->name ?? $invoice->customer_name ?? 'Client';

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            // Same folder-per-thread convention as Api\User\SalesChatController
            // ::send(), just under a distinct 'public-chat' root so a guest
            // upload is never confusable with one a logged-in staff member made.
            $folder = 'companies/' . $invoice->company_id . '/public-chat/' . $thread->linked_to_type . '-' . $thread->linked_to_id;
            $stored = AttachmentStorageService::store($file, $invoice->company_id, 'chat', $folder);
            if (!$stored) {
                return AttachmentStorageService::errorResponse($invoice->company_id);
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
            'guest_sender_name' => $guestName,
            'content'           => $validated['content'] ?? null,
            'message_type'      => $messageType,
            'visibility'        => 'client',
            'attachment_name'   => $attachmentName,
            'sent_at'           => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($invoice, $thread, $message, $context, $guestName);

        return ApiResponse::success($this->formatMessage($message), 'Message sent', 201);
    }

    // GET /public/invoices/{token}/chat/messages/{messageId}/attachment —
    // same token-only auth as every other public/* endpoint; re-derives the
    // thread from the token rather than trusting messageId alone, so a
    // guessed id from a DIFFERENT invoice's thread can never be downloaded
    // through this one's link.
    public function downloadAttachment(string $token, int $messageId): StreamedResponse|JsonResponse
    {
        [, , $thread, $err] = $this->resolveContextAndThread($token);
        if ($err) return $err;

        if (!$thread) {
            return ApiResponse::error('Attachment not found', 404);
        }

        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->whereNotNull('attachment_path')
            ->first();

        if (!$message) {
            return ApiResponse::error('Attachment not found', 404);
        }

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $thread->company_id
        );
    }

    // DELETE /public/invoices/{token}/chat/messages/{messageId} — the guest
    // may only ever delete their OWN messages: sender_id and sender_admin_id
    // are both null exclusively on a message this endpoint itself created
    // (see store() above), so that pair doubles as "belongs to the client,
    // not staff" — a Seller/Admin reply can never be touched from here.
    public function deleteMessage(string $token, int $messageId): JsonResponse
    {
        [, , $thread, $err] = $this->resolveContextAndThread($token);
        if ($err) return $err;

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

    private function notifyAndLog(Invoice $invoice, ChatThread $thread, ChatMessage $message, array $context, string $guestName): void
    {
        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        if ($context['threadType'] === 'project_group') {
            $link      = "/projects/{$context['project']->id}/chat";
            $moduleKey = 'project_management';
            $entityType = 'Project';
            $entityId   = $context['project']->id;
        } else {
            // ?tab=chat — frontend/app/leads/[id]/page.tsx, clients/[id]/
            // page.tsx and invoices/[id]/page.tsx all support this deep link;
            // without it the notification lands on the Details tab and the
            // new message is easy to miss entirely.
            $link       = match ($context['linkedType']) {
                'Client'  => "/clients/{$context['linkedId']}?tab=chat",
                'Invoice' => "/invoices/{$context['linkedId']}?tab=chat",
                default   => "/leads/{$context['linkedId']}?tab=chat",
            };
            $moduleKey  = 'sales';
            $entityType = $context['linkedType'];
            $entityId   = $context['linkedId'];
        }

        if ($context['sellerId']) {
            Notification::create([
                'user_id'    => $context['sellerId'],
                'company_id' => $invoice->company_id,
                'type'       => 'public_invoice_chat_message',
                'title'      => 'New message via invoice payment link',
                'body'       => "{$guestName}: {$preview}",
                'data'       => ['thread_id' => $thread->id, 'message_id' => $message->id, 'sender_name' => $guestName, 'link' => $link],
            ]);
        }

        // No `user_id` actor — this is the Admin-bell fallback every other
        // chat surface writes alongside its Notification row (see e.g.
        // Api\Admin\SalesChatController::notifyAndLog()), so Company Admin
        // sees it even when no Seller could be resolved to notify directly.
        SystemAuditLog::create([
            'company_id'  => $invoice->company_id,
            'user_id'     => null,
            'action'      => 'public_invoice_chat_message_sent',
            'module_key'  => $moduleKey,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'new_values'  => ['thread_id' => $thread->id, 'message_id' => $message->id, 'preview' => $preview, 'sender' => $guestName, 'invoice_number' => $invoice->invoice_number],
        ]);
    }

    // No raw internal IDs (thread_id, sender_id, client_id) exposed to the
    // public frontend beyond the message's own id (needed as a React key,
    // and to build the attachment download link).
    private function formatMessage(ChatMessage $m): array
    {
        return [
            'id'              => $m->id,
            'content'         => $m->content,
            // toIso8601String(), NOT toDateTimeString(): the latter emits a
            // bare "2026-08-28 11:20:07" with no timezone, and the browser's
            // new Date() parses a space-separated string like that as LOCAL
            // time — so a UTC value was being read as if it were already
            // local, showing every message off by the viewer's UTC offset.
            'sent_at'         => $m->sent_at->toIso8601String(),
            'sender_name'     => $m->sender?->name ?? $m->senderAdmin?->name ?? $m->guest_sender_name ?? 'Someone',
            'is_guest'        => is_null($m->sender_id) && is_null($m->sender_admin_id),
            'attachment_name' => $m->attachment_name,
        ];
    }
}
