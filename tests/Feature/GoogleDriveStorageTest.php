<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\User;
use App\Services\CompanyStorageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The centralized company storage / Google Drive integration's load-bearing
 * properties:
 *
 *  1. ONE QUOTA CALCULATION. CompanyStorageService sums real file sizes
 *     across every attachment location, filtered to storage_type='internal'
 *     — never a hardcoded or per-module figure.
 *
 *  2. THE UPLOAD DECISION. Under quota → stored internally exactly as
 *     before this feature existed. Over quota with no Drive connection →
 *     the exact "storage limit reached" response, never a silent failure.
 *     Over quota WITH a connection → transparently routed to Drive.
 *
 *  3. COMPANY ISOLATION. Company A's usage, connection and files are never
 *     visible to or counted against Company B.
 *
 *  4. ONLY COMPANY ADMIN manages the Drive connection — there is no
 *     staff-guard route for it at all.
 *
 *  5. DISCONNECTING never deletes a file or an attachment row.
 *
 * Google itself is faked at the HTTP boundary (Http::fake), same convention
 * SavedPaymentMethodTest already uses for Stripe — these tests are about
 * OUR decision logic and data, not Google's API.
 */
class GoogleDriveStorageTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyA;
    private int $companyB;
    private $admin; // CompanyAdmin
    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Drive Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id'          => $packageId,
            'name'                => 'Drive Admin',
            'email'               => 'driveadmin+' . uniqid() . '@example.test',
            'password'            => Hash::make('password'),
            'subscription_status' => 'active',
            'created_at'          => now(), 'updated_at' => now(),
        ]);
        $this->admin = \App\Models\CompanyAdmin::findOrFail($adminId);

        $this->companyA = $this->makeCompany($adminId, 'Drive Company A');
        $this->companyB = $this->makeCompany($adminId, 'Drive Company B');

        $this->projectA = Project::create([
            'company_id' => $this->companyA, 'name' => 'Project A', 'status' => 'active',
        ]);
    }

    private function makeCompany(int $adminId, string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => $name, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The attachment routes exercised here sit behind `module:projects` —
        // see routes/api.php.
        DB::table('company_modules')->insert([
            'company_id' => $id, 'module_key' => 'projects', 'is_enabled' => true,
        ]);

        return $id;
    }

    private function makeStaffUser(int $companyId): User
    {
        $user = User::create([
            'company_id' => $companyId,
            'name'       => 'Staff ' . uniqid(),
            'email'      => 'staff+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => 'project_manager',
            'is_active'  => true,
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('user_company_permissions')->insert([
            'user_id' => $user->id, 'company_id' => $companyId,
            'module_key' => 'project_management', 'permission_key' => 'canViewAllCompanyProjects',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_company_permissions')->insert([
            'user_id' => $user->id, 'company_id' => $companyId,
            'module_key' => 'project_management', 'permission_key' => 'canUploadProjectAttachments',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function asStaff(User $user): self
    {
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Active-Company-Id', (string) $user->company_id);

        return $this;
    }

    private function fakeDriveOAuthAndApi(): void
    {
        Http::fake([
            '*oauth2.googleapis.com/token*' => Http::response([
                'access_token'  => 'fake_access_token',
                'refresh_token' => 'fake_refresh_token',
                'expires_in'    => 3600,
            ], 200),
            '*googleapis.com/oauth2/v2/userinfo*' => Http::response([
                'email' => 'company-storage@example.test', 'id' => 'google-account-id-123',
            ], 200),
            // Upload — matched BEFORE the plain "/files" folder patterns
            // below, since Http::fake() array matching takes the first
            // pattern that matches and this URL would otherwise also match
            // the bare "/files" glob.
            '*googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'fake-drive-file-id'], 200),
            // Folder lookup (search) always reports "not found" so the
            // service creates fresh ones — matches a brand-new connection.
            '*googleapis.com/drive/v3/files?*' => Http::response(['files' => []], 200),
            '*googleapis.com/drive/v3/files' => Http::response(['id' => 'fake-folder-id'], 200),
        ]);
    }

    // ── CompanyStorageService — pure quota math ─────────────────────────────

    public function test_used_storage_sums_every_attachment_location_for_internal_rows_only(): void
    {
        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'a.pdf', 'file_name' => 'a.pdf', 'file_path' => 'x/a.pdf',
            'file_type' => 'application/pdf', 'file_size' => 1_000_000, 'storage_type' => 'internal',
        ]);
        // A Drive-stored row must NOT count toward internal usage.
        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'b.pdf', 'file_name' => 'b.pdf', 'file_path' => null,
            'file_type' => 'application/pdf', 'file_size' => 5_000_000, 'storage_type' => 'google_drive',
            'google_drive_file_id' => 'drive-1', 'google_drive_folder_id' => 'folder-1',
        ]);

        $this->assertEquals(1_000_000, CompanyStorageService::usedBytes($this->companyA));
    }

    public function test_a_second_companys_usage_is_never_counted(): void
    {
        $projectB = Project::create(['company_id' => $this->companyB, 'name' => 'Project B', 'status' => 'active']);

        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'a.pdf', 'file_name' => 'a.pdf', 'file_path' => 'x/a.pdf',
            'file_type' => 'application/pdf', 'file_size' => 2_000_000, 'storage_type' => 'internal',
        ]);
        ProjectAttachment::create([
            'company_id' => $this->companyB, 'project_id' => $projectB->id,
            'original_name' => 'c.pdf', 'file_name' => 'c.pdf', 'file_path' => 'y/c.pdf',
            'file_type' => 'application/pdf', 'file_size' => 9_000_000, 'storage_type' => 'internal',
        ]);

        $this->assertEquals(2_000_000, CompanyStorageService::usedBytes($this->companyA));
        $this->assertEquals(9_000_000, CompanyStorageService::usedBytes($this->companyB));
    }

    public function test_canstoreinternally_respects_the_configured_limit(): void
    {
        config(['services.company_storage.limit_mb' => 1]); // 1 MB for this test

        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'a.pdf', 'file_name' => 'a.pdf', 'file_path' => 'x/a.pdf',
            'file_type' => 'application/pdf', 'file_size' => 900_000, 'storage_type' => 'internal',
        ]);

        $this->assertTrue(CompanyStorageService::canStoreInternally($this->companyA, 50_000));
        $this->assertFalse(CompanyStorageService::canStoreInternally($this->companyA, 200_000));
    }

    // ── Upload decision, end-to-end through a real endpoint ─────────────────

    public function test_upload_stores_internally_when_quota_allows(): void
    {
        $user = $this->makeStaffUser($this->companyA);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(201);

        $this->assertSame('internal', $response->json('data.storage_type'));
        $this->assertNotNull($response->json('data.file_path'));
        $this->assertNull($response->json('data.google_drive_file_id'));

        Storage::assertExists($response->json('data.file_path'));
    }

    public function test_upload_over_quota_without_drive_returns_the_exact_storage_full_response(): void
    {
        config(['services.company_storage.limit_mb' => 1]);
        $user = $this->makeStaffUser($this->companyA);

        // Fill the quota first.
        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'filler.pdf', 'file_name' => 'filler.pdf', 'file_path' => 'x/filler.pdf',
            'file_type' => 'application/pdf', 'file_size' => 1_048_576, 'storage_type' => 'internal',
        ]);

        $file = UploadedFile::fake()->create('too-big.pdf', 50, 'application/pdf');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(422);

        $this->assertSame('storage_limit_reached', $response->json('errors.error_code'));
        $this->assertStringContainsString('Connect Google Drive', $response->json('message'));

        // Nothing was written for the rejected upload.
        $this->assertSame(
            1,
            ProjectAttachment::where('project_id', $this->projectA->id)->count(),
            'A rejected upload must not leave a stray attachment row.'
        );
    }

    public function test_upload_over_quota_with_drive_connected_falls_back_to_drive_transparently(): void
    {
        config(['services.company_storage.limit_mb' => 1]);
        $this->fakeDriveOAuthAndApi();

        GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'google_account_email' => 'files@example.test', 'google_account_id' => 'gid-1',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);

        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'filler.pdf', 'file_name' => 'filler.pdf', 'file_path' => 'x/filler.pdf',
            'file_type' => 'application/pdf', 'file_size' => 1_048_576, 'storage_type' => 'internal',
        ]);

        $user = $this->makeStaffUser($this->companyA);
        $file = UploadedFile::fake()->create('now-on-drive.pdf', 50, 'application/pdf');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(201);

        $this->assertSame('google_drive', $response->json('data.storage_type'));
        $this->assertSame('fake-drive-file-id', $response->json('data.google_drive_file_id'));
        $this->assertNull($response->json('data.file_path'));

        // The pre-existing internal file must never have moved.
        $this->assertDatabaseHas('project_attachments', [
            'original_name' => 'filler.pdf', 'storage_type' => 'internal', 'file_path' => 'x/filler.pdf',
        ]);
    }

    // ── Company isolation ────────────────────────────────────────────────────

    public function test_a_companys_drive_connection_is_never_visible_to_another_company(): void
    {
        GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'google_account_email' => 'a-only@example.test',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');

        $bodyForA = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();
        $this->assertTrue($bodyForA->json('data.drive.connected'));
        $this->assertSame('a-only@example.test', $bodyForA->json('data.drive.email'));

        $bodyForB = $this->withHeader('X-Active-Company-Id', (string) $this->companyB)
            ->getJson('/api/admin/storage')->assertOk();
        $this->assertFalse($bodyForB->json('data.drive.connected'));
        $this->assertNull($bodyForB->json('data.drive.email'));
    }

    // ── Permissions — Company Admin only ─────────────────────────────────────

    public function test_there_is_no_staff_route_to_manage_the_drive_connection(): void
    {
        $user = $this->makeStaffUser($this->companyA);

        // No such route exists at all for the staff guard — not a 403, a 404.
        $this->asStaff($user)->getJson('/api/user/storage/google-drive/connect-url')->assertStatus(404);
        $this->asStaff($user)->postJson('/api/user/storage/google-drive/disconnect')->assertStatus(404);
    }

    public function test_only_company_admin_can_request_a_connect_url(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        config(['services.google_drive.client_id' => 'x', 'services.google_drive.client_secret' => 'y', 'services.google_drive.redirect' => 'z']);

        $response = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage/google-drive/connect-url')
            ->assertOk();

        $this->assertStringContainsString('accounts.google.com', $response->json('data.url'));
    }

    // ── Disconnect never deletes anything ────────────────────────────────────

    public function test_disconnect_keeps_existing_drive_attachments_and_never_deletes_files(): void
    {
        Http::fake(['*oauth2.googleapis.com/revoke*' => Http::response([], 200)]);

        $connection = GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'google_account_email' => 'files@example.test',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);

        $attachment = ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'kept.pdf', 'file_name' => 'kept.pdf', 'file_path' => null,
            'file_type' => 'application/pdf', 'file_size' => 500_000, 'storage_type' => 'google_drive',
            'google_drive_file_id' => 'drive-kept-1', 'google_drive_folder_id' => 'folder-1',
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->postJson('/api/admin/storage/google-drive/disconnect')
            ->assertOk();

        $this->assertSame('disconnected', $connection->fresh()->status);

        // The attachment row — the only durable record of that Drive file —
        // is completely untouched.
        $this->assertDatabaseHas('project_attachments', [
            'id' => $attachment->id, 'storage_type' => 'google_drive', 'google_drive_file_id' => 'drive-kept-1',
        ]);

        // And a NEW upload past quota now correctly falls back to the
        // "connect Google Drive" message again, since the connection is off.
        config(['services.company_storage.limit_mb' => 0]);
        $user = $this->makeStaffUser($this->companyA);
        $file = UploadedFile::fake()->create('after-disconnect.pdf', 10, 'application/pdf');

        $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'storage_limit_reached');
    }

    // ── Download works for both storage kinds, and delete is storage-aware ──

    public function test_downloading_a_drive_backed_attachment_streams_through_this_server(): void
    {
        Http::fake([
            '*googleapis.com/drive/v3/files/drive-dl-1*' => Http::response('the-file-bytes', 200, ['Content-Type' => 'application/pdf']),
        ]);

        GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);

        $attachment = ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'remote.pdf', 'file_name' => 'remote.pdf', 'file_path' => null,
            'file_type' => 'application/pdf', 'file_size' => 14, 'storage_type' => 'google_drive',
            'google_drive_file_id' => 'drive-dl-1', 'google_drive_folder_id' => 'folder-1',
            'is_visible_to_client' => true,
        ]);

        $user = $this->makeStaffUser($this->companyA);
        DB::table('user_company_permissions')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyA,
            'module_key' => 'project_management', 'permission_key' => 'canDownloadProjectAttachments',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $content = $this->asStaff($user)
            ->get("/api/user/projects/{$this->projectA->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->streamedContent();

        $this->assertSame('the-file-bytes', $content);
    }

    // ── The REAL callback route, end to end — not a hand-built row ──────────
    //
    // Every test above creates its GoogleDriveConnection row directly via
    // ::create(), which never exercises Auth\GoogleDriveConnectionController
    // ::callback() at all. These go through the actual connect-url → Google
    // → callback round trip the real "Connect Google Drive" button drives,
    // which is the only way to catch a bug that lives specifically in that
    // controller (state handling, updateOrCreate, the disconnect/reconnect
    // sequence) rather than in the data itself.

    /** Drives one full OAuth round trip and returns the callback's redirect response. */
    private function connectViaRealOAuth(int $companyId, string $googleId = 'gid-real-1', string $email = 'real@example.test', ?string $returnTo = null)
    {
        Http::fake([
            '*oauth2.googleapis.com/token*' => Http::response([
                'access_token' => 'tok-' . uniqid(), 'refresh_token' => 'ref-' . uniqid(), 'expires_in' => 3600,
            ], 200),
            '*googleapis.com/oauth2/v2/userinfo*' => Http::response(['email' => $email, 'id' => $googleId], 200),
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $connectResponse = $this->withHeader('X-Active-Company-Id', (string) $companyId)
            ->getJson('/api/admin/storage/google-drive/connect-url' . ($returnTo ? '?' . http_build_query(['return_to' => $returnTo]) : ''))
            ->assertOk();

        $state = [];
        parse_str(parse_url($connectResponse->json('data.url'), PHP_URL_QUERY), $state);
        $this->assertArrayHasKey('state', $state, 'connect-url must produce a state param the callback can consume');

        // The callback route is intentionally unauthenticated (Google hits it
        // directly) — no Sanctum/admin guard on this request, matching how
        // the browser actually arrives here.
        return $this->get('/api/admin/google-drive/callback?' . http_build_query([
            'code' => 'fake-auth-code', 'state' => $state['state'],
        ]));
    }

    // ── return_to: the actual fix for "always redirects to Settings" ────────

    public function test_connecting_from_an_attachment_page_returns_to_that_exact_page(): void
    {
        $redirect = $this->connectViaRealOAuth(
            $this->companyA, 'gid-r1', 'r1@example.test',
            '/admin/projects/45/attachments'
        );

        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('/admin/projects/45/attachments', $location);
        $this->assertStringContainsString('drive=connected', $location);
        // Never falls through to the Settings default when a valid return_to
        // was supplied.
        $this->assertStringNotContainsString('/settings', $location);
    }

    public function test_return_to_preserves_the_originating_pages_own_query_string(): void
    {
        $redirect = $this->connectViaRealOAuth(
            $this->companyA, 'gid-r2', 'r2@example.test',
            '/client/projects/123?tab=documents'
        );

        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('tab=documents', $location, 'The page\'s own query params must survive alongside drive=connected.');
        $this->assertStringContainsString('drive=connected', $location);
    }

    public function test_an_absolute_or_protocol_relative_return_to_is_rejected_and_falls_back_to_settings(): void
    {
        foreach ([
            'https://evil.example.com/phishing',
            '//evil.example.com/phishing',
            'javascript:alert(1)',
            '/ok/../\\evil.example.com',
        ] as $malicious) {
            $redirect = $this->connectViaRealOAuth($this->companyA, 'gid-r3', 'r3@example.test', $malicious);
            $location = $redirect->headers->get('Location');

            $this->assertStringNotContainsString('evil.example.com', $location, "Open redirect via '{$malicious}' must be refused.");
            $this->assertStringContainsString('/settings', $location, "An unsafe return_to must fall back to Settings, not '{$malicious}'.");
        }
    }

    /** Google echoes `state` back even when the admin clicks Cancel — return_to must survive that path too. */
    public function test_cancelling_the_google_consent_screen_still_returns_to_the_originating_page(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $connectResponse = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage/google-drive/connect-url?' . http_build_query(['return_to' => '/tasks/9']))
            ->assertOk();

        $state = [];
        parse_str(parse_url($connectResponse->json('data.url'), PHP_URL_QUERY), $state);

        $redirect = $this->get('/api/admin/google-drive/callback?' . http_build_query([
            'error' => 'access_denied', 'state' => $state['state'],
        ]));

        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('/tasks/9', $location);
        $this->assertStringContainsString('drive=error', $location);
        $this->assertStringContainsString('reason=authorization_denied', $location);
        $this->assertDatabaseMissing('company_google_drive_connections', ['company_id' => $this->companyA]);
    }

    public function test_the_settings_pages_own_connect_button_still_defaults_to_settings(): void
    {
        // No return_to supplied at all — the pre-existing Settings > Storage
        // flow, unchanged.
        $redirect = $this->connectViaRealOAuth($this->companyA, 'gid-r4', 'r4@example.test', null);

        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('/settings', $location);
        $this->assertStringContainsString('tab=storage', $location);
        $this->assertStringContainsString('drive=connected', $location);
    }

    public function test_the_real_callback_route_connects_and_the_connection_is_immediately_usable(): void
    {
        $redirect = $this->connectViaRealOAuth($this->companyA);

        $redirect->assertRedirect();
        $this->assertStringContainsString('drive=connected', $redirect->headers->get('Location'));

        $connection = GoogleDriveConnection::where('company_id', $this->companyA)->first();
        $this->assertNotNull($connection, 'The callback must create the connection row, not just redirect.');
        $this->assertSame('connected', $connection->status);
        $this->assertTrue($connection->isUsable());
        $this->assertSame('real@example.test', $connection->google_account_email);

        // GET /admin/storage — what the Settings page reads — must agree
        // immediately, no manual refresh/second request needed.
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $status = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();
        $this->assertTrue($status->json('data.drive.connected'));
    }

    /**
     * The exact sequence the bug report describes: connect → disconnect →
     * reconnect → upload must work immediately, with no second
     * "Connect Google Drive" prompt and no stale state anywhere.
     */
    public function test_disconnect_then_reconnect_through_the_real_callback_makes_uploads_work_immediately(): void
    {
        config(['services.company_storage.limit_mb' => 0]); // force every upload to need Drive

        // 1) Connect for real.
        $this->connectViaRealOAuth($this->companyA, 'gid-a', 'first@example.test');
        $connection = GoogleDriveConnection::where('company_id', $this->companyA)->first();
        $this->assertSame('connected', $connection->status);

        // 2) Disconnect.
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->postJson('/api/admin/storage/google-drive/disconnect')
            ->assertOk();
        $this->assertSame('disconnected', $connection->fresh()->status);

        // An upload right now must show the exact "connect Google Drive"
        // response — never a silent failure, never a false "it worked".
        $user = $this->makeStaffUser($this->companyA);
        $fileWhileDisconnected = UploadedFile::fake()->create('while-disconnected.pdf', 10, 'application/pdf');
        $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $fileWhileDisconnected])
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'storage_limit_reached');

        // 3) Reconnect for real — same Google account.
        Http::fake([
            '*googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'fake-drive-file-id-2'], 200),
            '*googleapis.com/drive/v3/files?*'        => Http::response(['files' => []], 200),
            '*googleapis.com/drive/v3/files'          => Http::response(['id' => 'fake-folder-id'], 200),
        ]);
        $this->connectViaRealOAuth($this->companyA, 'gid-a', 'first@example.test');

        $reconnected = $connection->fresh();
        $this->assertSame('connected', $reconnected->status, 'Reconnecting must flip the SAME row back to connected, not leave it disconnected.');
        $this->assertTrue($reconnected->isUsable());

        // Exactly one connection row for this company — never a duplicate
        // created by the second callback.
        $this->assertSame(
            1,
            GoogleDriveConnection::where('company_id', $this->companyA)->count(),
            'Reconnecting must update the existing row, never insert a second one.'
        );

        // 4) The very next upload must go straight to Drive — no second
        // "connect Google Drive" prompt, no stale cached state anywhere in
        // the request path (every read here is a fresh DB query — see
        // AttachmentStorageService::store()).
        $fileAfterReconnect = UploadedFile::fake()->create('after-reconnect.pdf', 10, 'application/pdf');
        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $fileAfterReconnect])
            ->assertStatus(201);

        $this->assertSame('google_drive', $response->json('data.storage_type'));
        $this->assertSame('fake-drive-file-id-2', $response->json('data.google_drive_file_id'));
    }

    /** A stale/replayed/forged state must never connect anything, and must never 500. */
    public function test_an_invalid_or_reused_state_is_rejected_safely(): void
    {
        $redirect = $this->get('/api/admin/google-drive/callback?' . http_build_query([
            'code' => 'whatever', 'state' => 'this-state-was-never-issued',
        ]));

        $redirect->assertRedirect();
        $this->assertStringContainsString('drive=error', $redirect->headers->get('Location'));
        $this->assertStringContainsString('reason=session_expired', $redirect->headers->get('Location'));
        $this->assertDatabaseMissing('company_google_drive_connections', ['company_id' => $this->companyA]);
    }

    // ── Real Google Drive account quota (Storage Tracking & Progress) ───────

    private function makeConnectedDrive(): GoogleDriveConnection
    {
        return GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'google_account_email' => 'quota@example.test',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);
    }

    public function test_show_returns_the_real_drive_quota_fetched_from_google(): void
    {
        $this->makeConnectedDrive();
        Http::fake([
            '*googleapis.com/drive/v3/about*' => Http::response([
                'storageQuota' => ['limit' => '16106127360', 'usage' => '7945555967'], // 15GB / ~7.4GB
            ], 200),
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $response = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();

        $this->assertFalse($response->json('data.drive_quota.unlimited'));
        $this->assertSame(16106127360, $response->json('data.drive_quota.total_bytes'));
        $this->assertSame(7945555967, $response->json('data.drive_quota.used_bytes'));
        $this->assertNotNull($response->json('data.drive_quota.last_synced_at'));
        $this->assertFalse($response->json('data.drive_quota.stale'));
    }

    public function test_drive_quota_is_null_when_not_connected(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $response = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();

        $this->assertNull($response->json('data.drive_quota'));
    }

    public function test_an_account_with_no_reported_limit_is_treated_as_unlimited(): void
    {
        $this->makeConnectedDrive();
        Http::fake([
            '*googleapis.com/drive/v3/about*' => Http::response(['storageQuota' => ['usage' => '500000']], 200),
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $response = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();

        $this->assertTrue($response->json('data.drive_quota.unlimited'));
        $this->assertNull($response->json('data.drive_quota.total_bytes'));
        $this->assertNull($response->json('data.drive_quota.used_percentage'));
    }

    public function test_a_failed_quota_fetch_falls_back_to_the_last_known_values_and_never_errors(): void
    {
        $connection = $this->makeConnectedDrive();
        $connection->update([
            'quota_total_bytes' => 1000, 'quota_used_bytes' => 400,
            'quota_synced_at' => now()->subDays(3), // well past the TTL, forces a live refetch attempt
        ]);

        Http::fake(['*googleapis.com/drive/v3/about*' => Http::response('server error', 500)]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $response = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson('/api/admin/storage')->assertOk();

        $this->assertSame(1000, $response->json('data.drive_quota.total_bytes'));
        $this->assertSame(400, $response->json('data.drive_quota.used_bytes'));
        $this->assertTrue($response->json('data.drive_quota.stale'));
    }

    public function test_a_passive_page_view_does_not_refetch_within_the_cache_window(): void
    {
        $connection = $this->makeConnectedDrive();
        $connection->update([
            'quota_total_bytes' => 1000, 'quota_used_bytes' => 200, 'quota_synced_at' => now()->subMinutes(2),
        ]);

        Http::fake(['*googleapis.com/drive/v3/about*' => Http::response(['storageQuota' => ['limit' => '9999', 'usage' => '9999']], 200)]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA)->getJson('/api/admin/storage')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_refresh_button_forces_a_fresh_fetch_but_throttles_a_double_click(): void
    {
        $this->makeConnectedDrive();
        Http::fake([
            '*googleapis.com/drive/v3/about*' => Http::sequence()
                ->push(['storageQuota' => ['limit' => '1000', 'usage' => '100']], 200)
                ->push(['storageQuota' => ['limit' => '1000', 'usage' => '999']], 200),
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->postJson('/api/admin/storage/google-drive/refresh')->assertOk()
            ->assertJsonPath('data.drive_quota.used_bytes', 100);

        // Immediately forcing again is throttled (< 30s since the last sync)
        // — served from cache, never a second live call to Google.
        $second = $this->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->postJson('/api/admin/storage/google-drive/refresh')->assertOk();

        $this->assertSame(100, $second->json('data.drive_quota.used_bytes'));
        Http::assertSentCount(1);
    }

    public function test_a_drive_upload_invalidates_the_quota_cache_so_the_next_view_refetches(): void
    {
        $this->fakeDriveOAuthAndApi();
        Http::fake([
            '*googleapis.com/drive/v3/about*' => Http::response(['storageQuota' => ['limit' => '1000', 'usage' => '100']], 200),
        ]);

        $connection = $this->makeConnectedDrive();
        $connection->update(['quota_synced_at' => now()]); // fresh, so a passive view alone would not refetch

        config(['services.company_storage.limit_mb' => 0]); // force everything to Drive
        $user = $this->makeStaffUser($this->companyA);
        $file = UploadedFile::fake()->create('to-drive.pdf', 10, 'application/pdf');

        $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(201);

        $this->assertNull($connection->fresh()->quota_synced_at, 'A Drive upload must invalidate the persisted quota sync.');
    }

    public function test_drive_quota_isolation_between_companies(): void
    {
        $connectionA = $this->makeConnectedDrive();
        $connectionA->update(['quota_total_bytes' => 111, 'quota_used_bytes' => 11, 'quota_synced_at' => now()]);

        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $bodyForB = $this->withHeader('X-Active-Company-Id', (string) $this->companyB)
            ->getJson('/api/admin/storage')->assertOk();

        $this->assertNull($bodyForB->json('data.drive_quota'));
    }

    // ── breakdown() — drive_bytes/total_bytes and the two added sources ─────

    public function test_breakdown_sums_drive_bytes_and_total_bytes_correctly(): void
    {
        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'a.pdf', 'file_name' => 'a.pdf', 'file_path' => 'x/a.pdf',
            'file_type' => 'application/pdf', 'file_size' => 1_000, 'storage_type' => 'internal',
        ]);
        ProjectAttachment::create([
            'company_id' => $this->companyA, 'project_id' => $this->projectA->id,
            'original_name' => 'b.pdf', 'file_name' => 'b.pdf', 'file_path' => null,
            'file_type' => 'application/pdf', 'file_size' => 2_000, 'storage_type' => 'google_drive',
            'google_drive_file_id' => 'drive-1', 'google_drive_folder_id' => 'folder-1',
        ]);

        $row = collect(CompanyStorageService::breakdown($this->companyA))->firstWhere('label', 'Project Attachments');

        $this->assertSame(1_000, $row['internal_bytes']);
        $this->assertSame(2_000, $row['drive_bytes']);
        $this->assertSame(3_000, $row['total_bytes']);
    }

    public function test_breakdown_includes_support_ticket_replies_and_the_current_delivery_pointer(): void
    {
        $labels = collect(CompanyStorageService::breakdown($this->companyA))->pluck('label');

        $this->assertTrue($labels->contains('Support Ticket Replies'));
        $this->assertTrue($labels->contains('Current Delivery File'));
    }
}
