<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectChatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Talk to Seller", project by project.
 *
 * The hand-off used to pick ONE seller per account (lead owner, else account
 * manager, else the newest project's seller) and open the account-level Direct
 * Chat. A client with three projects and three different sellers therefore had
 * one conversation, with whichever seller that order happened to choose.
 *
 * It now asks WHICH PROJECT first, and opens that project's OWN existing
 * conversation — the same project_group thread the seller reads at
 * /projects/{id}/chat and Company Admin reads on their project page. Nothing
 * new is created: this file pins that the flow routes into the existing thread,
 * that each project stays its own conversation, that another company's or
 * another client's project is unreachable, and that the fallback for a client
 * with no projects (the reason the project thread was abandoned once before)
 * still works.
 *
 * The AI is switched off throughout, exactly as ClientAssistantWidgetTest does,
 * so intent classification falls back to the deterministic rules and no test
 * here makes a network call.
 */
class ClientTalkToSellerTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminId;
    private int $companyA;
    private int $companyB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.groq.key'       => null,
            'services.gemini.key'     => null,
            'services.openrouter.key' => null,
        ]);

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'TTS Admin', 'email' => 'ttsadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyA = $this->makeCompany('Talk Co A');
        $this->companyB = $this->makeCompany('Talk Co B');

        $this->user = User::create([
            'company_id' => $this->companyA,
            'name' => 'Portal Client', 'email' => 'tts+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['client_portal', 'projects'] as $module) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $module, 'is_enabled' => true,
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function makeClient(int $companyId, ?User $owner = null): Client
    {
        return Client::create([
            'company_id'    => $companyId,
            'user_id'       => ($owner ?? $this->user)->id,
            'name'          => 'Portal Client',
            'email'         => ($owner ?? $this->user)->email,
            'portal_access' => true,
        ]);
    }

    private function makeSeller(int $companyId, string $name, ?string $lastSeenAt = null): User
    {
        return User::create([
            'company_id'   => $companyId,
            'name'         => $name,
            'email'        => 'seller+' . uniqid() . '@example.test',
            'password'     => Hash::make('password'),
            'role_type'    => 'seller',
            'is_active'    => true,
            'status'       => 'active',
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    private function makeProject(Client $client, string $name, ?int $sellerId): Project
    {
        return Project::create([
            'company_id' => $client->company_id,
            'client_id'  => $client->id,
            'seller_id'  => $sellerId,
            'name'       => $name,
            'status'     => 'active',
        ]);
    }

    private function actingAsClient(): self
    {
        Sanctum::actingAs($this->user, ['role:client']);

        return $this;
    }

    /** @return array<int, string> */
    private function actionIds(array $json): array
    {
        return array_column($json['data']['actions'] ?? [], 'action');
    }

    private function askForSeller()
    {
        return $this->actingAsClient()
            ->postJson('/api/client/assistant/message', ['text' => 'I want to talk to my seller']);
    }

    // ── One project ──────────────────────────────────────────────────────────

    public function test_a_single_project_opens_that_projects_own_conversation(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Sara Seller');
        $project = $this->makeProject($client, 'AI Application', $seller->id);

        $response = $this->askForSeller();

        $response->assertOk()
            ->assertJsonPath('data.open_project_chat.project_id', $project->id)
            ->assertJsonPath('data.open_project_chat.project_name', 'AI Application')
            ->assertJsonPath('data.open_project_chat.seller.name', 'Sara Seller');

        // The project hand-off replaces the account-level Direct Chat one —
        // not both, or the widget would open two conversations at once.
        $this->assertNull($response->json('data.open_seller_chat'));
    }

    // ── Several projects, several sellers ────────────────────────────────────

    public function test_several_projects_are_offered_as_a_choice_before_any_conversation_opens(): void
    {
        $client = $this->makeClient($this->companyA);
        $one = $this->makeProject($client, 'AI Application', $this->makeSeller($this->companyA, 'John Seller')->id);
        $two = $this->makeProject($client, 'E-Commerce Website', $this->makeSeller($this->companyA, 'Mia Seller')->id);

        $response = $this->askForSeller();

        $response->assertOk();
        $this->assertSame(
            ['select_project_seller', 'select_project_seller'],
            $this->actionIds($response->json())
        );
        $this->assertNull($response->json('data.open_project_chat'), 'Nothing may open until a project is picked.');

        $labels = array_column($response->json('data.actions'), 'label');
        $this->assertContains('AI Application · John Seller', $labels);
        $this->assertContains('E-Commerce Website · Mia Seller', $labels);

        $ids = array_map(fn ($a) => $a['payload']['project_id'], $response->json('data.actions'));
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $ids);
    }

    public function test_picking_a_project_opens_that_projects_seller_and_not_another(): void
    {
        $client = $this->makeClient($this->companyA);
        $this->makeProject($client, 'AI Application', $this->makeSeller($this->companyA, 'John Seller')->id);
        $second = $this->makeProject($client, 'E-Commerce Website', $this->makeSeller($this->companyA, 'Mia Seller')->id);

        $response = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'select_project_seller',
            'payload' => ['project_id' => $second->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.open_project_chat.project_id', $second->id)
            ->assertJsonPath('data.open_project_chat.seller.name', 'Mia Seller');
    }

    // ── Multi-company ────────────────────────────────────────────────────────

    public function test_a_multi_company_client_chooses_a_company_first(): void
    {
        $this->makeClient($this->companyA);
        $this->makeClient($this->companyB);

        $response = $this->askForSeller();

        $response->assertOk();
        $this->assertSame(['select_company', 'select_company'], $this->actionIds($response->json()));
        $this->assertNull($response->json('data.open_project_chat'));
    }

    public function test_only_the_chosen_companys_projects_are_offered(): void
    {
        $clientA = $this->makeClient($this->companyA);
        $clientB = $this->makeClient($this->companyB);
        $mine = $this->makeProject($clientA, 'Company A Project', $this->makeSeller($this->companyA, 'A Seller')->id);
        $this->makeProject($clientB, 'Company B Project', $this->makeSeller($this->companyB, 'B Seller')->id);
        // A second Company A project, so the flow lists rather than auto-opens.
        $alsoMine = $this->makeProject($clientA, 'Company A Second', $this->makeSeller($this->companyA, 'A2 Seller')->id);

        $this->askForSeller()->assertOk();

        $response = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'select_company',
            'payload' => ['company_id' => $this->companyA, 'then' => 'talk_to_seller'],
        ]);

        $response->assertOk();
        $ids = array_map(fn ($a) => $a['payload']['project_id'], $response->json('data.actions'));
        $this->assertEqualsCanonicalizing([$mine->id, $alsoMine->id], $ids);
        $this->assertStringNotContainsString('Company B Project', $response->getContent());
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_another_clients_project_cannot_be_opened(): void
    {
        $this->makeClient($this->companyA);

        $otherUser = User::create([
            'company_id' => $this->companyA,
            'name' => 'Other Client', 'email' => 'other+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $theirs = $this->makeProject(
            $this->makeClient($this->companyA, $otherUser),
            'Secret Project',
            $this->makeSeller($this->companyA, 'Their Seller')->id
        );

        $response = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'select_project_seller',
            'payload' => ['project_id' => $theirs->id],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.open_project_chat'));
        $this->assertStringContainsString('not available for your account', $response->json('data.message'));
        $this->assertStringNotContainsString('Secret Project', $response->getContent());
    }

    public function test_the_project_chat_itself_is_unreachable_for_another_clients_project(): void
    {
        $this->makeClient($this->companyA);

        $otherUser = User::create([
            'company_id' => $this->companyA,
            'name' => 'Other Client', 'email' => 'other2+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $theirs = $this->makeProject(
            $this->makeClient($this->companyA, $otherUser),
            'Secret Project',
            $this->makeSeller($this->companyA, 'Their Seller')->id
        );

        $this->actingAsClient()
            ->getJson("/api/client/projects/{$theirs->id}/chat")
            ->assertNotFound();
    }

    // ── The window's own payload ─────────────────────────────────────────────

    public function test_the_chat_endpoint_carries_the_project_and_seller_the_window_renders(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Sara Seller', now()->toDateTimeString());
        $project = $this->makeProject($client, 'AI Application', $seller->id);

        $this->actingAsClient()
            ->getJson("/api/client/projects/{$project->id}/chat")
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.project.name', 'AI Application')
            ->assertJsonPath('data.project.company_name', 'Talk Co A')
            ->assertJsonPath('data.seller.name', 'Sara Seller')
            ->assertJsonPath('data.seller.online', true);
    }

    // ── Presence ─────────────────────────────────────────────────────────────

    public function test_a_seller_who_has_not_been_seen_recently_reads_offline(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Stale Seller', now()->subHour()->toDateTimeString());
        $project = $this->makeProject($client, 'AI Application', $seller->id);

        $this->actingAsClient()
            ->getJson("/api/client/projects/{$project->id}/chat")
            ->assertOk()
            ->assertJsonPath('data.seller.online', false);
    }

    /** Never seen at all is Offline — not "probably around". */
    public function test_a_seller_with_no_recorded_activity_reads_offline(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Unseen Seller');
        $project = $this->makeProject($client, 'AI Application', $seller->id);

        $this->assertNull($seller->fresh()->last_seen_at);
        $this->actingAsClient()
            ->getJson("/api/client/projects/{$project->id}/chat")
            ->assertOk()
            ->assertJsonPath('data.seller.online', false);
    }

    /** Presence comes from real requests — this is the middleware doing it. */
    public function test_making_a_request_marks_a_user_as_seen(): void
    {
        $client = $this->makeClient($this->companyA);
        $this->makeProject($client, 'AI Application', $this->makeSeller($this->companyA, 'Sara Seller')->id);

        $this->assertNull($this->user->fresh()->last_seen_at);

        $this->actingAsClient()->getJson('/api/client/projects')->assertOk();

        $this->assertNotNull(
            $this->user->fresh()->last_seen_at,
            'An authenticated request must stamp last_seen_at (App\Http\Middleware\TouchLastSeen).'
        );
        $this->assertTrue($this->user->fresh()->isOnline());
    }

    // ── Notifications ────────────────────────────────────────────────────────

    /**
     * The client's notification has to land ON the conversation, which lives in
     * a floating window rather than on a page of its own — so the link carries
     * the project id the window opens from.
     */
    public function test_a_clients_project_chat_notification_deep_links_to_the_window(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Sara Seller');
        $project = $this->makeProject($client, 'AI Application', $seller->id);

        ProjectChatService::addClient($project, $this->user->id);

        $notification = Notification::where('user_id', $this->user->id)
            ->where('type', 'project_chat_added')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame("/client/dashboard?seller_chat={$project->id}", $notification->data['link']);
    }

    // ── The no-project fallback ──────────────────────────────────────────────

    /**
     * The reason this hand-off left the project thread once before: a client
     * with no project at all had nowhere to go. They still reach their seller,
     * on the account-level Direct Chat, exactly as before.
     */
    public function test_a_client_with_no_project_still_reaches_their_seller(): void
    {
        $client = $this->makeClient($this->companyA);
        $seller = $this->makeSeller($this->companyA, 'Nadia Seller');
        $client->update(['account_manager' => $seller->id]);

        $response = $this->askForSeller();

        $response->assertOk();
        $this->assertNull($response->json('data.open_project_chat'));
        $this->assertNotNull($response->json('data.open_seller_chat.thread_id'));
        $this->assertStringContainsString('Nadia Seller', $response->json('data.message'));
    }

    /** A project with no seller assigned is not something to "talk to". */
    public function test_a_project_without_a_seller_falls_back_rather_than_offering_nobody(): void
    {
        $client = $this->makeClient($this->companyA);
        $this->makeProject($client, 'Unstaffed Project', null);

        $response = $this->askForSeller();

        $response->assertOk();
        $this->assertNull($response->json('data.open_project_chat'));
        $this->assertStringContainsString("couldn't find an assigned seller", $response->json('data.message'));
    }
}
