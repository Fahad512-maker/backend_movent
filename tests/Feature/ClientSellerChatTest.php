<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\CompanyAdmin;
use App\Models\User;
use App\Services\ClientSellerChatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Talk to My Seller" — the hand-off from AI Assistance into a real
 * conversation, and everything that must stay true of that conversation
 * afterwards.
 *
 * Four things carry the risk here:
 *
 *  1. VISIBILITY IS EARNED. A seller must see nothing until the client
 *     actually makes contact, and then only the clients who contacted THEM.
 *  2. ONE CONVERSATION. Pressing the button again tomorrow must reopen
 *     today's thread — not create a second, and not repeat the welcome.
 *  3. COMPANY ISOLATION. A conversation belongs to one company and one pair of
 *     people; no other seller, client, or company may reach it.
 *  4. THE ASSISTANT STEPS OUT. Once handed off, the thread is between two
 *     people. AI Assistance is not a participant and writes nothing into it.
 *
 * The AI is switched off throughout (see setUp), so these exercise the real
 * routing and authorization rather than a model's opinion of them.
 */
class ClientSellerChatTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private CompanyAdmin $otherAdmin;
    private int $companyA;
    private int $companyB;
    private User $clientUser;
    private Client $clientA;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.groq.key'       => null,
            'services.gemini.key'     => null,
            'services.openrouter.key' => null,
        ]);

        $this->admin      = $this->makeAdmin('csc');
        $this->otherAdmin = $this->makeAdmin('cscother');

        $this->companyA = $this->makeCompany($this->admin, 'Acme Corporation');
        $this->companyB = $this->makeCompany($this->otherAdmin, 'Other Holdings');

        $this->clientUser = $this->makeUser($this->companyA, 'John Client', 'client');
        $this->clientA    = $this->makeClient($this->companyA, $this->clientUser);

        $this->seller = $this->makeSeller($this->companyA, 'John Smith');
        $this->linkClientToSellerViaLead($this->clientA, $this->seller);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** packages.name is unique, so one package is made per run and shared. */
    private function packageId(): int
    {
        return $this->packageId ??= DB::table('packages')->insertGetId([
            'name' => 'CSC Package ' . uniqid(), 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private ?int $packageId = null;

    private function makeAdmin(string $tag): CompanyAdmin
    {
        return CompanyAdmin::findOrFail(DB::table('company_admins')->insertGetId([
            'package_id' => $this->packageId(),
            'name'       => ucfirst($tag) . ' Admin',
            'email'      => $tag . '+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function makeCompany(CompanyAdmin $admin, string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $admin->id, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['client_portal', 'invoices', 'projects'] as $module) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $module, 'is_enabled' => true,
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function makeUser(int $companyId, string $name, string $roleType): User
    {
        return User::create([
            'company_id' => $companyId,
            'name'       => $name,
            'email'      => str_replace(' ', '', strtolower($name)) . '+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'role_type'  => $roleType,
            'is_active'  => true,
            'status'     => 'active',
        ]);
    }

    private function makeSeller(int $companyId, string $name): User
    {
        $seller = $this->makeUser($companyId, $name, 'seller');

        // The staff guard resolves its company from an ACTIVE assignment row
        // (Api\User\Concerns\ScopesToActiveCompany), so a seller without one
        // has no company context at all.
        DB::table('company_user_assignments')->insert([
            'user_id' => $seller->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $seller;
    }

    private function makeClient(int $companyId, User $owner): Client
    {
        return Client::create([
            'company_id'    => $companyId,
            'user_id'       => $owner->id,
            'name'          => $owner->name,
            'email'         => $owner->email,
            'portal_access' => true,
        ]);
    }

    /** The CRM relationship the hand-off is supposed to follow: lead -> seller. */
    private function linkClientToSellerViaLead(Client $client, User $seller): void
    {
        $leadId = DB::table('leads')->insertGetId([
            'company_id'  => $client->company_id,
            'assigned_to' => $seller->id,
            'name'        => $client->name,
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $client->update(['lead_id' => $leadId]);
    }

    private function asClient(?User $user = null): self
    {
        Sanctum::actingAs($user ?? $this->clientUser, ['role:client']);

        return $this;
    }

    private function asStaff(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Runs "Talk to My Seller" through AI Assistance and returns the response. */
    private function talkToMySeller(string $text = 'I want to talk to my seller')
    {
        return $this->asClient()->postJson('/api/client/assistant/message', ['text' => $text]);
    }

    private function threadRow(): ?ChatThread
    {
        return ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')
            ->where('linked_to_id', $this->clientA->id)
            ->first();
    }

    // ── The hand-off ─────────────────────────────────────────────────────────

    public function test_talk_to_my_seller_opens_a_conversation_with_the_lead_seller(): void
    {
        $this->assertNull($this->threadRow(), 'No conversation may exist before the client asks for one.');

        $response = $this->talkToMySeller();

        $response->assertOk();
        $this->assertStringContainsString('John Smith', $response->json('data.message'));

        $thread = $this->threadRow();
        $this->assertNotNull($thread, 'The hand-off must open a conversation.');
        $this->assertSame($this->companyA, (int) $thread->company_id);
        $this->assertSame($thread->id, $response->json('data.open_seller_chat.thread_id'));

        // Exactly the two of them, and nobody else.
        $participants = ChatParticipant::where('thread_id', $thread->id)->pluck('user_id')->sort()->values()->all();
        $this->assertSame(
            collect([$this->seller->id, $this->clientUser->id])->sort()->values()->all(),
            $participants
        );

        // The client-initiated marker, on an existing column rather than a new
        // one: created_by is the client's own portal user.
        $this->assertSame((int) $this->clientUser->id, (int) $thread->created_by);
    }

    public function test_the_conversation_opens_with_one_welcome_from_the_seller(): void
    {
        $this->talkToMySeller()->assertOk();

        $messages = ChatMessage::where('thread_id', $this->threadRow()->id)->get();

        $this->assertCount(1, $messages);
        $welcome = $messages->first();

        // From the SELLER, not the assistant — this is a seller conversation now.
        $this->assertSame($this->seller->id, (int) $welcome->sender_id);
        $this->assertStringContainsString('How may I help you', $welcome->content);
        $this->assertStringNotContainsString('AI Assistance', $welcome->content);
        // The "automated" marker, using the existing enum rather than a column.
        $this->assertSame('system', $welcome->message_type);
        // Visible to the client — the default 'internal' would have hidden the
        // welcome from the only person it is addressed to.
        $this->assertSame('client', $welcome->visibility);
    }

    public function test_the_welcome_follows_the_clients_language(): void
    {
        $this->talkToMySeller('mujhe apne seller se baat karni hai')->assertOk();

        $welcome = ChatMessage::where('thread_id', $this->threadRow()->id)->first();

        $this->assertStringContainsString('Assalam o Alaikum', $welcome->content);
    }

    /** Requirement: reopening is not re-starting. */
    public function test_reopening_reuses_the_thread_and_never_repeats_the_welcome(): void
    {
        $this->talkToMySeller()->assertOk();
        $first = $this->threadRow();

        $again = $this->talkToMySeller();
        $again->assertOk();

        $this->assertSame(
            1,
            ChatThread::where('thread_type', 'client')->where('linked_to_id', $this->clientA->id)->count(),
            'A second press must not create a second conversation.'
        );
        $this->assertSame($first->id, $again->json('data.open_seller_chat.thread_id'));
        $this->assertSame(
            1,
            ChatMessage::where('thread_id', $first->id)->where('message_type', 'system')->count(),
            'The welcome must be written once and never again.'
        );

        // And the seller is told once, not twice.
        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $this->seller->id)
            ->where('type', 'client_chat_started')
            ->count());
    }

    public function test_the_seller_is_notified_when_the_client_makes_contact(): void
    {
        $this->talkToMySeller()->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id'    => $this->seller->id,
            'company_id' => $this->companyA,
            'type'       => 'client_chat_started',
        ]);

        $notification = DB::table('notifications')
            ->where('user_id', $this->seller->id)
            ->where('type', 'client_chat_started')
            ->first();

        $this->assertStringContainsString('John Client', $notification->title);
        // Points at the screen where the Direct Chat panel already lives.
        // Decoded rather than string-matched: json_encode escapes the slashes.
        $this->assertSame(
            "/clients/{$this->clientA->id}",
            json_decode($notification->data, true)['link'] ?? null
        );
    }

    // ── Visibility is earned ─────────────────────────────────────────────────

    /**
     * The widget is not a global seller chatbot.
     *
     * `visible` is what decides whether it renders at all, so this is the test
     * that stops it appearing for every seller in the company.
     */
    public function test_the_seller_widget_stays_hidden_until_the_client_initiates(): void
    {
        $before = $this->asStaff($this->seller)->getJson('/api/user/assistant/conversations');

        $before->assertOk()
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.conversations', []);

        $this->talkToMySeller()->assertOk();

        $after = $this->asStaff($this->seller)->getJson('/api/user/assistant/conversations');

        $after->assertOk()
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.conversations.0.client_name', 'John Client')
            ->assertJsonPath('data.conversations.0.company_name', 'Acme Corporation');
    }

    /**
     * A conversation the SELLER started is not a client reaching out, so it
     * must not switch the widget on either.
     */
    public function test_a_staff_started_conversation_does_not_make_the_widget_visible(): void
    {
        // Api\User\ClientChatController::isEligibleContact() lets a staff member
        // start a chat only with a client they are linked to — being the named
        // account manager is one of the three ways, and the simplest here.
        $this->clientA->update(['account_manager' => $this->seller->id]);

        $this->asStaff($this->seller)
            ->postJson("/api/user/clients/{$this->clientA->id}/direct-chat/start")
            ->assertCreated();

        $this->assertNotNull($this->threadRow(), 'The staff-side start still works.');

        $this->asStaff($this->seller)
            ->getJson('/api/user/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.visible', false);
    }

    public function test_another_seller_never_sees_the_conversation(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $other = $this->makeSeller($this->companyA, 'Nosy Seller');

        // No widget for them at all …
        $this->asStaff($other)
            ->getJson('/api/user/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.conversations', []);

        // … and hiding the button is not what makes it private: the endpoints
        // refuse them too, with the real ids.
        $this->asStaff($other)
            ->getJson("/api/user/clients/{$this->clientA->id}/direct-chat/{$thread->id}/messages")
            ->assertNotFound();

        $this->asStaff($other)
            ->postJson("/api/user/clients/{$this->clientA->id}/direct-chat/{$thread->id}/reply", [
                'content' => 'Let me jump in here',
            ])
            ->assertNotFound();
    }

    public function test_a_client_from_another_company_cannot_read_the_conversation(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $otherUser   = $this->makeUser($this->companyB, 'Rival Client', 'client');
        $this->makeClient($this->companyB, $otherUser);

        $this->asClient($otherUser)
            ->getJson("/api/client/seller-chat/{$thread->id}")
            ->assertNotFound();

        $this->asClient($otherUser)
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", ['content' => 'hello?'])
            ->assertNotFound();

        $this->asClient($otherUser)
            ->getJson('/api/client/seller-chat')
            ->assertOk()
            ->assertJsonPath('data.conversations', []);
    }

    public function test_a_seller_from_another_company_can_never_be_handed_the_client(): void
    {
        $foreignSeller = $this->makeSeller($this->companyB, 'Foreign Seller');

        $this->expectException(\RuntimeException::class);

        ClientSellerChatService::startFromClient($this->clientA, $foreignSeller);
    }

    public function test_company_admin_sees_it_only_for_companies_they_own(): void
    {
        // Before anyone reaches out, no Admin gets a widget either.
        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.visible', false);

        $this->talkToMySeller()->assertOk();

        $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.conversations.0.client_name', 'John Client');

        // The other tenant owns neither the company nor the conversation.
        $theirs = $this->actingAs($this->otherAdmin, 'admin')
            ->getJson('/api/admin/assistant/conversations');
        $theirs->assertOk()->assertJsonPath('data.visible', false);
        $this->assertStringNotContainsString('John Client', $theirs->getContent());
    }

    // ── The conversation itself ──────────────────────────────────────────────

    public function test_the_client_sees_the_sellers_reply_without_going_through_the_assistant(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        // The client speaks first.
        $this->asClient()
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", [
                'content' => 'I want to discuss my project.',
            ])
            ->assertCreated();

        // The seller sees it on the existing staff surface …
        $staffView = $this->asStaff($this->seller)
            ->getJson("/api/user/clients/{$this->clientA->id}/direct-chat/{$thread->id}/messages");
        $staffView->assertOk();
        $this->assertContains(
            'I want to discuss my project.',
            collect($staffView->json('data'))->pluck('content')->all()
        );

        // … and replies through it.
        $this->asStaff($this->seller)
            ->postJson("/api/user/clients/{$this->clientA->id}/direct-chat/{$thread->id}/reply", [
                'content' => "Sure, I'd be happy to help.",
            ])
            ->assertCreated();

        // The client reads the reply straight from the conversation — no
        // assistant turn in between.
        $clientView = $this->asClient()->getJson("/api/client/seller-chat/{$thread->id}");
        $clientView->assertOk()
            ->assertJsonPath('data.title', 'John Smith')
            // "Seller", not "Account Manager" — clients.account_manager is its
            // own column and often somebody else, so calling every seller an
            // account manager told the client something not necessarily true.
            ->assertJsonPath('data.role', 'Seller');

        $messages = collect($clientView->json('data.messages'));
        $reply    = $messages->firstWhere('content', "Sure, I'd be happy to help.");

        $this->assertNotNull($reply, 'The client must see the seller reply.');
        $this->assertFalse($reply['mine']);
        $this->assertSame('John Smith', $reply['sender']);
        $this->assertSame('Seller', $reply['sender_role']);
    }

    public function test_the_client_is_notified_of_a_reply_with_a_link_into_the_portal(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $this->asStaff($this->seller)
            ->postJson("/api/user/clients/{$this->clientA->id}/direct-chat/{$thread->id}/reply", [
                'content' => 'Checking in on your project.',
            ])
            ->assertCreated();

        $notification = DB::table('notifications')
            ->where('user_id', $this->clientUser->id)
            ->where('type', 'client_chat_message')
            ->first();

        $this->assertNotNull($notification);
        // Must stay inside /client/... — the portal bell refuses anything else.
        $this->assertSame(
            "/client/dashboard?assistant_chat={$thread->id}",
            json_decode($notification->data, true)['link'] ?? null
        );
    }

    /** The assistant hands off and steps out. It is never in the room. */
    public function test_ai_assistance_is_not_a_participant_and_writes_nothing_after_the_welcome(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $this->asClient()
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", ['content' => 'Hello there'])
            ->assertCreated();

        $senders = ChatMessage::where('thread_id', $thread->id)->pluck('sender_id')->unique()->values()->all();

        // Only two people ever wrote in here.
        sort($senders);
        $expected = collect([$this->seller->id, $this->clientUser->id])->sort()->values()->all();
        $this->assertSame($expected, $senders);

        // And the assistant's own transcript is a separate thing entirely.
        $this->assertSame(
            0,
            ChatMessage::where('thread_id', $thread->id)
                ->where('content', 'like', '%AI Assistance%')
                ->count()
        );
    }

    // ── Multiple companies ───────────────────────────────────────────────────

    public function test_a_client_at_two_companies_must_choose_before_anything_is_created(): void
    {
        // The same login also has a Client record at a second company.
        $companyC = $this->makeCompany($this->admin, 'Beta Industries');
        $this->makeClient($companyC, $this->clientUser);

        $asked = $this->talkToMySeller();
        $asked->assertOk();

        $this->assertSame(
            ['select_company', 'select_company'],
            array_column($asked->json('data.actions'), 'action')
        );
        $this->assertStringContainsString('Which company', $asked->json('data.message'));
        // Nothing may be created, and no seller told, before the choice.
        $this->assertNull($this->threadRow());
        $this->assertSame(0, DB::table('notifications')->where('type', 'client_chat_started')->count());

        // Choosing Acme then opens the Acme conversation with the Acme seller.
        $chosen = $this->asClient()->postJson('/api/client/assistant/action', [
            'action'  => 'select_company',
            'payload' => ['company_id' => $this->companyA, 'then' => 'talk_to_seller'],
        ]);

        $chosen->assertOk();
        $this->assertStringContainsString('John Smith', $chosen->json('data.message'));
        $this->assertNotNull($this->threadRow());
        $this->assertSame($this->companyA, (int) $this->threadRow()->company_id);
    }

    public function test_no_seller_is_never_invented_and_no_conversation_is_created(): void
    {
        $this->clientA->update(['lead_id' => null, 'account_manager' => null]);

        $response = $this->talkToMySeller();

        $response->assertOk();
        $this->assertStringContainsString("couldn't find an assigned seller", $response->json('data.message'));
        $this->assertNull($this->threadRow());
    }

    // ── The staff side is the message, not an assistant ──────────────────────

    /**
     * The seller must not have to ask a chatbot anything to reach their client.
     *
     * The staff endpoint returns the conversation and the message itself —
     * there is no prompt, no chips and no model on this side at all.
     */
    public function test_the_seller_gets_the_clients_own_message_with_no_assistant_in_between(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $this->asClient()
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", [
                'content' => 'Hi, I want to discuss my project.',
            ])
            ->assertCreated();

        $list = $this->asStaff($this->seller)->getJson('/api/user/assistant/conversations');

        $list->assertOk()
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.conversations.0.client_name', 'John Client')
            ->assertJsonPath('data.conversations.0.company_name', 'Acme Corporation')
            // The client's actual words, not a summary and not an AI turn.
            ->assertJsonPath('data.conversations.0.last_message', 'Hi, I want to discuss my project.');

        $this->assertSame(1, $list->json('data.unread_total'));

        // No assistant vocabulary anywhere in the staff payload.
        $this->assertStringNotContainsString('suggestions', $list->getContent());
        $this->assertStringNotContainsString("What's on your mind", $list->getContent());
    }

    /**
     * Opening the widget clears the badge.
     *
     * Nothing on the staff side wrote chat_participants.last_read_at for these
     * threads before, so the count could only ever grow — a seller who had read
     * everything still carried it.
     */
    public function test_opening_the_widget_clears_the_unread_count(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $this->asClient()
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", ['content' => 'First question'])
            ->assertCreated();

        $this->asStaff($this->seller)
            ->getJson('/api/user/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 1);

        // Opening the widget is having seen it.
        $this->asStaff($this->seller)
            ->postJson('/api/user/assistant/read')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 0);

        // And it stays cleared on the next look.
        $this->asStaff($this->seller)
            ->getJson('/api/user/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 0)
            ->assertJsonPath('data.visible', true);

        // A new client message counts again.
        //
        // The clock is advanced first because sent_at and last_read_at are both
        // one-second `timestamp` columns: without this the new message lands in
        // the same second as the read above and the strict `>` comparison
        // cannot separate them. See StaffAssistantService::unreadCount(), which
        // documents why `>` is the right side of that trade.
        $this->travel(2)->seconds();

        $this->asClient()
            ->postJson("/api/client/seller-chat/{$thread->id}/messages", ['content' => 'One more thing'])
            ->assertCreated();

        $this->asStaff($this->seller)
            ->getJson('/api/user/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 1);
    }

    /** Marking read can only ever touch conversations this seller is in. */
    public function test_marking_read_cannot_touch_another_sellers_conversation(): void
    {
        $this->talkToMySeller()->assertOk();
        $thread = $this->threadRow();

        $other = $this->makeSeller($this->companyA, 'Nosy Seller');

        $this->asStaff($other)
            ->postJson('/api/user/assistant/read', ['thread_id' => $thread->id])
            ->assertOk()
            ->assertJsonPath('data.visible', false);

        // The real participant's own read state is untouched by that attempt.
        $this->assertNull(
            ChatParticipant::where('thread_id', $thread->id)
                ->where('user_id', $this->seller->id)
                ->value('last_read_at')
        );
    }

    public function test_the_staff_and_admin_endpoints_require_their_own_session(): void
    {
        $this->getJson('/api/user/assistant/conversations')->assertUnauthorized();
        $this->getJson('/api/admin/assistant/conversations')->assertUnauthorized();
        $this->getJson('/api/client/seller-chat')->assertUnauthorized();
    }
}
