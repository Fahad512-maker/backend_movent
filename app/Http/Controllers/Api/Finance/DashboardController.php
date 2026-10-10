<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Services\Finance\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance > Dashboard. Read-only by construction — there is no write method
 * on this controller at all, which is what "view-only according to the
 * user's Finance Dashboard permission" means in practice.
 *
 * Every figure comes from FinanceReportService, which reads live invoice and
 * payment rows through FinanceScope. Nothing on this screen is seeded,
 * sampled or hardcoded: a company with no invoices gets zeros and empty
 * lists.
 */
class DashboardController extends FinanceController
{
    // GET /{admin|user}/finance/dashboard
    public function index(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewFinanceDashboard')) {
            return $fail;
        }

        return ApiResponse::success([
            'dashboard'    => FinanceReportService::dashboard($this->ctx, $request),
            // So the UI can hide the Record Payment / Export / Reconcile
            // controls it must not offer. The server re-checks each one
            // anyway; this only decides what is worth rendering.
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * GET /{admin|user}/finance/capabilities
     *
     * What this caller may do across the whole Finance Area, for the sidebar
     * and the section tabs. Deliberately reachable by anyone signed in with
     * an active company, including someone holding no Finance permission at
     * all — the honest answer for them is a map of all-false, and refusing
     * the question would mean the UI could not tell "no access" from "server
     * error".
     */
    public function capabilitiesIndex(Request $request): JsonResponse
    {
        if ($fail = $this->guardAuthenticated($request)) {
            return $fail;
        }

        return ApiResponse::success([
            'capabilities' => $this->capabilities($this->ctx),
            'companies'    => collect($this->ctx->companyNames())
                ->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])
                ->values()
                ->all(),
        ]);
    }
}
