<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyDealSettings;
use App\Models\CompanyPaymentGateway;
use App\Models\Payment;
use App\Support\PublicFile;
use App\Support\SettingsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Company profile, invoice defaults, bank details, payment gateways and the
// deal workflow are all PER-COMPANY (2026-09-11): every read and write on
// this controller is scoped to one selected company, so Company A's settings
// and Company B's settings never touch each other.
//
// They were tenant-level (one shared configuration on the company_admins row,
// used by every company the admin owns) from 2026-07-17 until then, which is
// exactly the bug this fixes: saving Settings while Company A was selected
// silently rewrote Company B's invoice prefix, tax rate, bank details and
// gateway credentials too. See:
//   - Company::invoicingProfile() -- the read precedence, now company-first
//   - 2026_09_11_100000_backfill_per_company_settings -- copied each tenant's
//     then-effective profile into every company row, so the flip was a no-op
//     on the day it shipped
//   - 2026_09_11_100100_scope_payment_gateways_to_company
//   - 2026_09_11_100200_scope_deal_settings_to_company
//
// Which company is being edited comes from ONE place: the app-wide company
// filter in the topbar (components/admin/CompanySelector), sent on every
// request as X-Active-Company-Id and resolved here by ScopesToActiveCompany.
// It is always intersected with companyIds(), so a tampered or foreign id can
// never reach another tenant.
//
// Settings deliberately has no company picker of its own. It briefly did, and
// two controls selecting the same thing is precisely the confusion that
// removed: the page could sit on Company B while the topbar read Company A,
// with no indication which one a save would land on. Changing the topbar
// filter reloads the page (see the CompanySelector's onChange in
// components/layout/Navbar.tsx), so Settings re-reads under the new company
// with no extra wiring.
//
// Api\User\SettingsController is the staff-side counterpart: same payload,
// same per-company scoping, gated on the account module's canManageSettings
// permission instead of the `admin` guard.
class SettingsController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->orderBy('id')->pluck('id')->toArray();
    }

    /**
     * The company whose Settings this request is reading or writing: whichever
     * one the app-wide company filter is currently on.
     *
     * That filter (the topbar CompanySelector, persisted as the
     * active_company_id cookie and sent as X-Active-Company-Id on every
     * request) is the ONE source of truth — Settings deliberately has no
     * picker of its own. It had one briefly, and two controls choosing the
     * same thing is exactly the confusion this removes: the page could sit on
     * Company B while the topbar said Company A.
     *
     * Returns null in "All Companies" mode. activeCompanyId() would silently
     * fall back to the first owned company there, which is the dangerous
     * answer — the admin reads "All Companies" and their save lands on one
     * particular company without saying so. Settings is inherently about one
     * company, so callers refuse instead; see allCompaniesMode().
     */
    private function settingsCompany(): ?Company
    {
        if ($this->allCompaniesMode()) {
            return null;
        }

        $companyId = $this->activeCompanyId();

        return $companyId ? Company::find($companyId) : null;
    }

    /**
     * True when the topbar filter is on "All Companies" — the literal string
     * the CompanySelector writes (never a numeric id), the same sentinel
     * ScopesToActiveCompany::activeCompanyIds() keys off.
     */
    private function allCompaniesMode(): bool
    {
        return request()->header('X-Active-Company-Id') === 'all'
            && count($this->companyIds()) > 1;
    }

    /**
     * The refusal every write shares: there is no single company to write to.
     * A 422 rather than a silent no-op so the screen can say why.
     */
    private function noCompanySelected(): JsonResponse
    {
        return $this->allCompaniesMode()
            ? ApiResponse::error('Settings belong to one company. Pick a single company in the company filter at the top, then try again.', 422)
            : ApiResponse::error('Create a company before configuring its settings.', 422);
    }

    // =========================================================================
    // GET /api/admin/settings
    // =========================================================================
    public function show(): JsonResponse
    {
        $company = $this->settingsCompany();

        if (!$company) {
            // Deliberately a 200, not an error: "the filter is on All
            // Companies" is a normal state of the screen, not a failure, and
            // an error status here would fire the frontend's generic error
            // toast on every visit made in that mode.
            return ApiResponse::success([
                'requires_company_selection' => true,
                'all_companies_mode'         => $this->allCompaniesMode(),
            ]);
        }

        return ApiResponse::success(
            SettingsPayload::forCompany($company) + [
                'requires_company_selection' => false,
                'company_id'                 => $company->id,
            ]
        );
    }

    // =========================================================================
    // PUT /api/admin/settings/company
    // =========================================================================
    public function updateCompany(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsPayload::companyRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        // companies.name is deliberately not writable here -- renaming a
        // company goes through Api\Admin\ClientController::updateCompany(),
        // which owns the CompanyName uniqueness check. The Settings screen
        // renders it as a disabled field for the same reason.
        $company->update(SettingsPayload::companyAttributes($validated));

        return ApiResponse::success(null, 'Company profile updated');
    }

    // =========================================================================
    // PUT /api/admin/settings/invoice
    // =========================================================================
    public function updateInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsPayload::invoiceRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $company->update(SettingsPayload::invoiceAttributes($validated));

        return ApiResponse::success(null, 'Invoice settings updated');
    }

    // =========================================================================
    // PUT /api/admin/settings/bank
    // =========================================================================
    public function updateBank(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsPayload::bankRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $company->update(SettingsPayload::bankAttributes($validated));

        return ApiResponse::success(null, 'Bank details updated');
    }

    // Ownership-checked lookup shared by every id-based gateway endpoint
    // below -- scoped to the SELECTED COMPANY, not just the tenant, so an
    // admin editing Company A can never reach Company B's gateway account
    // even though both belong to them.
    private function ownedGateway(int $id): ?CompanyPaymentGateway
    {
        $company = $this->settingsCompany();
        if (!$company) {
            return null;
        }

        return CompanyPaymentGateway::where('company_id', $company->id)
            ->where('id', $id)
            ->first();
    }

    // =========================================================================
    // POST /api/admin/settings/gateways -- add a new gateway ACCOUNT
    // =========================================================================
    public function storeGateway(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsPayload::gatewayStoreRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $row = SettingsPayload::createGateway($company, $validated);

        return ApiResponse::success(['id' => $row->id], $validated['label'] . ' added');
    }

    // =========================================================================
    // PUT /api/admin/settings/gateways/{id} -- edit an existing account
    // =========================================================================
    public function updateGateway(Request $request, int $id): JsonResponse
    {
        $row = $this->ownedGateway($id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $validated = $request->validate(SettingsPayload::gatewayUpdateRules());

        $row->update([
            'label'     => $validated['label'],
            'is_active' => $validated['is_active'],
            'config'    => SettingsPayload::mergeConfig($row->config ?? [], $validated['config'] ?? []),
        ]);

        return ApiResponse::success(null, $row->label . ' settings saved');
    }

    // =========================================================================
    // PATCH /api/admin/settings/gateways/{id}/toggle
    // =========================================================================
    public function toggleGateway(Request $request, int $id): JsonResponse
    {
        $row = $this->ownedGateway($id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $row->update(['is_active' => $validated['is_active']]);

        return ApiResponse::success(null, $row->label . ($validated['is_active'] ? ' enabled' : ' disabled'));
    }

    // =========================================================================
    // PATCH /api/admin/settings/gateways/{id}/default -- make this account the
    // default for its gateway type (unsets any sibling default of that type)
    // =========================================================================
    public function setDefaultGateway(int $id): JsonResponse
    {
        $row = $this->ownedGateway($id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        SettingsPayload::makeDefault($row);

        return ApiResponse::success(null, $row->label . ' set as default');
    }

    // =========================================================================
    // DELETE /api/admin/settings/gateways/{id}
    // =========================================================================
    public function destroyGateway(int $id): JsonResponse
    {
        $row = $this->ownedGateway($id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $usedInPaidInvoice = Payment::where('company_gateway_id', $id)
            ->where('status', 'confirmed')
            ->exists();

        if ($usedInPaidInvoice) {
            $row->update(['is_active' => false]);
            return ApiResponse::error(
                'Cannot delete a gateway account used in paid invoices -- it has been deactivated instead.',
                422
            );
        }

        $row->delete();

        return ApiResponse::success(null, 'Gateway account deleted');
    }

    // =========================================================================
    // POST /api/admin/settings/gateways/{id}/test
    // =========================================================================
    public function testGateway(int $id): JsonResponse
    {
        $row = $this->ownedGateway($id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $result = SettingsPayload::testGateway($row);

        return $result['success']
            ? ApiResponse::success(null, $result['message'])
            : ApiResponse::error($result['message'], 422);
    }

    // =========================================================================
    // POST /api/admin/settings/logo
    // =========================================================================
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(SettingsPayload::logoRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        $path = SettingsPayload::replaceLogo($company, $request->file('logo'));

        return ApiResponse::success(['logo_url' => PublicFile::url($path)], 'Logo uploaded');
    }

    // =========================================================================
    // GET /api/admin/settings/deal-workflow -- Lead-Won -> Deal eligibility ->
    // Project creation configuration (per-company, same scoping as gateways).
    // =========================================================================
    public function dealWorkflowSettings(): JsonResponse
    {
        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        return ApiResponse::success(SettingsPayload::dealWorkflow($company));
    }

    // =========================================================================
    // PUT /api/admin/settings/deal-workflow
    // =========================================================================
    public function updateDealWorkflowSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsPayload::dealWorkflowRules());

        $company = $this->settingsCompany();
        if (!$company) {
            return $this->noCompanySelected();
        }

        CompanyDealSettings::updateOrCreate(
            ['company_id' => $company->id],
            $validated + ['company_admin_id' => $company->admin_id]
        );

        return ApiResponse::success(null, 'Deal workflow settings saved');
    }
}
