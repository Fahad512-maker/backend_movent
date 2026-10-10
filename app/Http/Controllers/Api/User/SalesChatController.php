<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use App\Services\SalesConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// "Sales Chat" — a Seller's own conversation surface for a specific Lead or
// Client, kept entirely separate from Internal/Client-facing Project Chat
// (Api\User\ProjectChatController). Reuses the same generic chat_threads/
// chat_messages/chat_participants tables via thread_type='sales' +
// linked_to_type='Lead'|'Client' (linked_to_type was defined on chat_threads
// from day one but never actually used until now).
class SalesChatController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey, string $moduleKey = 'sales'): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Sales Chat is Seller <-> Client (<-> Company Admin, unrestricted on its
    // own guard), PLUS a Lead Manager for a thread they themselves actually
    // own (they created the invoice, they're the lead's assigned owner, or
    // they're the client's account manager) — never any other internal
    // role. canUseSalesChat alone isn't a strong enough gate: it's a real,
    // grantable permission key, so a Company Admin could otherwise hand it
    // to e.g. a Production user with no ownership ties at all. Hard
    // role_type check, same defense-in-depth pattern as isPM()/
    // isInternalStaff() elsewhere in this codebase (Api\User\
    // ProjectMessengerController/ProjectCommentController).
    //
    // A Lead Manager reaching here is deliberately NEVER given the
    // canViewAllCompanyLeads company-wide bypass that lead()/client()/
    // invoice() below grant a Seller with that override — despite normally
    // holding that permission by default (for their oversight of leads/
    // clients/invoices elsewhere), it must not also unlock every OTHER
    // Seller's private client conversations here. Ownership is always
    // enforced for them specifically; see the lead_manager branch in each
    // scoping helper below.
    private function canActAsChatOwner(): bool
    {
        return in_array($this->user()->role_type, ['seller', 'lead_manager'], true);
    }

    /**
     * The gate for LEAD chat specifically — deliberately looser than
     * canActAsChatOwner() above, which still guards the Client and Invoice
     * conversations.
     *
     * 2026-09-11 request: "seller, or any user who can create leads and who
     * the lead is assigned to". The hard role_type check was keeping two
     * legitimate people out of a conversation that is plainly theirs — the
     * Admin deputy role, and anyone else a Company Admin had deliberately
     * granted canUseSalesChat to — even for a lead they created themselves.
     *
     * Dropping the role check does NOT widen this to "anyone with the
     * permission": lead() below is the real gate and always has been. It
     * scopes every one of these endpoints to leads this person is actually
     * tied to — assigned, transferred to them, created by them, or invoiced
     * by them — so a user holding canUseSalesChat with no tie to a given lead
     * still sees nothing. What changes is WHICH roles may hold such a tie,
     * not whether a tie is required.
     *
     * Client and Invoice chat keep the stricter role check on purpose: those
     * are a Seller's private conversations with an existing customer, and
     * nothing in the request asked to open them up.
     */
    private function canActOnLeadChat(): bool
    {
        return $this->can('canUseSalesChat');
    }

    // Same scope Api\User\LeadController::visibleLeads() uses, PLUS "I
    // created an invoice for this lead" — App\Services\InvoiceChatContext::
    // resolve() picks invoice.created_by as the seller to notify BEFORE it
    // ever looks at the lead's own assigned_to/transferred_to, so a Lead
    // Manager who raised the invoice (but isn't the lead's assignee) must
    // still be able to reach what they were just notified about. A Lead
    // Manager never gets the canViewAllCompanyLeads bypass here (see
    // canActAsChatOwner()'s comment); only a literal Seller holding that
    // override does.
    private function lead(int $leadId): Lead
    {
        $user = $this->user();
        $base = Lead::where('company_id', $user->company_id);

        // The company-wide canViewAllCompanyLeads bypass is now restricted to
        // a LITERAL Seller. That is not a narrowing: until canActOnLeadChat()
        // replaced the hard role check, only a Seller or Lead Manager could
        // reach this method at all, and Lead Manager was already carved out —
        // so a Seller was already the only role the bypass ever applied to.
        // Spelling it out keeps it that way now that other roles can get here:
        // without it, the Admin deputy (who holds every permission, this one
        // included) would silently gain every lead's private conversation
        // company-wide, rather than the leads actually tied to them.
        if ($user->role_type !== 'seller' || !$this->can('canViewAllCompanyLeads')) {
            $base->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('transferred_to', $user->id)
                  // "I raised this lead" — added 2026-09-11 alongside
                  // canActOnLeadChat(). Creating a lead and then talking to it
                  // is the most ordinary thing a Seller does, yet without this
                  // clause someone who created a lead but was never assigned
                  // it could not open its chat at all unless they had also
                  // happened to raise an invoice for it.
                  ->orWhere('created_by', $user->id)
                  ->orWhereHas('invoices', fn ($i) => $i->where('created_by', $user->id));
            });
        }

        return $base->findOrFail($leadId);
    }

    // A Seller's "own clients": they're the account manager, OR the client
    // originated from one of their own leads, OR they created an invoice for
    // this client (same InvoiceChatContext::resolve() priority as lead()
    // above), OR they hold the company-wide lead/client override. A Lead
    // Manager never gets that last bypass here (see canActAsChatOwner()'s
    // comment) — only ownership counts.
    private function client(int $clientId): Client
    {
        $user = $this->user();
        $base = Client::where('company_id', $user->company_id);

        if ($user->role_type === 'lead_manager' || !$this->can('canViewAllCompanyLeads')) {
            $base->where(function ($q) use ($user) {
                $q->where('account_manager', $user->id)
                  ->orWhereHas('lead', fn($l) => $l->where(fn($q2) => $q2->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id)))
                  ->orWhereHas('invoices', fn ($i) => $i->where('created_by', $user->id));
            });
        }

        return $base->findOrFail($clientId);
    }

    // A fully guest invoice (no Lead, no Client — see App\Services\
    // InvoiceChatContext) has no other ownership signal, so this is scoped
    // to invoices this actor themselves created — a Lead Manager included,
    // since App\Services\InvoiceChatContext::resolve() already picked
    // created_by as the seller to notify; they must actually be able to
    // reach what they were just notified about. Same lead_manager carve-out
    // as lead()/client() above — no canViewAllCompanyLeads bypass for them.
    private function invoice(int $invoiceId): Invoice
    {
        $user = $this->user();
        $base = Invoice::where('company_id', $user->company_id);

        if ($user->role_type === 'lead_manager' || !$this->can('canViewAllCompanyLeads')) {
            $base->where('created_by', $user->id);
        }

        return $base->findOrFail($invoiceId);
    }

    // $forWrite: only a send may bring a missing project thread into
    // existence, mirroring Api\PublicInvoiceChatController's own
    // existingThreadFor()-to-display / threadFor()-to-send split.
    private function threadFor(string $linkedType, int $linkedId, int $companyId, string $title, bool $forWrite = false): ChatThread
    {
        $thread = ChatThread::firstOrCreate(
            ['thread_type' => 'sales', 'linked_to_type' => $linkedType, 'linked_to_id' => $linkedId],
            ['company_id' => $companyId, 'title' => $title, 'created_by' => $this->user()->id]
        );

        ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $this->user()->id],
            ['role' => 'member', 'joined_at' => now()]
        );

        // Once the invoice is paid this conversation lives on the project's
        // thread instead — see App\Services\SalesConversationResolver.
        //
        // No participant row is created on that resolved thread, on purpose:
        // participation in 'project_group' is what Api\*\ProjectMessengerController
        // treats as access to the INTERNAL team conversation, and Sales Chat
        // must not hand a Seller that. Authorisation here is already the
        // canUseSalesChat permission plus the ownership checks in lead()/
        // client()/invoice(), and messages() reads only the client-visible
        // slice, so no row is needed.
        return SalesConversationResolver::resolve($thread, $linkedType, $linkedId, $forWrite);
    }

    public function leadMessages(int $leadId): JsonResponse
    {
        if (!$this->canActOnLeadChat()) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->lead($leadId);
        $thread = $this->threadFor('Lead', $lead->id, $lead->company_id, $lead->name);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendLeadMessage(Request $request, int $leadId): JsonResponse
    {
        if (!$this->canActOnLeadChat()) {
            return ApiResponse::error('Permission denied', 403);
        }

        $lead = $this->lead($leadId);
        $thread = $this->threadFor('Lead', $lead->id, $lead->company_id, $lead->name, true);

        return $this->send($request, $thread, $lead->company_id, "/leads/{$lead->id}?tab=chat", "lead \"{$lead->name}\"",
            $lead->assigned_to);
    }

    public function downloadLeadAttachment(int $leadId, int $messageId): StreamedResponse
    {
        if (!$this->canActOnLeadChat()) {
            abort(403, 'Permission denied');
        }

        $lead = $this->lead($leadId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Lead')->where('linked_to_id', $lead->id)->firstOrFail();

        // Resolved like the message list itself: an attachment sent before
        // payment now hangs off the project thread, and download() scopes by
        // thread_id — without this the file 404s the moment the invoice is paid.
        return $this->download(SalesConversationResolver::resolve($thread, 'Lead', $lead->id)->id, $messageId, $lead->company_id);
    }

    public function clientMessages(int $clientId): JsonResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->client($clientId);
        $thread = $this->threadFor('Client', $client->id, $client->company_id, $client->name);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendClientMessage(Request $request, int $clientId): JsonResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->client($clientId);
        $thread = $this->threadFor('Client', $client->id, $client->company_id, $client->name, true);

        return $this->send($request, $thread, $client->company_id, "/clients/{$client->id}?tab=chat", "client \"{$client->name}\"",
            $client->account_manager);
    }

    public function downloadClientAttachment(int $clientId, int $messageId): StreamedResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            abort(403, 'Permission denied');
        }

        $client = $this->client($clientId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)->firstOrFail();

        return $this->download(SalesConversationResolver::resolve($thread, 'Client', $client->id)->id, $messageId, $client->company_id);
    }

    // The invoice-detail-page counterpart to leadMessages()/clientMessages()
    // — reached for a fully guest invoice (no Lead, no Client) whose public
    // "Chat with Seller" thread otherwise has no Seller-side page to be read
    // from at all (see App\Services\InvoiceChatContext).
    public function invoiceMessages(int $invoiceId): JsonResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $invoice = $this->invoice($invoiceId);
        $thread = $this->threadFor('Invoice', $invoice->id, $invoice->company_id, $invoice->customer_name ?? $invoice->invoice_number);

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $this->messages($thread->id)]);
    }

    public function sendInvoiceMessage(Request $request, int $invoiceId): JsonResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $invoice = $this->invoice($invoiceId);
        $thread = $this->threadFor('Invoice', $invoice->id, $invoice->company_id, $invoice->customer_name ?? $invoice->invoice_number, true);

        // No other internal recipient to notify — a guest invoice has no
        // Lead/Client owner besides the Seller who's the one replying here;
        // the guest customer picks this up via their own polling on the
        // public invoice page, not an in-app Notification (no `users.id`).
        return $this->send($request, $thread, $invoice->company_id, "/invoices/{$invoice->id}?tab=chat",
            "invoice \"{$invoice->invoice_number}\"", null);
    }

    public function downloadInvoiceAttachment(int $invoiceId, int $messageId): StreamedResponse
    {
        if (!$this->canActAsChatOwner() || !$this->can('canUseSalesChat')) {
            abort(403, 'Permission denied');
        }

        $invoice = $this->invoice($invoiceId);
        $thread = ChatThread::where('thread_type', 'sales')->where('linked_to_type', 'Invoice')->where('linked_to_id', $invoice->id)->firstOrFail();

        return $this->download(SalesConversationResolver::resolve($thread, 'Invoice', $invoice->id)->id, $messageId, $invoice->company_id);
    }

    private function messages(int $threadId)
    {
        return ChatMessage::where('thread_id', $threadId)
            ->where('is_deleted', false)
            // A no-op on a 'sales' thread (everything written there is
            // client-facing) but essential once threadFor() has resolved to a
            // project thread, which also carries the team's INTERNAL messages
            // — the same visibility='client' slice the client portal and the
            // public invoice link read. Without it internal project chatter
            // would surface inside a client-facing Sales Chat.
            ->where('visibility', 'client')
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();
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
            'sender_id'       => $this->user()->id,
            'content'         => $validated['content'] ?? null,
            'message_type'    => $messageType,
            'visibility'      => 'client',
            'attachment_name' => $attachmentName,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($thread, $message, $companyId, $link, $entityLabel, $notifyUserId);

        return ApiResponse::success($message->load('sender:id,name'), 'Message sent', 201);
    }

    private function notifyAndLog(ChatThread $thread, ChatMessage $message, int $companyId, string $link, string $entityLabel, ?int $notifyUserId): void
    {
        $actorId    = $this->user()->id;
        $senderName = $message->sender?->name ?? $message->senderAdmin?->name ?? 'Someone';
        $preview    = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        if ($notifyUserId && $notifyUserId !== $actorId) {
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
            'user_id'     => $actorId,
            'action'      => 'sales_chat_message_sent',
            'module_key'  => 'sales',
            'entity_type' => $thread->linked_to_type,
            'entity_id'   => $thread->linked_to_id,
            'new_values'  => ['thread_id' => $thread->id, 'message_id' => $message->id, 'preview' => $preview, 'entity' => $entityLabel, 'sender' => $senderName],
        ]);
    }
}
