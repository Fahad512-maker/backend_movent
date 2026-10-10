<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Services\AttachmentStorageService;
use App\Services\SalesConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Admin-side mirror of Api\User\SalesChatController — Company Admin is
// unrestricted (same pattern as Api\Admin\ProjectChatController), scoped
// only to companies the admin owns, not to a specific lead/client ownership.
class SalesChatController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array { return $this->admin()->companies()->pluck('id')->toArray(); }

    // Mirrors Api\User\SalesChatController::threadFor() — once the invoice is
    // paid this conversation lives on the project's thread instead, and both
    // guards must follow it identically or Admin and Seller end up replying
    // into different threads. $forWrite: only a send may create a missing
    // project thread. See App\Services\SalesConversationResolver.
    private function threadFor(string $linkedType, int $linkedId, int $companyId, string $title, bool $forWrite = false): ChatThread
    {
        $thread = ChatThread::firstOrCreate(
            ['thread_type' => 'sales', 'linked_to_type' => $linkedType, 'linked_to_id' => $linkedId],
            ['company_id' => $companyId, 'title' => $title]
        );

        return SalesConversationResolver::resolve($thread, $linkedType, $linkedId, $forWrite);
    }

    public function leadMessages(int $leadId): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($leadId);
        $thread = $this->threadFor('Lead', $lead->id, $lead->company_id, $lead->name);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendLeadMessage(Request $request, int $leadId): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($leadId);
        $thread = $this->threadFor('Lead', $lead->id, $lead->company_id, $lead->name, true);

        return $this->send($request, $thread, $lead->company_id, "/admin/leads/{$lead->id}?tab=chat", "lead \"{$lead->name}\"", $lead->assigned_to);
    }

    public function downloadLeadAttachment(int $leadId, int $messageId): StreamedResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($leadId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Lead')->where('linked_to_id', $lead->id)->firstOrFail();

        // Resolved like the message list — an attachment sent before payment
        // now hangs off the project thread, and download() scopes by thread_id.
        return $this->download(SalesConversationResolver::resolve($thread, 'Lead', $lead->id)->id, $messageId, $lead->company_id);
    }

    public function clientMessages(int $clientId): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($clientId);
        $thread = $this->threadFor('Client', $client->id, $client->company_id, $client->name);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendClientMessage(Request $request, int $clientId): JsonResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($clientId);
        $thread = $this->threadFor('Client', $client->id, $client->company_id, $client->name, true);

        return $this->send($request, $thread, $client->company_id, "/admin/clients/{$client->id}?tab=chat", "client \"{$client->name}\"", $client->account_manager);
    }

    public function downloadClientAttachment(int $clientId, int $messageId): StreamedResponse
    {
        $client = Client::whereIn('company_id', $this->companyIds())->findOrFail($clientId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)->firstOrFail();

        return $this->download(SalesConversationResolver::resolve($thread, 'Client', $client->id)->id, $messageId, $client->company_id);
    }

    // The invoice-detail-page counterpart to leadMessages()/clientMessages()
    // — reached for a fully guest invoice (no Lead, no Client) whose public
    // "Chat with Seller" thread otherwise has no page to be read from at all
    // (see App\Services\InvoiceChatContext).
    public function invoiceMessages(int $invoiceId): JsonResponse
    {
        $invoice = Invoice::whereIn('company_id', $this->companyIds())->findOrFail($invoiceId);
        $thread = $this->threadFor('Invoice', $invoice->id, $invoice->company_id, $invoice->customer_name ?? $invoice->invoice_number);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendInvoiceMessage(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = Invoice::whereIn('company_id', $this->companyIds())->findOrFail($invoiceId);
        $thread = $this->threadFor('Invoice', $invoice->id, $invoice->company_id, $invoice->customer_name ?? $invoice->invoice_number, true);

        return $this->send($request, $thread, $invoice->company_id, "/admin/invoices/{$invoice->id}?tab=chat",
            "invoice \"{$invoice->invoice_number}\"", $invoice->created_by);
    }

    public function downloadInvoiceAttachment(int $invoiceId, int $messageId): StreamedResponse
    {
        $invoice = Invoice::whereIn('company_id', $this->companyIds())->findOrFail($invoiceId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Invoice')->where('linked_to_id', $invoice->id)->firstOrFail();

        return $this->download(SalesConversationResolver::resolve($thread, 'Invoice', $invoice->id)->id, $messageId, $invoice->company_id);
    }

    private function messages(int $threadId)
    {
        return ChatMessage::where('thread_id', $threadId)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();
    }

    // DELETE /admin/sales-chat/messages/{messageId} — Company Admin is
    // unrestricted (same "unrestricted" posture as every other method in
    // this controller), scoped only to companies this admin owns via the
    // message's own thread. Works across Lead/Client/Invoice-anchored
    // threads alike since it keys off the message id, not a specific
    // entity route. Soft-delete only (is_deleted flag), same as every other
    // chat-message delete in this codebase (Api\PublicLeadChatController::
    // deleteMessage(), Api\User\ProjectMessengerController::deleteMessage()).
    public function deleteMessage(int $messageId): JsonResponse
    {
        $message = ChatMessage::where('id', $messageId)
            ->where('is_deleted', false)
            ->whereHas('thread', fn ($q) => $q->whereIn('company_id', $this->companyIds()))
            ->firstOrFail();

        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    private function download(int $threadId, int $messageId, int $companyId): StreamedResponse
    {
        $message = ChatMessage::where('thread_id', $threadId)->whereNotNull('attachment_path')->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $companyId
        );
    }

    private function send(Request $request, ChatThread $thread, int $companyId, string $link, string $entityLabel, ?int $notifyUserId): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'file'    => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($companyId, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            $folder = 'companies/' . $companyId . '/sales-chat/' . $thread->linked_to_type . '-' . $thread->linked_to_id;
            $stored = AttachmentStorageService::store($file, $companyId, 'chat', $folder);
            if (!$stored) {
                return AttachmentStorageService::errorResponse($companyId);
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
            'thread_id'       => $thread->id,
            'sender_admin_id' => $this->admin()->id,
            'content'         => $validated['content'] ?? null,
            'message_type'    => $messageType,
            'visibility'      => 'client',
            'attachment_name' => $attachmentName,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($thread, $message, $companyId, $link, $entityLabel, $notifyUserId);

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }

    private function notifyAndLog(ChatThread $thread, ChatMessage $message, int $companyId, string $link, string $entityLabel, ?int $notifyUserId): void
    {
        $senderName = $message->senderAdmin?->name ?? $message->sender?->name ?? 'Someone';
        $preview    = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        if ($notifyUserId) {
            Notification::create([
                'user_id'    => $notifyUserId,
                'company_id' => $companyId,
                'type'       => 'sales_chat_message',
                'title'      => 'New Sales Chat message',
                'body'       => "{$senderName}: {$preview}",
                'data'       => ['thread_id' => $thread->id, 'message_id' => $message->id, 'sender_name' => $senderName, 'link' => $link],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => null,
            'action'      => 'sales_chat_message_sent',
            'module_key'  => 'sales',
            'entity_type' => $thread->linked_to_type,
            'entity_id'   => $thread->linked_to_id,
            'new_values'  => ['thread_id' => $thread->id, 'message_id' => $message->id, 'preview' => $preview, 'entity' => $entityLabel, 'sender' => $senderName],
        ]);
    }
}
