<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\UserCompanyPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finance module entitlement is the parent gate; permission comes second.
 *
 *   CAN_ACCESS_FINANCE = company holds the Finance module (and the Super
 *                        Admin has not deactivated it) AND the user holds
 *                        the Finance permission.
 *
 * Company A holds Finance + Invoice. Company B holds Invoice only — the
 * "never bought Finance" case — and both belong to one Company Admin, which
 * is what makes the multi-company leak testable.
 */
class FinanceModuleEntitlementTest extends TestCase
{
    use DatabaseTransactions;

    private const FINANCE_KEYS = ['finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report'];
    private const INVOICE_KEYS = ['invoices', 'payments', 'payment_details', 'invoice_reminders'];

    /** Every finance-bucket key a Finance Manager would hold. */
    private const ALL_FINANCE_PERMS = [
        'canViewFinanceDashboard', 'canViewFinanceInvoices', 'canViewPayments', 'canReconcilePayments',
        'canRecordPayments', 'canViewPaymentDetails', 'canCreateInvoiceReminders', 'canTrackInvoiceReminders',
        'canViewFinanceReports', 'canViewRevenueReports', 'canViewPaymentReports', 'canViewAllCompanyFinance',
    ];

    private CompanyAdmin $admin;
    private int $companyA;
    private int $companyB;
    private Invoice $invoiceA;
    private Invoice $invoiceB;
    private Payment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog row CheckCompanyModule's "disabled by the Super Admin"
        // check reads — seeded state isn't assumed in the test database.
        Module::updateOrCreate(['key' => 'finance'], [
            'label' => 'Finance', 'sub_modules' => self::FINANCE_KEYS,
            'price_usd' => 5, 'is_active' => true, 'is_system' => true,
        ]);

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Entitlement Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId, 'name' => 'Entitlement Admin',
            'email' => 'entadmin+' . uniqid() . '@example.test', 'password' => Hash::make('password'),
            'subscription_status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyA = $this->makeCompany($adminId, 'Has Finance', [...self::FINANCE_KEYS, ...self::INVOICE_KEYS]);
        $this->companyB = $this->makeCompany($adminId, 'No Finance', self::INVOICE_KEYS);

        $this->invoiceA = $this->makeInvoice($this->companyA, 'A');
        $this->invoiceB = $this->makeInvoice($this->companyB, 'B');

        $this->paymentB = Payment::create([
            'invoice_id' => $this->invoiceB->id, 'amount' => 100, 'currency' => 'USD',
            'method' => 'cash', 'status' => 'confirmed',
            'receipt_number' => 'RCP-ENT-B-' . uniqid(), 'payment_date' => now()->toDateString(),
        ]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(int $adminId, string $name, array $moduleKeys): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => $name, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($moduleKeys as $key) {
            DB::table('company_modules')->insert(['company_id' => $id, 'module_key' => $key, 'is_enabled' => true]);
        }

        return $id;
    }

    private function makeInvoice(int $companyId, string $tag): Invoice
    {
        $clientId = DB::table('clients')->insertGetId([
            'company_id' => $companyId, 'name' => "Client {$tag}",
            'email' => 'entclient' . uniqid() . '@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::create([
            'company_id' => $companyId, 'client_id' => $clientId,
            'invoice_number' => "INV-ENT-{$tag}-" . uniqid(),
            'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
            'currency' => 'USD', 'status' => 'sent',
            'due_date' => now()->addDays(10)->toDateString(), 'sent_at' => now(),
        ]);
    }

