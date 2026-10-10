<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyDealSettings;
use App\Models\CompanyPaymentGateway;
use App\Models\CompanyUserAssignment;
use App\Models\Payment;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use App\Support\PermissionDebug;
use App\Support\PublicFile;
use App\Support\SettingsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff counterpart to Api\Admin\SettingsController, serving the SAME
// settings through the same App\Support\SettingsPayload -- so a staff member
// granted Settings Management really does manage what the Company Admin
// manages, not a reduced copy of it that drifts.
//
// ONE permission gates every route here: the account module's
// canManageSettings ("Settings Management Permission" on the Add/Edit User
// screen). It is not a default for ANY role, company_admin and admin
// included -- see RoleDefaultPermissions::ALWAYS_EXPLICIT_PERMISSIONS. A
// Company Admin ticks it by hand for the person who should have it.
//
// Revoking it takes effect on the very next request: can() below reads
// user_company_permissions live, with no caching and no role-based bypass, so
// unticking the box locks this controller immediately. (The staff sidebar
// reads permissions out of the session cookie, so the nav link can linger
// until the next /user/me refresh -- exactly as canAddUsers behaves -- but
// every endpoint behind it already answers 403.)
//
// ALWAYS one company: whichever the caller is currently working under
// (X-Active-Company-Id, validated against an ACTIVE assignment for them).
// Because both the permission row and the settings row are keyed to that
// company id, a user assigned to Company A and Company B can hold Settings
// Management for A only, and while A is selected cannot read or write a
// single one of B's settings.
//
// Deliberately NOT exposed here, unlike the Company Admin's own screen:
// module purchase / seat purchase / plan upgrade. Those spend money on the
// tenant's subscription and are account-level rather than company-level, so
// they stay with the account owner. The staff screen shows which modules the
// selected company has (read-only) and no purchase action.
class SettingsController extends Controller
{
    private const MODULE = 'account';
    private const PERMISSION = 'canManageSettings';

    private function user() { return auth('sanctum')->user(); }

    private function companyId(): int
    {
        $user      = $this->user();
        $requested = (int) request()->header('X-Active-Company-Id');

        if ($requested && CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $requested)
            ->where('status', 'active')
            ->exists()) {
            return $requested;
        }

