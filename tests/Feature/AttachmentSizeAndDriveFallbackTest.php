<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\GoogleDriveConnection;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The exact reported bug: uploading a file over the company's 10MB storage
 * limit showed "exceeds 10MB limit" even with Google Drive connected,
 * because every upload endpoint's own Laravel `max:` validation rule
 * rejected the request before AttachmentStorageService ever got a chance to
 * route it to Drive (see AttachmentStorageService::maxUploadKb()).
 *
 * Also covers the other half of this fix: Chat and Support ticket
 * attachments, previously "documented but not wired" to
 * AttachmentStorageService at all (raw `$file->store()`, no Drive fallback,
 * no quota accounting), now go through the same decision as every other
 * attachment location.
 */
class AttachmentSizeAndDriveFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyA;
    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Storage::fake('public'); // support-ticket attachments store on this disk specifically
        config(['services.company_storage.limit_mb' => 10]); // the documented default

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Attachment Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId, 'name' => 'Attachment Admin',
            'email' => 'attadmin+' . uniqid() . '@example.test', 'password' => Hash::make('password'),
            'subscription_status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyA = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Attachment Co', 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['projects', 'client', 'client_portal'] as $key) {
            DB::table('company_modules')->insert(['company_id' => $this->companyA, 'module_key' => $key, 'is_enabled' => true]);
        }

        $this->projectA = Project::create(['company_id' => $this->companyA, 'name' => 'Project A', 'status' => 'active']);
    }

    private function makeStaffUser(): User
    {
        $user = User::create([
            'company_id' => $this->companyA, 'name' => 'Staff ' . uniqid(),
            'email' => 'attstaff+' . uniqid() . '@example.test', 'password' => 'password',
            'role_type' => 'project_manager', 'is_active' => true,
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyA, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['canViewAllCompanyProjects', 'canUploadProjectAttachments'] as $key) {
            DB::table('user_company_permissions')->insert([
                'user_id' => $user->id, 'company_id' => $this->companyA,
                'module_key' => 'project_management', 'permission_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function asStaff(User $user): self
    {
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Active-Company-Id', (string) $user->company_id);

        return $this;
    }

    private function connectDrive(): void
    {
        Http::fake([
            // A resumable session (any file at/above GoogleDriveService's 5MB
            // threshold — every "large file" in this test) needs a Location
            // header on the init response; the follow-up PUT of the actual
            // bytes goes back to that same URL, which still matches this glob.
            '*googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'fake-drive-file-id'], 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=fake-session',
            ]),
            '*googleapis.com/drive/v3/files?*' => Http::response(['files' => []], 200),
            // A download's GET .../files/{fileId}?alt=media — matched before
            // the bare 'files' folder-create pattern below since it has a
            // path segment the other pattern's exact match would not accept.
            '*googleapis.com/drive/v3/files/*' => Http::response('fake file bytes', 200),
            '*googleapis.com/drive/v3/files'   => Http::response(['id' => 'fake-folder-id'], 200),
        ]);

        GoogleDriveConnection::create([
            'company_id' => $this->companyA, 'status' => 'connected',
            'google_account_email' => 'drive@example.test', 'google_account_id' => 'gid-1',
            'credentials' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->timestamp],
            'connected_at' => now(),
        ]);
    }

    // ── The reported bug, reproduced and fixed ──────────────────────────────

    public function test_a_file_over_10mb_is_rejected_with_no_drive_connected(): void
    {
        $user = $this->makeStaffUser();
        // Larger than the company's whole 10MB quota — well past what a
        // single fresh company could ever fit, with or without a per-file cap.
        $file = UploadedFile::fake()->create('big.zip', 25 * 1024, 'application/zip');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(422);

        // The real "connect Google Drive" response, not a generic Laravel
        // validation message — this is what test case 5 (no Drive, big file)
        // in the bug report asks for: a real Drive-connect prompt, not a
        // dead-end "exceeds 10MB" error.
        $this->assertSame('storage_limit_reached', $response->json('errors.error_code'));
        $this->assertStringContainsString('Connect Google Drive', $response->json('message'));
    }

    public function test_the_exact_reported_bug_a_25mb_zip_uploads_through_drive_when_connected(): void
    {
        $this->connectDrive();
        $user = $this->makeStaffUser();

        // moday_changes_1.zip, well over 10MB — the exact repro from the bug report.
        $file = UploadedFile::fake()->create('moday_changes_1.zip', 25 * 1024, 'application/zip');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(201);

        $this->assertSame('google_drive', $response->json('data.storage_type'));
        $this->assertSame('fake-drive-file-id', $response->json('data.google_drive_file_id'));
        $this->assertNull($response->json('data.file_path'));
    }

    public function test_a_5mb_file_still_uploads_internally_exactly_as_before_drive_connected_or_not(): void
    {
        $user = $this->makeStaffUser();
        $file = UploadedFile::fake()->create('small.pdf', 5 * 1024, 'application/pdf');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file])
            ->assertStatus(201);

        $this->assertSame('internal', $response->json('data.storage_type'));

        $this->connectDrive();
        $file2 = UploadedFile::fake()->create('small2.pdf', 4 * 1024, 'application/pdf');
        $response2 = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/attachments", ['file' => $file2])
            ->assertStatus(201);

        // 5MB + 4MB = 9MB, still under the 10MB quota — no need to touch Drive.
        $this->assertSame('internal', $response2->json('data.storage_type'));
    }

    // ── Chat attachments — previously not wired to Drive at all ─────────────

    public function test_a_large_chat_attachment_falls_back_to_drive_when_connected(): void
    {
        $this->connectDrive();
        $user = $this->makeStaffUser();

        $thread = ChatThread::create([
            'company_id' => $this->companyA, 'thread_type' => 'project_group',
            'linked_to_type' => 'Project', 'linked_to_id' => $this->projectA->id, 'title' => 'Project A',
        ]);
        \App\Models\ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id, 'role' => 'admin', 'joined_at' => now()]);
        foreach (['canUseProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment'] as $key) {
            DB::table('user_company_permissions')->insert([
                'user_id' => $user->id, 'company_id' => $this->companyA,
                'module_key' => 'project_management', 'permission_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $file = UploadedFile::fake()->create('chat-file.zip', 15 * 1024, 'application/zip');

        $response = $this->asStaff($user)
            ->postJson("/api/user/projects/{$this->projectA->id}/messenger/messages", ['file' => $file])
            ->assertStatus(201);

        $message = ChatMessage::findOrFail($response->json('data.id'));
        $this->assertSame('google_drive', $message->storage_type);
        $this->assertSame('fake-drive-file-id', $message->google_drive_file_id);
        $this->assertNull($message->attachment_path);
        $this->assertEquals(15 * 1024 * 1024, $message->attachment_size);
    }

    // ── Support ticket attachments — previously not wired to Drive at all ───

    public function test_a_large_support_ticket_attachment_falls_back_to_drive_when_connected(): void
    {
        $this->connectDrive();

        $adminId = DB::table('companies')->where('id', $this->companyA)->value('admin_id');
        $admin = \App\Models\CompanyAdmin::findOrFail($adminId);
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA);

        $ticket = SupportTicket::create([
            'company_id' => $this->companyA, 'raised_by' => null, 'subject' => 'Big file',
            'category' => 'technical_issue', 'priority' => 'low', 'status' => 'open',
        ]);

        $file = UploadedFile::fake()->create('support-file.zip', 15 * 1024, 'application/zip');

        $response = $this->postJson("/api/admin/support/{$ticket->id}/reply", [
            'message' => 'Here is the file', 'attachment' => $file,
        ])->assertOk();

        $reply = SupportTicketReply::findOrFail($response->json('data.id'));
        $this->assertSame('google_drive', $reply->storage_type);
        $this->assertSame('fake-drive-file-id', $reply->google_drive_file_id);
        $this->assertNull($reply->attachment_path);
        $this->assertEquals(15 * 1024 * 1024, $reply->attachment_size);

        // And it's downloadable through the new authenticated route.
        $this->get("/api/admin/support/{$ticket->id}/replies/{$reply->id}/attachment")->assertOk();
    }

    public function test_a_small_support_ticket_attachment_still_stores_internally_on_the_public_disk(): void
    {
        $adminId = DB::table('companies')->where('id', $this->companyA)->value('admin_id');
        $admin = \App\Models\CompanyAdmin::findOrFail($adminId);
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $this->companyA);

        $ticket = SupportTicket::create([
            'company_id' => $this->companyA, 'raised_by' => null, 'subject' => 'Small file',
            'category' => 'technical_issue', 'priority' => 'low', 'status' => 'open',
        ]);

        $file = UploadedFile::fake()->create('small.pdf', 500, 'application/pdf');

        $response = $this->postJson("/api/admin/support/{$ticket->id}/reply", [
            'message' => 'Here is the file', 'attachment' => $file,
        ])->assertOk();

        $reply = SupportTicketReply::findOrFail($response->json('data.id'));
        $this->assertSame('internal', $reply->storage_type);
        $this->assertNotNull($reply->attachment_path);
        Storage::disk('public')->assertExists($reply->attachment_path);
    }
}
