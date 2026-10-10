<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gate every Finance endpoint passes through.
 *
 * Section 10 of the Finance spec asks each endpoint to verify six things.
 * They are enforced here, once, rather than re-typed in thirty methods where
 * the thirty-first would eventually miss one:
 *
 *   1. Authentication      FinanceContext::resolve() returns null with no
 *                          signed-in admin or sanctum user → 401.
 *   2. Active user         a suspended/deactivated staff account fails
 *                          membershipActive() → 403.
 *   3. Active company      route middleware (`company.subscription.active` /
 *                          `subscription.active`) has already refused a
 *                          lapsed subscription; hasCompany() then refuses a
 *                          caller with no company resolved at all.
 *   4. Company assignment  the company ids in the context are only ever ones
 *                          backed by an ACTIVE company_user_assignments row
 *                          (staff) or owned by this admin — see
 *                          FinanceContext.
 *   5. Finance permission  $required, checked per endpoint below.
 *   6. Resource ownership  NOT here — it belongs to the query, so every read
 *                          and write goes through FinanceScope, which filters
 *                          by the same company ids. A hand-edited id in the
 *                          URL simply does not match and the caller gets 404.
 *
 * Controllers extending this never call auth() themselves and never build
 * their own Invoice/Payment queries; doing either would step around one of
 * the six.
 */
abstract class FinanceController extends Controller
{
    protected ?FinanceContext $ctx = null;

    /**
     * Resolve the caller and assert they may perform $required.
     *
     * Returns a JsonResponse to send back on failure, or null to proceed —
     * the same "guard clause returns a response" shape the rest of this
     * codebase's controllers already use. On success $this->ctx is set.
     *
     * @param array<int, array{0: string, 1: string}>|string $required One
     *        permission key (Finance module), or a list of [module, key]
     *        pairs of which ANY one suffices.
     */
    protected function guard(Request $request, array|string $required): ?JsonResponse
    {
        $ctx = FinanceContext::resolve($request);

        if (!$ctx) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if (!$ctx->hasCompany()) {
            // Distinct from a permission failure: there is no company to
            // check a permission against. Same wording the module middleware
            // uses for the staff side so the UI reads one message.
            return ApiResponse::error('No active company for your account. Contact your admin.', 403);
        }

        if (!$ctx->membershipActive()) {
            return ApiResponse::error('Your access to this company is not active.', 403);
        }

        $pairs = is_string($required)
            ? [[FinanceContext::MODULE, $required]]
            : $required;

        if (!$ctx->canAny($pairs)) {
            return ApiResponse::error('Permission denied', 403);
        }

        $this->ctx = $ctx;

        return null;
    }

    /**
     * Resolve the caller WITHOUT requiring a specific permission.
     *
     * Only for the "what may I do here?" capability endpoint, whose whole
     * purpose is to answer that question for a caller who may hold nothing.
     */
    protected function guardAuthenticated(Request $request): ?JsonResponse
    {
        $ctx = FinanceContext::resolve($request);

        if (!$ctx) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if (!$ctx->hasCompany() || !$ctx->membershipActive()) {
            return ApiResponse::error('No active company for your account. Contact your admin.', 403);
        }

        $this->ctx = $ctx;

        return null;
    }

    /**
     * The one "not found" this module ever returns.
     *
     * Wrong company, someone else's record and genuinely-missing all answer
     * identically and deliberately: a 403 on a row that exists in another
     * company would confirm that it exists, which is itself a cross-tenant
     * disclosure.
     */
    protected function notFound(string $what = 'Record'): JsonResponse
    {
        return ApiResponse::error("{$what} not found", 404);
    }

    /**
     * What this caller may do in the Finance Area, for the UI to render
     * against. Advisory only — every action re-checks server-side, so a
     * stale or tampered copy of this buys nothing.
     *
     * @return array<string, bool>
     */
    protected function capabilities(FinanceContext $ctx): array
    {
        return [
            'dashboard.view'          => $ctx->can('canViewFinanceDashboard'),
            'revenue_dashboard.view'  => $ctx->can('canViewRevenueDashboard'),

            'invoices.view'           => $ctx->canFinanceOrInvoice('canViewFinanceInvoices', 'canViewInvoices'),
            'invoices.create'         => $ctx->canFinanceOrInvoice('canCreateFinanceInvoices', 'canCreateInvoices'),
            'invoices.update'         => $ctx->canFinanceOrInvoice('canUpdateFinanceInvoices', 'canEditInvoices'),
            'invoices.export'         => $ctx->canFinanceOrInvoice('canExportFinanceInvoices', 'canDownloadOrExportInvoices'),

            'payments.view'           => $ctx->canFinanceOrInvoice('canViewPayments', 'canViewPayments'),
            'payments.record'         => $ctx->canFinanceOrInvoice('canRecordPayments', 'canRecordPayments'),
            'payments.reconcile'      => $ctx->can('canReconcilePayments'),

            'payment_details.view'      => $ctx->can('canViewPaymentDetails'),
            // The wider key implies the narrower one — someone trusted to
            // reconcile from the list is certainly trusted to reconcile the
            // record they are looking at.
            'payment_details.reconcile' => $ctx->can('canReconcilePaymentDetails') || $ctx->can('canReconcilePayments'),
            'payment_details.export'    => $ctx->can('canExportPaymentDetails'),

            'reminders.create'        => $ctx->can('canCreateInvoiceReminders'),
            'reminders.send'          => $ctx->can('canSendInvoiceReminders'),
            'reminders.track'         => $ctx->can('canTrackInvoiceReminders'),

            'reports.finance'         => $ctx->can('canViewFinanceReports'),
            'reports.finance.export'  => $ctx->can('canExportFinanceReports'),
            'reports.revenue'         => $ctx->can('canViewRevenueReports'),
            'reports.revenue.export'  => $ctx->can('canExportRevenueReports'),
            'reports.payments'        => $ctx->can('canViewPaymentReports'),
            'reports.payments.export' => $ctx->can('canExportPaymentReports'),

            'scope.all_company'       => $ctx->scopeAll(),
        ];
    }
}
