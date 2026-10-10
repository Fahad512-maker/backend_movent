<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Company suspend/reactivate — replaces "Archive/Restore/Delete Company" with
 * a manual, per-Company suspension that:
 *   - blocks normal API access for staff AND the Company Admin, scoped to
 *     just the suspended company (Company B, under the same Admin, must stay
 *     untouched — see test_*_isolation below);
 *   - never deletes any record (see test_suspend_deletes_nothing);
 *   - stops NEW public-link payments but never a pending/webhook reconcile;
 *   - stays reachable from the company-management endpoints so it can always
 *     be reactivated;
 *   - is written to the audit trail;
 *   - is independent of Super Admin's is_active toggle and of the tenant's
 *     own subscription_status (test_reactivate_does_not_lift_other_restrictions).
 *
 * Permanent delete is Super-Admin-only and safeguarded (see
 * CompanySuspensionPermanentDeleteTest) — deliberately not exercised for real
 * here beyond confirming Admin/staff guards can never reach it.
 */
class CompanySuspensionTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $companyA;
    private int $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Suspension Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId, 'name' => 'Suspension Admin',
            'email' => 'suspadmin+' . uniqid() . '@example.test', 'password' => Hash::make('password'),
            'subscription_status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyA = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Company A', 'currency' => 'USD', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->companyB = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Company B', 'currency' => 'USD', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(array $companyIds): User
    {
        $user = User::create([
            'company_id' => $companyIds[0], 'name' => 'Staff ' . uniqid(),
            'email' => 'suspstaff+' . uniqid() . '@example.test', 'password' => 'password',
            'role_type' => 'hr', 'is_active' => true,
        ]);

        foreach ($companyIds as $companyId) {
            DB::table('company_user_assignments')->insert([
                'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function asStaff(User $user, int $companyId): self
    {
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Active-Company-Id', (string) $companyId);

        return $this;
    }

    private function asAdmin(int|string $companyId): self
    {
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) $companyId);

        return $this;
    }

    /** A Client portal login IS a User row with role_type 'client' — see Api\Client\AuthController::login(). */
    private function clientUser(int $companyId): User
    {
        $user = User::create([
            'company_id' => $companyId, 'name' => 'Client ' . uniqid(),
            'email' => 'suspclientuser+' . uniqid() . '@example.test', 'password' => 'password',
            'role_type' => 'client', 'is_active' => true,
        ]);

        \App\Models\Client::create([
            'company_id' => $companyId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'portal_access' => true,
        ]);

        return $user;
    }

    private function asClient(User $user): self
    {
        Sanctum::actingAs($user, ['role:client']);

        return $this;
    }

    private function makeInvoice(int $companyId, string $tag): Invoice
    {
        $clientId = DB::table('clients')->insertGetId([
            'company_id' => $companyId, 'name' => "Client {$tag}",
            'email' => 'suspclient' . uniqid() . '@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::create([
            'company_id' => $companyId, 'client_id' => $clientId,
            'invoice_number' => "INV-SUSP-{$tag}-" . uniqid(),
            'subtotal' => 500, 'total_amount' => 500, 'paid_amount' => 0,
            'currency' => 'USD', 'status' => 'sent',
            'due_date' => now()->addDays(10)->toDateString(), 'sent_at' => now(),
            'payment_token' => 'tok-' . uniqid(), 'token_expires_at' => now()->addDays(30),
        ]);
    }

    // ── Suspend/Reactivate endpoints ────────────────────────────────────────

    public function test_admin_can_suspend_and_reactivate_their_own_company(): void
    {
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Non-payment'])
            ->assertOk()
            ->assertJsonPath('data.suspension_reason', 'Non-payment');

        $company = Company::findOrFail($this->companyA);
        $this->assertNotNull($company->suspended_at);
        $this->assertTrue($company->is_active, 'is_active (Super Admin\'s own flag) must be untouched by a manual suspend.');

        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.fully_active', true);

        $this->assertNull($company->fresh()->suspended_at);
    }

    public function test_suspend_requires_a_reason(): void
    {
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", [])
            ->assertStatus(422);
    }

    public function test_suspend_writes_an_audit_log_entry(): void
    {
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Testing audit'])
            ->assertOk();

        $this->assertDatabaseHas('system_audit_logs', [
            'company_id' => $this->companyA, 'action' => 'company.suspended', 'entity_type' => Company::class,
        ]);

        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/reactivate")
            ->assertOk();

        $this->assertDatabaseHas('system_audit_logs', [
            'company_id' => $this->companyA, 'action' => 'company.reactivated', 'entity_type' => Company::class,
        ]);
    }

    public function test_manage_companies_reports_creator_and_created_at(): void
    {
        $created = $this->asAdmin($this->companyA)
            ->postJson('/api/admin/companies', ['name' => 'Created Via API ' . uniqid()])
            ->assertOk()
            ->json('data.id');

        $row = collect($this->asAdmin($this->companyA)->getJson('/api/admin/companies/manage')->json('data'))
            ->firstWhere('id', $created);

        $this->assertNotNull($row['created_at']);
        $this->assertSame($this->admin->name, $row['admin']['name']);
    }

    public function test_company_history_lists_created_and_status_changes(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'History test'])->assertOk();
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/reactivate")->assertOk();

        $actions = collect(
            $this->asAdmin($this->companyA)->getJson("/api/admin/companies/{$this->companyA}/history")->json('data')
        )->pluck('action');

        $this->assertContains('company.suspended', $actions);
        $this->assertContains('company.reactivated', $actions);
    }

    public function test_company_history_is_scoped_to_its_own_company(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'x'])->assertOk();

        $historyB = $this->asAdmin($this->companyB)->getJson("/api/admin/companies/{$this->companyB}/history")->json('data');
        $this->assertEmpty($historyB);
    }

    public function test_admin_cannot_suspend_a_company_they_do_not_own(): void
    {
        $otherAdminId = DB::table('company_admins')->insertGetId([
            'package_id' => DB::table('packages')->insertGetId([
                'name' => 'Other Package', 'tier' => 'basic', 'price' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'name' => 'Other Admin', 'email' => 'other+' . uniqid() . '@example.test', 'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCompany = DB::table('companies')->insertGetId([
            'admin_id' => $otherAdminId, 'name' => 'Not Yours', 'currency' => 'USD', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$otherCompany}/suspend", ['reason' => 'nope'])
            ->assertNotFound();
    }

    /**
     * Regression test for a real bug: /admin/users eager-loads
     * companyAssignments.company with a restricted column list. If that list
     * ever omits is_active/suspended_at, Company::isOperational() reads them
     * as null and reports EVERY company as suspended — showing every staff
     * member's assignment as "Suspended" on the Users page even though
     * nothing was ever suspended. See Api\Admin\UserController::index()'s
     * 'companyAssignments' eager load and UserResource's company_assignments
     * status override.
     */
    public function test_admin_users_list_does_not_show_an_active_companys_staff_as_suspended(): void
    {
        $user = $this->staff([$this->companyA]);

        $row = collect($this->asAdmin($this->companyA)->getJson('/api/admin/users')->assertOk()->json('data.users'))
            ->firstWhere('id', $user->id);

        $assignment = collect($row['company_assignments'])->firstWhere('company_id', $this->companyA);
        $this->assertSame('active', $assignment['status'], 'An unsuspended company must not make its staff read as Suspended.');
    }

    public function test_suspend_deletes_nothing(): void
    {
        $invoice = $this->makeInvoice($this->companyA, 'KEEP');

        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Keep data test'])
            ->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $this->companyA]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'company_id' => $this->companyA]);
    }

    // ── Middleware enforcement ──────────────────────────────────────────────

    public function test_staff_request_is_blocked_once_their_company_is_suspended(): void
    {
        $user = $this->staff([$this->companyA]);

        $this->asStaff($user, $this->companyA)->getJson('/api/user/me')->assertOk();

        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Blocking staff'])
            ->assertOk();

        $response = $this->asStaff($user, $this->companyA)->getJson('/api/user/profile');
        $response->assertStatus(403);
        $this->assertSame('company_suspended', $response->json('errors.error_code'));
    }

    public function test_staff_logout_and_me_stay_reachable_while_suspended(): void
    {
        $user = $this->staff([$this->companyA]);
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'x'])->assertOk();

        $this->asStaff($user, $this->companyA)->getJson('/api/user/me')->assertOk();
    }

    public function test_client_portal_is_blocked_once_its_company_is_suspended(): void
    {
        $clientUser = $this->clientUser($this->companyA);

        $this->asClient($clientUser)->getJson('/api/client/profile')->assertOk();

        // Suspended directly, not through asAdmin()->patchJson(...) — see the
        // comment in test_client_portal_in_an_unaffected_company_is_untouched
        // for why chaining a real 'admin'-guard actingAs() before a client
        // request in the same test would make this pass for the wrong reason.
        Company::whereKey($this->companyA)->update(['suspended_at' => now(), 'suspension_reason' => 'Blocking client portal']);

        $blocked = $this->asClient($clientUser)->getJson('/api/client/profile');
        $blocked->assertStatus(403);
        $this->assertSame('company_suspended', $blocked->json('errors.error_code'));

        // logout/me stay reachable, same as the staff/admin guards.
        $this->asClient($clientUser)->getJson('/api/client/me')->assertOk();
    }

    public function test_client_portal_in_an_unaffected_company_is_untouched(): void
    {
        $clientUserA = $this->clientUser($this->companyA);
        $clientUserB = $this->clientUser($this->companyB);

        // Suspended directly (not via the admin HTTP endpoint): chaining
        // Sanctum::actingAs() across the 'admin' and default guards in one
        // test leaves the 'admin' guard's resolved user set for the rest of
        // the test (a Sanctum testing quirk, not a real request — a live
        // client request's Bearer token never resolves against the 'admin'
        // guard at all, since it belongs to a different tokenable model).
        // That stale binding would make EnsureCompanyNotSuspended's admin
        // branch fire here too, against whichever company its no-header
        // fallback happens to land on — irrelevant to what this test is
        // actually checking (client-side isolation).
        Company::whereKey($this->companyA)->update(['suspended_at' => now(), 'suspension_reason' => 'Isolation client']);

        $this->asClient($clientUserA)->getJson('/api/client/profile')->assertStatus(403);
        $this->asClient($clientUserB)->getJson('/api/client/profile')->assertOk();
    }

    public function test_admin_request_is_blocked_for_the_suspended_company_only(): void
    {
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Admin block test'])
            ->assertOk();

        $blocked = $this->asAdmin($this->companyA)->getJson('/api/admin/dashboard');
        $blocked->assertStatus(403);
        $this->assertSame('company_suspended', $blocked->json('errors.error_code'));
    }

    public function test_admin_can_still_reach_company_management_endpoints_while_suspended(): void
    {
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Reachability test'])
            ->assertOk();

        $this->asAdmin($this->companyA)->getJson('/api/admin/companies')->assertOk();
        $this->asAdmin($this->companyA)->getJson('/api/admin/companies/manage')->assertOk();
        $this->asAdmin($this->companyA)->getJson("/api/admin/companies/{$this->companyA}")->assertOk();
        $this->asAdmin($this->companyA)->getJson('/api/admin/me')->assertOk();

        // And Reactivate itself, from inside the very state it's meant to fix.
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/reactivate")
            ->assertOk();
        $this->asAdmin($this->companyA)->getJson('/api/admin/dashboard')->assertOk();
    }

    // ── Multi-company isolation ──────────────────────────────────────────────

    public function test_suspending_company_a_does_not_affect_company_b_for_staff(): void
    {
        $user = $this->staff([$this->companyA, $this->companyB]);

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Isolation'])->assertOk();

        $this->asStaff($user, $this->companyA)->getJson('/api/user/profile')->assertStatus(403);
        $this->asStaff($user, $this->companyB)->getJson('/api/user/profile')->assertOk();
    }

    public function test_suspending_company_a_does_not_affect_company_b_for_admin(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Isolation admin'])->assertOk();

        $this->asAdmin($this->companyA)->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->asAdmin($this->companyB)->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_admin_all_companies_mode_is_not_blocked_by_one_suspended_company(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'All-mode'])->assertOk();

        // 'all' resolves to BOTH companies (see ActiveCompany::adminCompanyIds) —
        // EnsureCompanyNotSuspended deliberately only blocks a single-company
        // selection, so the aggregate view stays usable.
        $this->asAdmin('all')->getJson('/api/admin/dashboard')->assertOk();
    }

    /**
     * The concrete report this fixes: /admin/employees in "All Companies"
     * mode kept listing a suspended company's employees mixed in with the
     * rest. ScopesToActiveCompany::activeCompanyIds() — the shared resolver
     * ~30 Admin controllers build their "all" aggregate on — now filters
     * through Company::operational(), so this fix applies to every one of
     * them, not just Employees.
     */
    public function test_all_companies_mode_excludes_a_suspended_companys_employees(): void
    {
        foreach ([$this->companyA, $this->companyB] as $companyId) {
            DB::table('company_modules')->insert(['company_id' => $companyId, 'module_key' => 'employees', 'is_enabled' => true]);
        }

        $empA = \App\Models\Employee::create(['company_id' => $this->companyA, 'name' => 'Employee A', 'status' => 'active']);
        $empB = \App\Models\Employee::create(['company_id' => $this->companyB, 'name' => 'Employee B', 'status' => 'active']);

        $before = $this->asAdmin('all')->getJson('/api/admin/employees')->assertOk()->json('data');
        $this->assertContains($empA->id, collect($before)->pluck('id')->all());

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Employees list test'])->assertOk();

        $after = collect($this->asAdmin('all')->getJson('/api/admin/employees')->assertOk()->json('data'));
        $this->assertNotContains($empA->id, $after->pluck('id')->all(), 'Suspended company\'s employee must drop out of the aggregate list.');
        $this->assertContains($empB->id, $after->pluck('id')->all(), 'The unaffected company\'s employee must remain.');
    }

    /**
     * The other half of the fix: not just hiding a suspended company from
     * lists, but refusing to let it be explicitly targeted as the company
     * for a brand-new record — even while in "All Companies" mode, where
     * EnsureCompanyNotSuspended's single-company block doesn't apply.
     * ScopesToActiveCompany::operationalCompanyIds() is what every Admin
     * controller's create/store validation now builds its company_id
     * whitelist from instead of the raw (unfiltered) companyIds().
     */
    public function test_cannot_create_a_new_employee_under_a_suspended_company_even_in_all_mode(): void
    {
        DB::table('company_modules')->insert(['company_id' => $this->companyA, 'module_key' => 'employees', 'is_enabled' => true]);
        DB::table('company_modules')->insert(['company_id' => $this->companyB, 'module_key' => 'employees', 'is_enabled' => true]);

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Create-block test'])->assertOk();

        $this->asAdmin('all')
            ->postJson('/api/admin/employees', ['company_id' => $this->companyA, 'name' => 'Should Not Save'])
            ->assertStatus(422);

        $this->asAdmin('all')
            ->postJson('/api/admin/employees', ['company_id' => $this->companyB, 'name' => 'Should Save'])
            ->assertSuccessful();

        $this->assertDatabaseMissing('employees', ['company_id' => $this->companyA, 'name' => 'Should Not Save']);
        $this->assertDatabaseHas('employees', ['company_id' => $this->companyB, 'name' => 'Should Save']);
    }

    public function test_cannot_create_a_new_lead_under_a_suspended_company_even_in_all_mode(): void
    {
        DB::table('company_modules')->insert(['company_id' => $this->companyA, 'module_key' => 'leads', 'is_enabled' => true]);

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Lead create-block test'])->assertOk();

        $this->asAdmin('all')
            ->postJson('/api/admin/leads', [
                'company_id' => $this->companyA, 'name' => 'Lead X', 'email' => 'leadx+' . uniqid() . '@example.test',
            ])
            ->assertStatus(422);
    }

    // ── Public payment links ────────────────────────────────────────────────

    public function test_public_invoice_show_hides_gateways_when_company_suspended(): void
    {
        $invoiceA = $this->makeInvoice($this->companyA, 'PUB');

        $before = $this->getJson("/api/public/invoices/{$invoiceA->payment_token}")->assertOk();
        $this->assertSame($invoiceA->invoice_number, $before->json('data.invoice_number'));

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Public link test'])->assertOk();

        $after = $this->getJson("/api/public/invoices/{$invoiceA->payment_token}")->assertOk();
        $this->assertSame([], $after->json('data.available_gateways'));
        $this->assertNotNull($after->json('data.gateway_unavailable_message'));
        // The invoice itself is still visible — suspension hides payment, not data.
        $this->assertSame($invoiceA->invoice_number, $after->json('data.invoice_number'));
    }

    public function test_public_invoice_pay_is_refused_when_company_suspended(): void
    {
        $invoiceA = $this->makeInvoice($this->companyA, 'PAY');
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Pay refusal test'])->assertOk();

        $this->postJson("/api/public/invoices/{$invoiceA->payment_token}/pay", ['gateway' => 'bank_transfer'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payments for this account are temporarily unavailable. Please contact the business directly.');

        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoiceA->id]);
    }

    public function test_public_invoice_pay_still_works_for_an_unaffected_company(): void
    {
        $invoiceB = $this->makeInvoice($this->companyB, 'OK');
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Unrelated'])->assertOk();

        $this->postJson("/api/public/invoices/{$invoiceB->payment_token}/pay", ['gateway' => 'bank_transfer'])
            ->assertOk();

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoiceB->id]);
    }

    // ── Scheduled jobs skip a suspended company ─────────────────────────────

    public function test_invoice_reminder_due_scope_skips_a_suspended_companys_reminder(): void
    {
        $invoiceA = $this->makeInvoice($this->companyA, 'REM');
        $reminder = InvoiceReminder::create([
            'invoice_id' => $invoiceA->id, 'company_id' => $this->companyA, 'type' => 'email',
            'status' => 'scheduled', 'reminder_date' => now()->toDateString(),
        ]);

        $this->assertTrue(InvoiceReminder::due()->whereKey($reminder->id)->exists());

        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'Reminder skip'])->assertOk();

        $this->assertFalse(InvoiceReminder::due()->whereKey($reminder->id)->exists());
    }

    // ── Reactivate does not bypass other restrictions ───────────────────────

    public function test_reactivate_reports_remaining_super_admin_deactivation(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'x'])->assertOk();
        Company::whereKey($this->companyA)->update(['is_active' => false]); // Super Admin's own toggle

        $result = $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/reactivate")->assertOk();

        $this->assertSame('deactivated_by_super_admin', $result->json('data.remaining_restriction'));
        $this->assertFalse($result->json('data.fully_active'));
        // Still blocked overall — reactivate lifted ONLY the manual suspension.
        $this->asAdmin($this->companyA)->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    public function test_reactivate_reports_remaining_subscription_restriction(): void
    {
        $this->asAdmin($this->companyA)->patchJson("/api/admin/companies/{$this->companyA}/suspend", ['reason' => 'x'])->assertOk();
        $this->admin->update(['subscription_status' => 'suspended']);

        // A blocked tenant 402s before reaching the controller at all — the
        // manual suspension can never even be tested for reactivation while
        // the tenant itself is blocked; this documents that ordering.
        $this->asAdmin($this->companyA)
            ->patchJson("/api/admin/companies/{$this->companyA}/reactivate")
            ->assertStatus(402);
    }

    // ── Permanent delete is unreachable outside Super Admin ─────────────────

    public function test_no_admin_or_staff_route_can_permanently_delete_a_company(): void
    {
        $this->asAdmin($this->companyA)->deleteJson("/api/admin/companies/{$this->companyA}")->assertStatus(405);

        $user = $this->staff([$this->companyA]);
        $this->asStaff($user, $this->companyA)->deleteJson("/api/user/companies/{$this->companyA}")->assertStatus(404);
    }
}