        return (int) $user->company_id;
    }

    private function can(): bool
    {
        $user      = $this->user();
        $companyId = $this->companyId();

        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('module_key', self::MODULE)
            ->where('permission_key', self::PERMISSION)
            ->exists();

        PermissionDebug::log($user->id, $companyId, $user->role_type, self::MODULE, self::PERMISSION, $result);

        return $result;
    }

    /**
     * The company this request may act on, or null when it may not act at
     * all. One method for both checks on purpose: every endpoint needs the
     * permission AND the company, and resolving them together means neither
     * can be forgotten.
     */
    private function permittedCompany(): ?Company
    {
        if (!$this->can()) {
            return null;
        }

        return Company::find($this->companyId());
    }

    private function denied(): JsonResponse
    {
        return ApiResponse::error('Permission denied: you do not have Settings Management for this company.', 403);
    }

    // Settings changes are exactly the kind a Company Admin will want to
    // trace back to a person later -- who changed the bank account, who
    // swapped a gateway credential. The Company Admin's own screen needs no
    // equivalent: they are the account owner.
    private function audit(Request $request, int $companyId, string $action, array $after = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $this->user()->id,
            'action'      => $action,
            'module_key'  => 'settings',
            'entity_type' => 'Settings',
            'entity_id'   => $companyId,
            'new_values'  => $after ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // =========================================================================
    // GET /api/user/settings
    // =========================================================================
    public function show(): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        return ApiResponse::success(
            SettingsPayload::forCompany($company) + [
                // Always a single company: a staff member switches workspace
                // through /select-company, which is their equivalent of the
                // admin's topbar company filter. There is no "All Companies"
                // mode on this side, so unlike the admin controller this can
                // never need a company-selection prompt.
                'requires_company_selection' => false,
                'company_id'                 => $company->id,
                // Tells the frontend to render the read-only module list
                // without the "Upgrade Modules" action -- purchasing is the
                // account owner's, not a delegated staff member's.
                'can_purchase_modules' => false,
            ]
        );
    }

    // =========================================================================
    // PUT /api/user/settings/company
    // =========================================================================
    public function updateCompany(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $validated = $request->validate(SettingsPayload::companyRules());
        $company->update(SettingsPayload::companyAttributes($validated));
        $this->audit($request, $company->id, 'settings.company_updated', $validated);

        return ApiResponse::success(null, 'Company profile updated');
    }

    // =========================================================================
    // PUT /api/user/settings/invoice
    // =========================================================================
    public function updateInvoice(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $validated = $request->validate(SettingsPayload::invoiceRules());
        $company->update(SettingsPayload::invoiceAttributes($validated));
        $this->audit($request, $company->id, 'settings.invoice_updated', $validated);

        return ApiResponse::success(null, 'Invoice settings updated');
    }

    // =========================================================================
    // PUT /api/user/settings/bank
    // =========================================================================
    public function updateBank(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $validated = $request->validate(SettingsPayload::bankRules());
        $company->update(SettingsPayload::bankAttributes($validated));
        // Account number/IBAN are logged as "changed", never by value -- an
        // audit trail should not become a second copy of the bank details.
        $this->audit($request, $company->id, 'settings.bank_updated', [
            'fields_changed' => array_keys(array_filter($validated, fn ($v) => $v !== null)),
        ]);

        return ApiResponse::success(null, 'Bank details updated');
    }

    // =========================================================================
    // POST /api/user/settings/logo
    // =========================================================================
    public function uploadLogo(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $request->validate(SettingsPayload::logoRules());

        $path = SettingsPayload::replaceLogo($company, $request->file('logo'));
        $this->audit($request, $company->id, 'settings.logo_updated');

        return ApiResponse::success(['logo_url' => PublicFile::url($path)], 'Logo uploaded');
    }

    // Gateway rows are looked up by id AND company_id, so an id belonging to
    // another company of the same tenant is a 404 here, not an edit.
    private function ownedGateway(Company $company, int $id): ?CompanyPaymentGateway
    {
        return CompanyPaymentGateway::where('company_id', $company->id)
            ->where('id', $id)
            ->first();
    }

    // =========================================================================
    // POST /api/user/settings/gateways
    // =========================================================================
    public function storeGateway(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $validated = $request->validate(SettingsPayload::gatewayStoreRules());
        $row = SettingsPayload::createGateway($company, $validated);
        $this->audit($request, $company->id, 'settings.gateway_added', [
            'gateway' => $validated['gateway_type'], 'label' => $validated['label'],
        ]);

        return ApiResponse::success(['id' => $row->id], $validated['label'] . ' added');
    }

    // =========================================================================
    // PUT /api/user/settings/gateways/{id}
    // =========================================================================
    public function updateGateway(Request $request, int $id): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $row = $this->ownedGateway($company, $id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $validated = $request->validate(SettingsPayload::gatewayUpdateRules());

        $row->update([
            'label'     => $validated['label'],
            'is_active' => $validated['is_active'],
            'config'    => SettingsPayload::mergeConfig($row->config ?? [], $validated['config'] ?? []),
        ]);

        // Credentials are never written into the audit trail -- only the fact
        // that they were touched.
        $this->audit($request, $company->id, 'settings.gateway_updated', [
            'gateway' => $row->gateway, 'label' => $row->label,
            'config_fields_changed' => array_keys($validated['config'] ?? []),
        ]);

        return ApiResponse::success(null, $row->label . ' settings saved');
    }

    // =========================================================================
    // PATCH /api/user/settings/gateways/{id}/toggle
    // =========================================================================
    public function toggleGateway(Request $request, int $id): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $row = $this->ownedGateway($company, $id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $row->update(['is_active' => $validated['is_active']]);
        $this->audit($request, $company->id, 'settings.gateway_toggled', [
            'gateway' => $row->gateway, 'is_active' => $validated['is_active'],
        ]);

        return ApiResponse::success(null, $row->label . ($validated['is_active'] ? ' enabled' : ' disabled'));
    }

    // =========================================================================
    // PATCH /api/user/settings/gateways/{id}/default
    // =========================================================================
    public function setDefaultGateway(Request $request, int $id): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $row = $this->ownedGateway($company, $id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        SettingsPayload::makeDefault($row);
        $this->audit($request, $company->id, 'settings.gateway_default_set', [
            'gateway' => $row->gateway, 'label' => $row->label,
        ]);

        return ApiResponse::success(null, $row->label . ' set as default');
    }

    // =========================================================================
    // DELETE /api/user/settings/gateways/{id}
    // =========================================================================
    public function destroyGateway(Request $request, int $id): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $row = $this->ownedGateway($company, $id);
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

        $label   = $row->label;
        $gateway = $row->gateway;
        $row->delete();
        $this->audit($request, $company->id, 'settings.gateway_deleted', [
            'gateway' => $gateway, 'label' => $label,
        ]);

        return ApiResponse::success(null, 'Gateway account deleted');
    }

    // =========================================================================
    // POST /api/user/settings/gateways/{id}/test
    // =========================================================================
    public function testGateway(int $id): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $row = $this->ownedGateway($company, $id);
        if (!$row) {
            return ApiResponse::error('Gateway account not found', 404);
        }

        $result = SettingsPayload::testGateway($row);

        return $result['success']
            ? ApiResponse::success(null, $result['message'])
            : ApiResponse::error($result['message'], 422);
    }

    // =========================================================================
    // GET /api/user/settings/deal-workflow
    // =========================================================================
    public function dealWorkflowSettings(): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        return ApiResponse::success(SettingsPayload::dealWorkflow($company));
    }

    // =========================================================================
    // PUT /api/user/settings/deal-workflow
    // =========================================================================
    public function updateDealWorkflowSettings(Request $request): JsonResponse
    {
        $company = $this->permittedCompany();
        if (!$company) {
            return $this->denied();
        }

        $validated = $request->validate(SettingsPayload::dealWorkflowRules());

        CompanyDealSettings::updateOrCreate(
            ['company_id' => $company->id],
            $validated + ['company_admin_id' => $company->admin_id]
        );

        $this->audit($request, $company->id, 'settings.deal_workflow_updated', $validated);

        return ApiResponse::success(null, 'Deal workflow settings saved');
    }
}
