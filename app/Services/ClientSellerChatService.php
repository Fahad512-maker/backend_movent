<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Client <-> Seller direct conversation the Client Portal assistant hands
 * off into.
 *
 * NOTHING HERE IS A NEW CHAT SYSTEM. It is the app's existing per-client Direct
 * Chat — `chat_threads.thread_type = 'client'`, anchored to
 * (linked_to_type='Client', linked_to_id=<client id>) — the same rows that
 * Api\User\ClientChatController already lists on a staff member's Client
 * detail page and Api\Admin\ClientChatController already reviews on the Admin
 * one. Both of those surfaces, their notifications and their permission rules
 * are untouched; this adds the CLIENT end, which has been missing since
 * Api\Client\ChatController was removed on 2026-09-11, plus the one-time
 * welcome message the handoff needs.
 *
 * Why this thread and not the project one: the portal's project conversation
 * lives on the project's own 'project_group' thread, which a client cannot
 * cause to exist (ProjectChatService::threadFor() is PM-tier/Admin only) and
 * which does not exist at all for a client who has no project — a standalone
 * invoice client, or one whose project is still draft. Handing those clients at
 * the project chat produced a dead end. The per-client Direct Chat has none of
 * those preconditions and is already scoped, per company, to exactly the two
 * people in it.
 *
 * Identity of a conversation: ONE thread per (client, staff member). It is
 * identified by its participants, not by a name — exactly the rule
 * Api\User\ClientChatController::startChat() already uses (a thread with
 * precisely those two participant rows), so a conversation started from the
 * staff side and one started from here are the same row rather than two.
 */
class ClientSellerChatService
{
    /**
     * The automated opener, marked as such without a new column.
     *
     * `message_type='system'` is the existing enum value for "this line was not
     * typed by a person" (chat_messages.message_type is
     * text/file/image/system). `sender_id` is still the seller, so to the client
     * it reads as a normal message from the person they asked for — which is
     * what it is, on that person's behalf — while anything that needs to know it
     * was automated can tell from the type.
     */
    public const WELCOME_TYPE = 'system';

    /** English is the default; the client's own language is used when we know it. */
    public const WELCOME_TEXTS = [
        'en'       => "Hi! How may I help you? Send me your message and I'll get back to you as soon as I can.",
        'roman_ur' => 'Assalam o Alaikum! Main aap ki kis tarah madad kar sakta hoon? Apna message bhej dein, main jald jawab dunga.',
        'ur'       => 'السلام علیکم! میں آپ کی کس طرح مدد کر سکتا ہوں؟ اپنا پیغام بھیج دیں، میں جلد جواب دوں گا۔',
    ];

