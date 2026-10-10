<?php

namespace Tests\Unit;

use App\Services\ModuleDependency;
use PHPUnit\Framework\TestCase;

// Regression coverage for App\Services\ModuleDependency::errors() — the single
// backend validator shared by PublicController::register(),
// SubscriptionPaymentController::process(), and ModulePurchaseController::purchase().
// Exercises the Compliance -> Projects + Invoice rule alongside every
// pre-existing dependency (Sales/Finance -> Invoice, Client -> Invoice OR
// Project) to confirm the additions didn't disturb them.
//
// INVOICE -> CLIENT (b04543d) is why several selections below carry 'client'
// even where the rule under test has nothing to do with clients: that commit
// made Invoice require Client, so every otherwise-valid selection containing
// Invoice needs one to stay valid. Each such test still pins the rule its name
// describes — 'client' is only there to keep the selection legal.
class ModuleDependencyTest extends TestCase
{
    // ── Compliance (new rule) ────────────────────────────────────────────
    public function test_compliance_alone_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['compliance']);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    public function test_compliance_with_only_projects_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['compliance', 'projects']);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    public function test_compliance_with_only_invoice_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['compliance', 'invoice']);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    public function test_compliance_with_projects_and_invoice_but_no_finance_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['compliance', 'projects', 'invoice', 'client']);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    public function test_compliance_with_projects_invoice_and_finance_is_valid(): void
    {
        $errors = ModuleDependency::errors(['compliance', 'projects', 'invoice', 'client', 'finance']);
        $this->assertSame([], $errors);
    }

    // Granular sub-module keys (what actually gets sent from the custom
    // module picker / stored on Package rows) resolve the same as the
    // top-level category keys used above.
    public function test_compliance_valid_via_granular_submodule_keys(): void
    {
        $errors = ModuleDependency::errors([
            'compliance', 'policies', 'audit_trails', 'compliance_reports', 'risk_assessments', 'alerts', 'document_compliance',
            'projects', 'tasks', 'timesheets',
            'invoice', 'invoices', 'payments',
            'client_portal', 'client_documents',
            'finance', 'finance_dashboard', 'finance_reports',
        ]);
        $this->assertSame([], $errors);
    }

    public function test_compliance_invalid_via_granular_submodule_keys_missing_invoice(): void
    {
        $errors = ModuleDependency::errors([
            'compliance', 'policies',
            'projects', 'tasks',
        ]);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    // ── Pre-existing rules (regression) ──────────────────────────────────
    public function test_sales_alone_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['sales']);
        $this->assertContains('Invoice module is required because Sales includes invoice features.', $errors);
    }

    public function test_sales_with_invoice_is_valid(): void
    {
        $errors = ModuleDependency::errors(['sales', 'invoice', 'client']);
        $this->assertSame([], $errors);
    }

    public function test_finance_alone_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['finance']);
        $this->assertContains('Invoice module is required because Finance depends on invoice and payment data.', $errors);
    }

    public function test_finance_with_invoice_is_valid(): void
    {
        $errors = ModuleDependency::errors(['finance', 'invoice', 'client']);
        $this->assertSame([], $errors);
    }

    public function test_client_alone_is_invalid(): void
    {
        $errors = ModuleDependency::errors(['client']);
        $this->assertContains('Client module requires Invoice or Project.', $errors);
    }

    public function test_client_with_invoice_is_valid(): void
    {
        $errors = ModuleDependency::errors(['client', 'invoice']);
        $this->assertSame([], $errors);
    }

    public function test_client_with_projects_is_valid(): void
    {
        $errors = ModuleDependency::errors(['client', 'projects']);
        $this->assertSame([], $errors);
    }

    // ── Invoice -> Client (b04543d) ──────────────────────────────────────
    //
    // Invoice alone USED to be valid and this test asserted so. That changed
    // deliberately: an invoice has to be billed to somebody, so buying Invoice
    // now requires Client. Kept (inverted) rather than deleted, so the rule has
    // a test that fails loudly if it is ever dropped by accident.
    public function test_invoice_alone_is_invalid_without_client(): void
    {
        $errors = ModuleDependency::errors(['invoice']);
        $this->assertContains('Client module is required because Invoice needs a client to bill.', $errors);
    }

    public function test_invoice_with_client_is_valid(): void
    {
        $this->assertSame([], ModuleDependency::errors(['invoice', 'client']));
    }

    /**
     * The grandfathering half of the rule — the reason errors() takes a second
     * argument at all.
     *
     * A company that already owned Invoice before this rule existed must not be
     * locked out of buying something unrelated later. ModulePurchaseController
     * passes only the keys in THIS purchase as $newKeys, so the check fires on
     * what is being bought, not on what is already owned.
     */
    public function test_an_existing_invoice_owner_is_not_blocked_from_a_later_unrelated_purchase(): void
    {
        $errors = ModuleDependency::errors(
            ['invoice', 'projects'], // owned + newly requested
            ['projects'],            // only Projects is being bought now
        );

        $this->assertSame([], $errors);
    }

    /** But buying Invoice itself still requires Client, however it is bought. */
    public function test_buying_invoice_later_still_requires_client(): void
    {
        $errors = ModuleDependency::errors(['projects', 'invoice'], ['invoice']);

        $this->assertContains('Client module is required because Invoice needs a client to bill.', $errors);
    }

    public function test_projects_alone_is_valid(): void
    {
        $this->assertSame([], ModuleDependency::errors(['projects']));
    }

    public function test_hr_alone_is_valid(): void
    {
        $this->assertSame([], ModuleDependency::errors(['hr']));
    }

    // ── Combined selections ──────────────────────────────────────────────
    public function test_sales_and_compliance_together_needs_both_dependencies(): void
    {
        $errors = ModuleDependency::errors(['sales', 'compliance']);
        $this->assertContains('Invoice module is required because Sales includes invoice features.', $errors);
        $this->assertContains('Compliance requires the Project, Invoice, and Finance modules.', $errors);
    }

    public function test_everything_selected_together_is_valid(): void
    {
        $errors = ModuleDependency::errors(['sales', 'finance', 'client', 'compliance', 'projects', 'invoice', 'hr']);
        $this->assertSame([], $errors);
    }
}