    /** A staff user holding $financePerms in EACH of $companyIds. */
    private function staff(array $companyIds, array $financePerms = self::ALL_FINANCE_PERMS): User
    {
        $user = User::create([
            'company_id' => $companyIds[0], 'name' => 'Entitlement Staff',
            'email' => 'entstaff+' . uniqid() . '@example.test', 'password' => 'password',
            'role_type' => 'finance', 'is_active' => true,
        ]);

        foreach ($companyIds as $companyId) {
            $assignmentId = DB::table('company_user_assignments')->insertGetId([
                'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($financePerms as $key) {
                UserCompanyPermission::create([
                    'company_user_id' => $assignmentId, 'user_id' => $user->id, 'company_id' => $companyId,
                    'module_key' => 'finance', 'permission_key' => $key,
                ]);
            }
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

    /**
     * Every Finance endpoint, one per verb — GET/POST/PATCH/DELETE.
     *
     * @return array<int, array{0: string, 1: string, 2?: array}>
     */
    private function financeCalls(string $prefix, int $invoiceId, int $paymentId): array
    {
        return [
            ['GET',    "{$prefix}/finance/capabilities"],
            ['GET',    "{$prefix}/finance/dashboard"],
            ['GET',    "{$prefix}/finance/invoices"],
            ['GET',    "{$prefix}/finance/invoices/{$invoiceId}"],
            ['GET',    "{$prefix}/finance/invoices/export"],
            ['GET',    "{$prefix}/finance/payments"],
            ['PATCH',  "{$prefix}/finance/payments/{$paymentId}/reconcile"],
            ['DELETE', "{$prefix}/finance/payments/{$paymentId}/reconcile"],
            ['POST',   "{$prefix}/finance/invoices/{$invoiceId}/payments", ['amount' => 10, 'method' => 'cash']],
            ['GET',    "{$prefix}/finance/payment-details"],
            ['GET',    "{$prefix}/finance/invoice-reminders"],
            ['POST',   "{$prefix}/finance/invoice-reminders", ['invoice_id' => $invoiceId]],
            ['GET',    "{$prefix}/finance/reports/finance"],
            ['GET',    "{$prefix}/finance/reports/revenue"],
            ['GET',    "{$prefix}/finance/reports/payments"],
        ];
    }

    private function assertAllRefused(string $prefix, int $invoiceId, int $paymentId): void
    {
        foreach ($this->financeCalls($prefix, $invoiceId, $paymentId) as $call) {
            [$method, $uri] = $call;
            $this->json($method, $uri, $call[2] ?? [])
                ->assertStatus(403, "{$method} {$uri} must be refused when Finance is unavailable");
        }
    }

    // ── A / B: module present ───────────────────────────────────────────────

    public function test_a_module_and_permission_grants_access(): void
    {
        $user = $this->staff([$this->companyA]);

        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/dashboard')->assertOk();
        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/invoices')->assertOk();
        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/reports/finance')->assertOk();
    }

    public function test_b_module_without_permission_is_refused(): void
    {
        $user = $this->staff([$this->companyA], []);

        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/dashboard')->assertStatus(403);
        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/invoices')->assertStatus(403);
    }

    // ── C: Super Admin deactivated Finance ──────────────────────────────────

    public function test_c_super_admin_deactivation_blocks_every_finance_call_despite_permissions(): void
    {
        $user = $this->staff([$this->companyA]);
        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/dashboard')->assertOk();

        // Through the real Super Admin endpoint, not a direct DB write.
        $superAdmin = SuperAdmin::create([
            'name' => 'SA', 'email' => 'sa+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $module = Module::where('key', 'finance')->firstOrFail();
        Sanctum::actingAs($superAdmin, ['*'], 'super_admin');
        $this->patchJson("/api/super-admin/modules/{$module->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->asStaff($user, $this->companyA);
        $this->assertAllRefused('/api/user', $this->invoiceA->id, $this->paymentB->id);

        $this->asAdmin($this->companyA);
        $this->assertAllRefused('/api/admin', $this->invoiceA->id, $this->paymentB->id);

        // And the module lists the frontend renders from no longer carry it.
        $staffModules = $this->asStaff($user, $this->companyA)->getJson('/api/user/me')->json('data.company.modules');
        $this->assertEmpty(array_intersect(self::FINANCE_KEYS, $staffModules));
        $this->assertContains('invoices', $staffModules, 'Invoice module must be unaffected');

        $adminModules = $this->asAdmin($this->companyA)->getJson('/api/admin/me')->json('data.modules');
        $this->assertEmpty(array_intersect(self::FINANCE_KEYS, $adminModules));
        $this->assertContains('invoices', $adminModules, 'Invoice module must be unaffected');

        // Reactivating restores access under the user's existing permissions.
        Sanctum::actingAs($superAdmin, ['*'], 'super_admin');
        $this->patchJson("/api/super-admin/modules/{$module->id}/toggle")->assertJsonPath('data.is_active', true);
        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/dashboard')->assertOk();
    }

    public function test_c_per_company_deactivation_by_super_admin_blocks_finance(): void
    {
        $user = $this->staff([$this->companyA]);

        $superAdmin = SuperAdmin::create([
            'name' => 'SA', 'email' => 'sa+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        Sanctum::actingAs($superAdmin, ['*'], 'super_admin');
        $this->putJson("/api/super-admin/companies/{$this->companyA}/modules", ['modules' => self::INVOICE_KEYS])
            ->assertOk();

        $this->asStaff($user, $this->companyA);
        $this->assertAllRefused('/api/user', $this->invoiceA->id, $this->paymentB->id);
    }

    // ── D: never purchased ──────────────────────────────────────────────────

    public function test_d_company_without_finance_is_refused_even_with_every_finance_permission(): void
    {
        $user = $this->staff([$this->companyB]);

        $this->asStaff($user, $this->companyB);
        $this->assertAllRefused('/api/user', $this->invoiceB->id, $this->paymentB->id);

        $this->assertSame('unreconciled', $this->paymentB->fresh()->reconciliation_status);
        $this->assertDatabaseMissing('invoice_reminders', ['invoice_id' => $this->invoiceB->id]);

        $modules = $this->asStaff($user, $this->companyB)->getJson('/api/user/me')->json('data.company.modules');
        $this->assertNotContains('finance_dashboard', $modules);
    }

    // ── G: multi-company isolation ──────────────────────────────────────────

    public function test_g_staff_in_two_companies_sees_finance_only_in_the_one_that_has_it(): void
    {
        $user = $this->staff([$this->companyA, $this->companyB]);

        $this->asStaff($user, $this->companyA)->getJson('/api/user/finance/dashboard')->assertOk();
        $this->assertContains('finance_dashboard',
            $this->asStaff($user, $this->companyA)->getJson('/api/user/me')->json('data.company.modules'));

        $this->asStaff($user, $this->companyB)->getJson('/api/user/finance/dashboard')->assertStatus(403);
        $this->assertNotContains('finance_dashboard',
            $this->asStaff($user, $this->companyB)->getJson('/api/user/me')->json('data.company.modules'));
    }

    public function test_g_admin_cannot_read_finance_for_their_company_that_never_bought_it(): void
    {
        // The leak this fix closes: Company A's Finance used to open the
        // gate for Company B, and FinanceContext then returned B's data.
        $this->asAdmin($this->companyA)->getJson('/api/admin/finance/dashboard')->assertOk();

        $this->asAdmin($this->companyB);
        $this->assertAllRefused('/api/admin', $this->invoiceB->id, $this->paymentB->id);
        $this->assertSame('unreconciled', $this->paymentB->fresh()->reconciliation_status);
    }

    public function test_g_admin_all_companies_mode_only_includes_companies_with_finance(): void
    {
        $numbers = $this->asAdmin('all')->getJson('/api/admin/finance/invoices')
            ->assertOk()->json('data.invoices.*.invoice_number');

        $this->assertContains($this->invoiceA->invoice_number, $numbers);
        $this->assertNotContains($this->invoiceB->invoice_number, $numbers);

        $this->asAdmin('all')->getJson("/api/admin/finance/invoices/{$this->invoiceB->id}")->assertNotFound();
    }

    public function test_g_admin_me_reports_the_active_companys_modules(): void
    {
        $this->assertContains('finance_dashboard',
            $this->asAdmin($this->companyA)->getJson('/api/admin/me')->json('data.modules'));
        $this->assertNotContains('finance_dashboard',
            $this->asAdmin($this->companyB)->getJson('/api/admin/me')->json('data.modules'));
    }

    // ── Other modules unaffected ────────────────────────────────────────────

    public function test_invoice_module_still_works_in_a_company_without_finance(): void
    {
        $this->asAdmin($this->companyB)->getJson('/api/admin/invoices')->assertOk();
    }

    // ── Backfill command ────────────────────────────────────────────────────

    public function test_backfill_is_a_dry_run_by_default_and_never_overrides_an_explicit_row(): void
    {
        // A per-company choice made on purpose: B explicitly has Finance
        // Reports switched off. The backfill must leave that row alone.
        DB::table('company_modules')->insert([
            'company_id' => $this->companyB, 'module_key' => 'finance_reports', 'is_enabled' => false,
        ]);

        $this->artisan('company:sync-admin-modules', ['--admin' => $this->admin->email])->assertSuccessful();
        $this->assertDatabaseMissing('company_modules', ['company_id' => $this->companyB, 'module_key' => 'finance_dashboard']);

        $this->artisan('company:sync-admin-modules', ['--admin' => $this->admin->email, '--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('company_modules', ['company_id' => $this->companyB, 'module_key' => 'finance_dashboard', 'is_enabled' => true]);
        $this->assertDatabaseHas('company_modules', ['company_id' => $this->companyB, 'module_key' => 'finance_reports', 'is_enabled' => false]);
        $this->assertSame(1, DB::table('company_modules')->where('company_id', $this->companyB)->where('module_key', 'invoices')->count(),
            'Keys B already has must not be duplicated');
    }
}