    /**
     * The existing conversation between this client and this staff member, or
     * null.
     *
     * Read-only on purpose — "does a conversation exist?" must never be the
     * question that brings one into existence, the same split
     * Api\PublicInvoiceChatController and ProjectChatService already make.
     *
     * Matched on PARTICIPANTS rather than on the thread's title or created_by:
     * a title is cosmetic and created_by only records who happened to press
     * first, whereas the pair of people in the room is what actually makes two
     * rows the same conversation.
     */
    public static function existingThread(Client $client, int $staffUserId): ?ChatThread
    {
        if (!$client->user_id) {
            return null;
        }

        return ChatThread::where('thread_type', 'client')
            ->where('company_id', $client->company_id)
            ->where('linked_to_type', 'Client')
            ->where('linked_to_id', $client->id)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $staffUserId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $client->user_id))
            ->withCount('participants')
            ->orderBy('id')
            ->get()
            // Exactly two: the client and this staff member. A thread that also
            // holds an invited Project Manager still counts — the PM was added
            // to THIS conversation (Api\User\ClientChatController::
            // addParticipant()), so it is the same room with one more chair.
            ->first(fn (ChatThread $t) => $t->participants_count >= 2);
    }

    /**
     * Open the client's conversation with this seller, creating it the first
     * time.
     *
     * Everything that only happens ONCE happens here and is guarded by whether
     * the thread was actually created:
     *   - the welcome message (never repeated on a later open — see the
     *     messages()->exists() guard, which also covers a thread created but
     *     left empty by an earlier failure);
     *   - the seller's "a client wants to talk" notification.
     *
     * Runs in a transaction so a half-built conversation (thread with no
     * participants, or participants with no welcome) cannot survive a failure
     * partway through.
     *
     * @return array{thread: ChatThread, created: bool}
     */
    public static function startFromClient(
        Client $client,
        User $seller,
        string $language = 'en'
    ): array {
        // Cross-company is refused here rather than filtered later. This is the
        // one call that CREATES a conversation, so it is where a mismatched
        // pair must stop — a Company B seller must never end up in a Company A
        // client's room, whatever the caller believed.
        if ((int) $seller->company_id !== (int) $client->company_id
            && !$seller->companyAssignments()
                ->where('company_id', $client->company_id)
                ->where('status', 'active')
                ->exists()) {
            throw new \RuntimeException('That account manager does not belong to this company.');
        }

        if (!$client->user_id) {
            throw new \RuntimeException('This client has no portal account.');
        }

        $existing = static::existingThread($client, (int) $seller->id);

        if ($existing) {
            // Reopening is not re-starting: no second thread, no second
            // welcome, no second notification. Requirement §8/§16.
            return ['thread' => $existing, 'created' => false];
        }

        $thread = DB::transaction(function () use ($client, $seller, $language) {
            // Re-checked inside the transaction: two taps on "Talk to My
            // Seller" a moment apart would otherwise both find nothing and both
            // create a thread.
            $raced = static::existingThread($client, (int) $seller->id);
            if ($raced) {
                return $raced;
            }

            $thread = ChatThread::create([
                'company_id'     => $client->company_id,
                'thread_type'    => 'client',
                'linked_to_type' => 'Client',
                'linked_to_id'   => $client->id,
                // The staff member's name, matching what
                // Api\User\ClientChatController::startChat() titles it with, so
                // both surfaces label the same conversation identically.
                'title'          => $seller->name,
                // THE client-initiated marker, and an existing column rather
                // than a new one: created_by is the portal `users` row of the
                // client themselves. Staff-started threads carry the staff id,
                // Admin-started ones carry null — so all three origins stay
                // distinguishable without a schema change.
                'created_by'     => $client->user_id,
            ]);

            foreach ([$seller->id, $client->user_id] as $userId) {
                ChatParticipant::firstOrCreate(
                    ['thread_id' => $thread->id, 'user_id' => $userId],
                    ['role' => 'member', 'joined_at' => now()]
                );
            }

            static::ensureWelcome($thread, $seller, $language);

            return $thread;
        });

        // Outside the transaction: a notification is not worth rolling a
        // created conversation back for.
        static::notifySellerOfContact($client, $seller, $thread);

        return ['thread' => $thread, 'created' => true];
    }

    /**
     * The one-time opener from the seller's side.
     *
     * Guarded on the thread being EMPTY rather than on "was just created", so a
     * thread that was created but whose welcome failed to write still gets one,
     * and a conversation with any history never gets a second.
     */
    public static function ensureWelcome(ChatThread $thread, User $seller, string $language = 'en'): ?ChatMessage
    {
        if ($thread->messages()->exists()) {
            return null;
        }

        return ChatMessage::create([
            'thread_id'    => $thread->id,
            // The seller, not the assistant. Once the handoff has happened this
            // is a seller conversation, and labelling the opener "AI Assistance"
            // would misrepresent who the client is now talking to (§17/§29).
            'sender_id'    => $seller->id,
            'content'      => static::WELCOME_TEXTS[$language] ?? static::WELCOME_TEXTS['en'],
            'message_type' => static::WELCOME_TYPE,
            // 'client' so it is part of the conversation the client reads. The
            // default is 'internal', which would have hidden the welcome from
            // the only person it is addressed to.
            'visibility'   => 'client',
            'sent_at'      => now(),
        ]);
    }

    /**
     * "A client would like to talk" — through the existing bell, not a new one.
     *
     * Deliberately separate from the per-message notification below: this fires
     * once, when the conversation opens, and is the one the seller acts on to
     * find a client who has just walked in.
     */
    public static function notifySellerOfContact(Client $client, User $seller, ChatThread $thread): void
    {
        $company = $client->company?->name ?: $client->company_name;

        Notification::create([
            'user_id'    => $seller->id,
            'company_id' => $client->company_id,
            'type'       => 'client_chat_started',
            'title'      => "{$client->name} wants to speak with you",
            'body'       => $company
                ? "{$client->name} ({$company}) started a conversation from the Client Portal."
                : "{$client->name} started a conversation from the Client Portal.",
            // The staff Client detail page, where the Direct Chat panel already
            // lives — no new screen to build or link to.
            'data'       => [
                'client_id' => $client->id,
                'thread_id' => $thread->id,
                'link'      => "/clients/{$client->id}",
            ],
        ]);
    }

    /**
     * Post a message from the client and tell the staff side.
     *
     * Every staff participant is notified, which covers a Project Manager the
     * seller looped in later (Api\User\ClientChatController::addParticipant())
     * without this needing to know about that feature. The client is never
     * notified about their own message.
     */
    public static function postClientMessage(Client $client, ChatThread $thread, string $content): ChatMessage
    {
        $message = ChatMessage::create([
            'thread_id'    => $thread->id,
            'sender_id'    => $client->user_id,
            'content'      => $content,
            'message_type' => 'text',
            'visibility'   => 'client',
            'sent_at'      => now(),
        ]);

        $thread->update(['last_message_at' => now()]);

        $staffIds = ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', '!=', $client->user_id)
            ->pluck('user_id');

        foreach ($staffIds as $staffId) {
            Notification::create([
                'user_id'    => $staffId,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_message',
                'title'      => "New message from {$client->name}",
                'body'       => Str::limit($content, 120),
                'data'       => [
                    'client_id'  => $client->id,
                    'thread_id'  => $thread->id,
                    'message_id' => $message->id,
                    'link'       => "/clients/{$client->id}",
                ],
            ]);
        }

        return $message;
    }

    /**
     * How a message is shown to the CLIENT.
     *
     * Staff are named and role-labelled — the client is talking to a person, so
     * "Seller"/"Account Manager" and a name, never "AI Assistance" and never
     * "Unknown" (§29/§31). A soft-deleted staff member still resolves, because
     * ChatMessage::sender() carries withTrashed() for exactly this reason.
     */
    public static function presentMessage(ChatMessage $message, int $clientUserId): array
    {
        $isMine = (int) $message->sender_id === $clientUserId;

        return [
            'id'         => (int) $message->id,
            'content'    => $message->is_deleted ? 'This message was removed.' : $message->content,
            'mine'       => $isMine,
            'sender'     => $isMine
                ? null
                : ($message->sender?->name ?? $message->senderAdmin?->name ?? 'Support'),
            'sender_role' => $isMine ? null : static::roleLabel($message),
            // Lets the client UI mark the opener quietly if it wants to; it is
            // still rendered as an ordinary message from the seller.
            'automated'  => $message->message_type === static::WELCOME_TYPE,
            'sent_at'    => optional($message->sent_at)->toIso8601String(),
        ];
    }

    /**
     * A readable role for a staff sender, preferring any custom label they carry.
     *
     * role_type='seller' reads as "Seller", not "Account Manager". The two are
     * different things in this CRM — clients.account_manager is its own column,
     * and a seller is often not the person in it — so labelling every seller an
     * account manager told the client something that was not necessarily true.
     */
    private static function roleLabel(ChatMessage $message): string
    {
        if ($message->senderAdmin) {
            return 'Company Admin';
        }

        $user = $message->sender;

        if (!$user) {
            return 'Support';
        }

        if (!empty($user->custom_role_label)) {
            return $user->custom_role_label;
        }

        return match ($user->role_type) {
            'seller'          => 'Seller',
            'project_manager' => 'Project Manager',
            'lead_manager'    => 'Lead Manager',
            default           => Str::headline((string) $user->role_type),
        };
    }
}
