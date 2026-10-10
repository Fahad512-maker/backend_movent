<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserCompanyPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Finance Area's two load-bearing properties.
 *
 *  1. COMPANY ISOLATION. Every Finance read and write goes through
 *     App\Services\Finance\FinanceScope, which filters by the company ids
 *     FinanceContext resolved. A hand-edited id in the URL must therefore
 *     produce a 404, never another company's row — and an export, a report
 *     and a list must all agree about that, since they share the same scope.
 *
 *  2. PERMISSIONS ARE ENFORCED ON THE SERVER. Hiding a button is a courtesy;
 *     what actually holds is the per-endpoint check. Each granular key is
 *     tested by giving a user ONLY that key and asserting the one endpoint it
 *     unlocks works and its neighbours 403.
 *
 * Two companies under one Company Admin, so cross-company assertions are
 * possible without inventing a second tenant. DatabaseTransactions, not
 * RefreshDatabase — see the note in MultiRolePerCompanyTest.
 */
class FinanceAreaTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $companyA;
    private int $companyB;
    private Invoice $invoiceA;
    private Invoice $invoiceB;
    private Payment $paymentA;
    private Payment $paymentB;

    /** Every DB module key the Finance route groups gate on. */
    private const MODULES = [
        'finance_dashboard', 'finance_reports', 'revenue_reports', 'payments_report',
        'invoices', 'payments', 'payment_details', 'invoice_reminders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Finance Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id'          => $packageId,
            'name'                => 'Finance Admin',
            'email'               => 'finadmin+' . uniqid() . '@example.test',
            'password'            => Hash::make('password'),
            'subscription_status' => 'active',
            'created_at'          => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyA = $this->makeCompany($adminId, 'Finance Company A');
        $this->companyB = $this->makeCompany($adminId, 'Finance Company B');

        $this->invoiceA = $this->makeInvoice($this->companyA, 'A', 1000, 250);
        $this->invoiceB = $this->makeInvoice($this->companyB, 'B', 500, 0);

        $this->paymentA = Payment::create([
            'invoice_id' => $this->invoiceA->id, 'amount' => 250, 'currency' => 'USD',
            'method' => 'bank_transfer', 'status' => 'confirmed',
            'receipt_number' => 'RCP-TEST-A-' . uniqid(), 'payment_date' => now()->toDateString(),
        ]);
        $this->paymentB = Payment::create([
            'invoice_id' => $this->invoiceB->id, 'amount' => 100, 'currency' => 'USD',
            'method' => 'cash', 'status' => 'confirmed',
            'receipt_number' => 'RCP-TEST-B-' . uniqid(), 'payment_date' => now()->toDateString(),
        ]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(int $adminId, string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => $name, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // company_modules carries no timestamps — see its 2024_01_01 migration.
        foreach (self::MODULES as $key) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $key, 'is_enabled' => true,
            ]);
        }

        return $id;
    }

    private function makeInvoice(int $companyId, string $tag, float $total, float $paid): Invoice
    {
        $clientId = DB::table('clients')->insertGetId([
            'company_id' => $companyId, 'name' => "Client {$tag}",
            'email' => 'client' . uniqid() . '@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::create([
            'company_id'     => $companyId,
            'client_id'      => $clientId,
            'invoice_number' => "INV-{$tag}-" . uniqid(),
            'subtotal'       => $total,
            'total_amount'   => $total,
            'paid_amount'    => $paid,
            'currency'       => 'USD',
            'status'         => $paid > 0 ? 'partially_paid' : 'sent',
            'due_date'       => now()->addDays(10)->toDateString(),
            'sent_at'        => now(),
        ]);
    }

    /**
     * A staff user in $companyId holding exactly $permissions (module =>
     * keys) and nothing else. Starting from nothing rather than from a role's
     * defaults is the point: it is how a single key can be shown to unlock
     * exactly one endpoint.
     */
    private function staff(int $companyId, array $permissions = []): User
    {
        $user = User::create([
            'company_id' => $companyId,
            'name'       => 'Finance Staff',
            'email'      => 'fin+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => 'finance',
            'is_active'  => true,
        ]);

        $assignmentId = DB::table('company_user_assignments')->insertGetId([
            'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $moduleKey => $keys) {
            foreach ($keys as $key) {
                UserCompanyPermission::create([
                    'company_user_id' => $assignmentId,
                    'user_id'         => $user->id,
                    'company_id'      => $companyId,
                    'module_key'      => $moduleKey,
                    'permission_key'  => $key,
                ]);
            }
        }

        return $user;
    }

    /** Acts as $user with the active-company header the staff routes read. */
    private function asStaff(User $user, ?int $companyId = null): self
    {
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Active-Company-Id', (string) ($companyId ?? $user->company_id));

        return $this;
    }

    private function asAdmin(?int $companyId = null): self
    {
        Sanctum::actingAs($this->admin, ['*'], 'admin');
        $this->withHeader('X-Active-Company-Id', (string) ($companyId ?? $this->companyA));

        return $this;
    }

    // ── Company Admin ───────────────────────────────────────────────────────

    public function test_company_admin_has_full_finance_access_without_any_permission_rows(): void
    {
        // The admin guard IS the authorization — no user_company_permissions
        // row exists for a CompanyAdmin, and none should be needed.
        foreach ([
            '/api/admin/finance/dashboard',
            '/api/admin/finance/invoices',
            '/api/admin/finance/payments',
            '/api/admin/finance/payment-details',
            '/api/admin/finance/invoice-reminders',
            '/api/admin/finance/reports/finance',
            '/api/admin/finance/reports/revenue',
            '/api/admin/finance/reports/payments',
        ] as $url) {
            $this->asAdmin()->getJson($url)->assertOk();
        }
    }

    public function test_company_admin_capabilities_are_all_true(): void
    {
        $caps = $this->asAdmin()->getJson('/api/admin/finance/capabilities')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertNotEmpty($caps);
        foreach ($caps as $key => $value) {
            $this->assertTrue($value, "Company Admin should hold {$key}");
        }
    }

    // ── No Finance permissions at all ───────────────────────────────────────

    public function test_a_user_with_no_finance_permissions_is_refused_everywhere(): void
    {
        $user = $this->staff($this->companyA);

        foreach ([
            '/api/user/finance/dashboard',
            '/api/user/finance/invoices',
            '/api/user/finance/payments',
            '/api/user/finance/payment-details',
            '/api/user/finance/reports/finance',
            '/api/user/finance/reports/revenue',
            '/api/user/finance/reports/payments',
            '/api/user/finance/invoices/export',
            '/api/user/finance/payment-details/export',
        ] as $url) {
            $this->asStaff($user)->getJson($url)->assertStatus(403);
        }
    }

    public function test_capabilities_answers_all_false_rather_than_refusing(): void
    {
        // The one Finance endpoint a permission-less user may reach: the UI
        // has to be able to tell "no access" from "server error".
        $caps = $this->asStaff($this->staff($this->companyA))
            ->getJson('/api/user/finance/capabilities')
            ->assertOk()
            ->json('data.capabilities');

        foreach ($caps as $key => $value) {
            $this->assertFalse($value, "{$key} should be false for a user granted nothing");
        }
    }

    // ── Granular permissions ────────────────────────────────────────────────

    public function test_invoice_view_alone_unlocks_only_the_invoice_list(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson('/api/user/finance/invoices')->assertOk();
        $this->asStaff($user)->getJson('/api/user/finance/invoices/export')->assertStatus(403);
        $this->asStaff($user)->getJson('/api/user/finance/payments')->assertStatus(403);
        $this->asStaff($user)->getJson('/api/user/finance/dashboard')->assertStatus(403);
        $this->asStaff($user)->getJson('/api/user/finance/reports/finance')->assertStatus(403);
    }

    public function test_invoice_export_needs_its_own_permission(): void
    {
        $viewer = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($viewer)->getJson('/api/user/finance/invoices/export')->assertStatus(403);

        $exporter = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canExportFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($exporter)->get('/api/user/finance/invoices/export')->assertOk();
    }

    public function test_payment_view_does_not_grant_reconcile(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewPayments', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson('/api/user/finance/payments')->assertOk();
        $this->asStaff($user)
            ->patchJson("/api/user/finance/payments/{$this->paymentA->id}/reconcile")
            ->assertStatus(403);

        $this->assertSame('unreconciled', $this->paymentA->fresh()->reconciliation_status);
    }

    public function test_reconcile_permission_records_who_and_when(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewPayments', 'canReconcilePayments', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)
            ->patchJson("/api/user/finance/payments/{$this->paymentA->id}/reconcile", ['note' => 'Checked against statement'])
            ->assertOk();

        $payment = $this->paymentA->fresh();
        $this->assertSame('reconciled', $payment->reconciliation_status);
        $this->assertSame($user->id, $payment->reconciled_by);
        $this->assertNotNull($payment->reconciled_at);
        $this->assertSame('Checked against statement', $payment->reconciliation_note);

        // Reconciliation is bookkeeping, not money movement: neither the
        // payment's own status nor the invoice's balance may change.
        $this->assertSame('confirmed', $payment->status);
        $this->assertEquals(250, (float) $this->invoiceA->fresh()->paid_amount);

        $this->assertDatabaseHas('system_audit_logs', [
            'company_id'  => $this->companyA,
            'user_id'     => $user->id,
            'action'      => 'finance_payment_reconciled',
            'module_key'  => 'finance',
            'entity_type' => 'Payment',
            'entity_id'   => $payment->id,
        ]);
    }

    public function test_payment_details_reconcile_is_the_narrower_grant(): void
    {
        // Holding ONLY the payment-details reconcile key reconciles from that
        // screen, and does not unlock the payments-list action.
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewPaymentDetails', 'canReconcilePaymentDetails', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)
            ->patchJson("/api/user/finance/payments/{$this->paymentA->id}/reconcile")
            ->assertStatus(403);

        $this->asStaff($user)
            ->patchJson("/api/user/finance/payment-details/{$this->paymentA->id}/reconcile")
            ->assertOk();

        $this->assertSame('reconciled', $this->paymentA->fresh()->reconciliation_status);
    }

    public function test_recording_a_payment_needs_the_record_permission_and_credits_the_invoice(): void
    {
        $viewer = $this->staff($this->companyA, ['finance' => ['canViewPayments', 'canViewAllCompanyFinance']]);
        $this->asStaff($viewer)
            ->postJson("/api/user/finance/invoices/{$this->invoiceA->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(403);

        $recorder = $this->staff($this->companyA, [
            'finance' => ['canViewPayments', 'canRecordPayments', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($recorder)
            ->postJson("/api/user/finance/invoices/{$this->invoiceA->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(201);

        // 250 already paid + 100 now.
        $this->assertEquals(350, (float) $this->invoiceA->fresh()->paid_amount);

        // Recorded by hand means confirmed, but NOT yet reconciled — the two
        // are deliberately separate acts.
        $latest = Payment::where('invoice_id', $this->invoiceA->id)->latest('id')->first();
        $this->assertSame('confirmed', $latest->status);
        $this->assertSame('unreconciled', $latest->reconciliation_status);
        $this->assertSame($recorder->id, $latest->recorded_by);
    }

    public function test_a_payment_cannot_exceed_the_invoice_balance(): void
    {
        $recorder = $this->staff($this->companyA, [
            'finance' => ['canViewPayments', 'canRecordPayments', 'canViewAllCompanyFinance'],
        ]);

        // 750 outstanding; 800 must be refused.
        $this->asStaff($recorder)
            ->postJson("/api/user/finance/invoices/{$this->invoiceA->id}/payments", ['amount' => 800, 'method' => 'cash'])
            ->assertStatus(422);

        $this->assertEquals(250, (float) $this->invoiceA->fresh()->paid_amount);
    }

    public function test_each_report_is_granted_independently(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewRevenueReports', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson('/api/user/finance/reports/revenue')->assertOk();
        $this->asStaff($user)->getJson('/api/user/finance/reports/finance')->assertStatus(403);
        $this->asStaff($user)->getJson('/api/user/finance/reports/payments')->assertStatus(403);

        // Export is an extra right on top of reading, never a way around it.
        $this->asStaff($user)->getJson('/api/user/finance/reports/revenue/export')->assertStatus(403);
    }

    public function test_report_export_requires_both_view_and_export(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewRevenueReports', 'canExportRevenueReports', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->get('/api/user/finance/reports/revenue/export')->assertOk();

        // Holding export WITHOUT view still cannot read the report.
        $exportOnly = $this->staff($this->companyA, [
            'finance' => ['canExportRevenueReports', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($exportOnly)->getJson('/api/user/finance/reports/revenue/export')->assertStatus(403);
    }

    // ── Company isolation ───────────────────────────────────────────────────

    public function test_a_user_cannot_read_another_companys_invoice_by_id(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson("/api/user/finance/invoices/{$this->invoiceA->id}")->assertOk();
        // Same endpoint, an id from the other company: 404, never 403 — a 403
        // would confirm the row exists somewhere.
        $this->asStaff($user)->getJson("/api/user/finance/invoices/{$this->invoiceB->id}")->assertStatus(404);
    }

    public function test_a_user_cannot_read_or_reconcile_another_companys_payment(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewPayments', 'canViewPaymentDetails', 'canReconcilePayments', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson("/api/user/finance/payments/{$this->paymentA->id}")->assertOk();
        $this->asStaff($user)->getJson("/api/user/finance/payments/{$this->paymentB->id}")->assertStatus(404);
        $this->asStaff($user)->getJson("/api/user/finance/payment-details/{$this->paymentB->id}")->assertStatus(404);

        $this->asStaff($user)
            ->patchJson("/api/user/finance/payments/{$this->paymentB->id}/reconcile")
            ->assertStatus(404);

        $this->assertSame('unreconciled', $this->paymentB->fresh()->reconciliation_status);
    }

    public function test_a_users_lists_never_contain_another_companys_rows(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewPayments', 'canViewAllCompanyFinance'],
        ]);

        $numbers = $this->asStaff($user)->getJson('/api/user/finance/invoices')
            ->assertOk()->json('data.invoices.*.invoice_number');
        $this->assertContains($this->invoiceA->invoice_number, $numbers);
        $this->assertNotContains($this->invoiceB->invoice_number, $numbers);

        $companyIds = $this->asStaff($user)->getJson('/api/user/finance/payments')
            ->assertOk()->json('data.payments.*.company_id');
        $this->assertSame([$this->companyA], array_values(array_unique($companyIds)));
    }

    public function test_the_company_filter_cannot_widen_scope(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        // Asking for company B explicitly: the filter is intersected with the
        // ids already in scope, so it narrows to nothing rather than leaking.
        $numbers = $this->asStaff($user)
            ->getJson('/api/user/finance/invoices?company_id=' . $this->companyB)
            ->assertOk()->json('data.invoices.*.invoice_number');

        $this->assertNotContains($this->invoiceB->invoice_number, $numbers);
    }

    public function test_a_forged_active_company_header_does_not_grant_access(): void
    {
        // The user has no assignment in company B, so the header resolves
        // back to their real company rather than the one they asked for.
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        $numbers = $this->asStaff($user, $this->companyB)
            ->getJson('/api/user/finance/invoices')
            ->assertOk()->json('data.invoices.*.invoice_number');

        $this->assertNotContains($this->invoiceB->invoice_number, $numbers);
    }

    public function test_reports_only_aggregate_the_callers_own_company(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceReports', 'canViewAllCompanyFinance'],
        ]);

        $byCurrency = $this->asStaff($user)->getJson('/api/user/finance/reports/finance')
            ->assertOk()->json('data.report.invoices.by_currency');

        // Company A alone has 1000 invoiced; B's 500 must not be included.
        $this->assertEquals(1000, $byCurrency[0]['total_invoiced']);
    }

    public function test_an_export_contains_only_the_callers_own_rows(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canExportFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        $csv = $this->asStaff($user)->get('/api/user/finance/invoices/export')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString($this->invoiceA->invoice_number, $csv);
        $this->assertStringNotContainsString($this->invoiceB->invoice_number, $csv);
    }

    // ── Record-level scope within one company ───────────────────────────────

    public function test_without_the_company_wide_key_a_user_sees_only_their_own_records(): void
    {
        $user = $this->staff($this->companyA, ['finance' => ['canViewFinanceInvoices']]);

        // invoiceA was created by nobody in particular, so it is not "theirs".
        $numbers = $this->asStaff($user)->getJson('/api/user/finance/invoices')
            ->assertOk()->json('data.invoices.*.invoice_number');
        $this->assertNotContains($this->invoiceA->invoice_number, $numbers);

        $own = $this->makeInvoice($this->companyA, 'OWN', 300, 0);
        $own->update(['created_by' => $user->id]);

        $numbers = $this->asStaff($user)->getJson('/api/user/finance/invoices')
            ->assertOk()->json('data.invoices.*.invoice_number');
        $this->assertContains($own->invoice_number, $numbers);
    }

    // ── Sensitive data ──────────────────────────────────────────────────────

    public function test_payment_details_never_expose_gateway_internals_or_card_data(): void
    {
        $this->paymentA->update([
            'method'             => 'gateway',
            'gateway'            => 'stripe',
            'gateway_ref'        => 'txn_visible_reference',
            'gateway_session_id' => 'cs_test_secret_session_handle',
        ]);

        $user = $this->staff($this->companyA, [
            'finance' => ['canViewPaymentDetails', 'canViewAllCompanyFinance'],
        ]);

        $body = $this->asStaff($user)
            ->getJson("/api/user/finance/payment-details/{$this->paymentA->id}")
            ->assertOk()
            ->getContent();

        // The merchant-side transaction reference IS what reconciliation
        // needs, so it is shown.
        $this->assertStringContainsString('txn_visible_reference', $body);

        // The checkout-session handle, the gateway credential blob and
        // anything card-shaped are not serialised by FinancePresenter and
        // must not appear.
        foreach ([
            'cs_test_secret_session_handle',
            'gateway_session_id',
            'config',
            'card_number',
            'cvv',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Response leaked {$forbidden}");
        }
    }

    // ── Invoice reminders ───────────────────────────────────────────────────

    public function test_creating_a_reminder_needs_the_create_permission(): void
    {
        $tracker = $this->staff($this->companyA, [
            'finance' => ['canTrackInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($tracker)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceA->id])
            ->assertStatus(403);

        $creator = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($creator)
            ->postJson('/api/user/finance/invoice-reminders', [
                'invoice_id'    => $this->invoiceA->id,
                'reminder_date' => now()->addDay()->toDateString(),
                'message'       => 'Gentle nudge',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.reminder.status', 'scheduled');

        $this->assertDatabaseHas('invoice_reminders', [
            'invoice_id' => $this->invoiceA->id,
            'company_id' => $this->companyA,
            'created_by' => $creator->id,
            'status'     => 'scheduled',
        ]);
    }

    public function test_creating_a_reminder_never_implies_sending_one(): void
    {
        Mail::fake();

        $creator = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);

        // send_now is asked for but the SEND permission is absent: the
        // reminder is kept (it was validly created) and nothing goes out.
        $this->asStaff($creator)
            ->postJson('/api/user/finance/invoice-reminders', [
                'invoice_id' => $this->invoiceA->id,
                'send_now'   => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.reminder.status', 'scheduled');

        Mail::assertNothingSent();
    }

    public function test_sending_a_reminder_records_the_delivery(): void
    {
        Mail::fake();

        $sender = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canSendInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);

        $reminderId = $this->asStaff($sender)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceA->id])
            ->assertStatus(201)
            ->json('data.reminder.id');

        $this->asStaff($sender)
            ->postJson("/api/user/finance/invoice-reminders/{$reminderId}/send")
            ->assertOk()
            ->assertJsonPath('data.reminder.status', 'sent');

        Mail::assertSent(\App\Mail\InvoiceReminderMail::class);

        $reminder = InvoiceReminder::findOrFail($reminderId);
        $this->assertSame($sender->id, $reminder->sent_by);
        $this->assertNotNull($reminder->sent_at);
        $this->assertNotNull($reminder->sent_to);
    }

    public function test_a_settled_invoice_is_never_chased(): void
    {
        Mail::fake();

        $this->invoiceA->update(['paid_amount' => $this->invoiceA->total_amount, 'status' => 'paid']);

        $sender = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canSendInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);

        $reminderId = $this->asStaff($sender)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceA->id])
            ->assertStatus(201)
            ->json('data.reminder.id');

        // Checked at SEND time, not create time — an invoice can be settled
        // between the two.
        $this->asStaff($sender)
            ->postJson("/api/user/finance/invoice-reminders/{$reminderId}/send")
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame('failed', InvoiceReminder::findOrFail($reminderId)->status);
    }

    public function test_a_reminder_cannot_be_created_against_another_companys_invoice(): void
    {
        $creator = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($creator)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceB->id])
            ->assertStatus(404);

        $this->assertDatabaseMissing('invoice_reminders', ['invoice_id' => $this->invoiceB->id]);
    }

    public function test_without_track_a_user_sees_only_the_reminders_they_raised(): void
    {
        $other = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);
        $this->asStaff($other)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceA->id])
            ->assertStatus(201);

        $creator = $this->staff($this->companyA, [
            'finance' => ['canCreateInvoiceReminders', 'canViewAllCompanyFinance'],
        ]);
        $mine = $this->asStaff($creator)
            ->postJson('/api/user/finance/invoice-reminders', ['invoice_id' => $this->invoiceA->id])
            ->assertStatus(201)->json('data.reminder.id');

        $ids = $this->asStaff($creator)->getJson('/api/user/finance/invoice-reminders')
            ->assertOk()->json('data.reminders.*.id');

        $this->assertSame([$mine], $ids);
    }

    // ── Suspended membership ────────────────────────────────────────────────

    public function test_a_suspended_assignment_loses_finance_access_immediately(): void
    {
        $user = $this->staff($this->companyA, [
            'finance' => ['canViewFinanceInvoices', 'canViewAllCompanyFinance'],
        ]);

        $this->asStaff($user)->getJson('/api/user/finance/invoices')->assertOk();

        DB::table('company_user_assignments')
            ->where('user_id', $user->id)->where('company_id', $this->companyA)
            ->update(['status' => 'suspended']);

        // No re-login needed: the company no longer resolves at all.
        $this->asStaff($user)->getJson('/api/user/finance/invoices')->assertStatus(403);
    }

    // ── Dashboard figures come from real rows ───────────────────────────────

    public function test_the_dashboard_reports_the_databases_actual_figures(): void
    {
        $data = $this->asAdmin()->getJson('/api/admin/finance/dashboard')
            ->assertOk()->json('data.dashboard');

        $usd = collect($data['overview']['by_currency'])->firstWhere('currency', 'USD');

        $this->assertEquals(1000, $usd['total_invoiced']);
        $this->assertEquals(250, $usd['total_paid']);
        $this->assertEquals(750, $usd['total_outstanding']);
        $this->assertSame(1, $data['overview']['counts']['total']);
        $this->assertSame(1, $data['payments']['counts']['confirmed']);
        $this->assertSame(1, $data['payments']['counts']['unreconciled']);
    }
}
