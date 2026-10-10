<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\SystemAuditLog;
use App\Services\SuperAdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = Company::with('admin')
            ->withCount('users')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($companies);
    }

    public function toggleStatus(Company $company): JsonResponse
    {
        $company->update(['is_active' => !$company->is_active]);

        // Recorded in the Super Admin's own bell even though a Super Admin is
        // the one doing it: activation and suspension are the platform's
        // audit trail, and the feed is where that history is read back.
        SuperAdminNotificationService::companyStatusChanged($company, $company->is_active);

        return ApiResponse::success(
            ['is_active' => $company->is_active],
            'Status updated'
        );
    }

    // GET /super-admin/companies/{company}/deletion-summary — read-only
    // preview of what a permanent delete would look at, so the Super Admin
    // sees exactly why a company is (or isn't) eligible BEFORE the
    // destructive confirmation step. destroy() below recomputes this itself
    // rather than trusting anything the frontend sends back — a client-side
    // "yes it's eligible" flag would be trivially bypassable.
    public function deletionSummary(Company $company): JsonResponse
    {
        return ApiResponse::success($this->buildDeletionSummary($company));
    }

    // DELETE /super-admin/companies/{company} — Super Admin only (route is
    // under the auth:super_admin guard; no Company Admin/staff route ever
    // reaches this controller). Requires the company's own current name typed
    // back exactly (guards against clicking the wrong row in a list) plus an
    // explicit confirm_irreversible flag — no confirmation, no deletion.
    //
    // Retention policy: this codebase defines no retention-period rule
    // anywhere (grepped for one before writing this), so per instructions
    // this blocks deletion of any company holding real business records
    // (invoices, payments, payroll, employees, leads, clients, projects)
    // rather than inventing a time-based allowance. Only a genuinely empty
    // company — and never one with a payment still pending/processing — is
    // deletable. Never cascades to `users` (a shared tenant-wide account,
    // only ever removed from THIS company via its company_user_assignments
    // row) or to company_payment_gateways (tenant-wide, shared with every
    // other company this Admin owns) — both are explicitly left untouched
    // below, even though the company row itself is being removed.
    public function destroy(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'company_name_confirmation' => ['required', 'string'],
            'confirm_irreversible'      => ['required', 'accepted'],
        ]);

        if ($data['company_name_confirmation'] !== $company->name) {
            return ApiResponse::error('Company name confirmation does not match.', 422);
        }

        $summary = $this->buildDeletionSummary($company);

        if (!$summary['eligible_for_deletion']) {
            return ApiResponse::error(
                'This company cannot be permanently deleted: ' . implode(' ', $summary['blocking_reasons']),
                422,
                ['summary' => $summary]
            );
        }

        DB::transaction(function () use ($company, $request) {
            // Scoped to records exclusively owned by this company — never
            // `users` (belongs to the tenant, possibly assigned to other
            // companies too — unassigned below, never deleted), never
            // company_payment_gateways (tenant-wide), and deliberately NOT
            // system_audit_logs: that FK is nullOnDelete (see its migration),
            // so this company's history — including the entry created below
            // — survives the delete with company_id set to null instead of
            // being wiped along with it. Everything deleted here is already
            // confirmed empty/negligible by buildDeletionSummary()'s
            // eligibility check above; this only removes the handful of
            // non-business rows (documents, chat threads, modules) a
            // genuinely "empty" company can still have picked up.
            $company->modules()->delete();
            $company->documents()->delete();
            $company->chatThreads()->delete();
            $company->notifications()->delete();
            CompanyUserAssignment::where('company_id', $company->id)->delete();
            $company->users()->update(['company_id' => null]);

            // Recorded before the delete so entity_id still resolves to a
            // real row at write time; the FK going null afterwards is what
            // keeps this readable in the platform-wide audit feed rather
            // than disappearing with the company.
            SystemAuditLog::create([
                'company_id'  => $company->id,
                'user_id'     => null,
                'action'      => 'company.permanently_deleted',
                'module_key'  => 'company',
                'entity_type' => Company::class,
                'entity_id'   => $company->id,
                'old_values'  => ['name' => $company->name, 'admin_id' => $company->admin_id],
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
            ]);

            $company->delete();
        });

        return ApiResponse::success(null, 'Company permanently deleted');
    }

    private function buildDeletionSummary(Company $company): array
    {
        $counts = [
            'assigned_users' => $company->users()->count(),
            'clients'        => $company->clients()->count(),
            'leads'          => $company->leads()->count(),
            'projects'       => $company->projects()->count(),
            'invoices'       => $company->invoices()->count(),
            'employees'      => $company->employees()->count(),
            'documents'      => $company->documents()->count(),
        ];

        $pendingPayments = Payment::whereHas('invoice', fn ($q) => $q->where('company_id', $company->id))
            ->whereIn('status', ['pending'])
            ->count();

        $payrollRecords = Payroll::whereIn('employee_id', $company->employees()->pluck('id'))->count();

        $blockingReasons = [];

        if ($counts['invoices'] > 0)  $blockingReasons[] = 'It has invoice records.';
        if ($payrollRecords > 0)      $blockingReasons[] = 'It has payroll records.';
        if ($counts['employees'] > 0) $blockingReasons[] = 'It has employee records.';
        if ($counts['clients'] > 0)   $blockingReasons[] = 'It has client records.';
        if ($counts['leads'] > 0)     $blockingReasons[] = 'It has lead records.';
        if ($counts['projects'] > 0)  $blockingReasons[] = 'It has project records.';
        if ($pendingPayments > 0)     $blockingReasons[] = 'It has a payment still pending/processing.';

        return [
            'company_id'             => $company->id,
            'company_name'           => $company->name,
            'counts'                 => $counts + ['payroll_records' => $payrollRecords, 'pending_payments' => $pendingPayments],
            'eligible_for_deletion'  => empty($blockingReasons),
            'blocking_reasons'       => $blockingReasons,
        ];
    }

    public function modules(Company $company): JsonResponse
    {
        $company->load('modules');
        return ApiResponse::success([
            'company_id' => $company->id,
            'modules'    => $company->modules->pluck('module_key')->toArray(),
        ]);
    }

    public function syncModules(Company $company, Request $request): JsonResponse
    {
        $request->validate([
            'modules'   => ['required', 'array'],
            'modules.*' => ['string'],
        ]);

        // Top-level catalog keys (e.g. 'hr') this company fully owned BEFORE
        // the sync, so we can tell which ones are newly gained after it —
        // same "every sub_module present" rule ModulePurchaseController::
        // catalog() already uses to decide what counts as "owned."
        $beforeGranular = $company->modules()->where('is_enabled', true)->pluck('module_key')->toArray();
        $catalogModules = \App\Models\Module::where('is_active', true)->get(['key', 'sub_modules']);
        $ownedBefore = $catalogModules
            ->filter(fn ($m) => !empty($m->sub_modules) && empty(array_diff($m->sub_modules, $beforeGranular)))
            ->pluck('key')->all();

        $company->modules()->delete();

        foreach ($request->modules as $key) {
            $company->modules()->create(['module_key' => $key, 'is_enabled' => true]);
        }

        $ownedAfter = $catalogModules
            ->filter(fn ($m) => !empty($m->sub_modules) && empty(array_diff($m->sub_modules, $request->modules)))
            ->pluck('key')->all();

        // Existing staff never get a newly-granted module's permissions just
        // by it becoming available — only new hires do (see
        // Api\Admin\UserController's own default-grant path). Additive
        // only — see PermissionBackfillService.
        \App\Services\PermissionBackfillService::grantDefaultsForNewModules(
            $company,
            array_values(array_diff($ownedAfter, $ownedBefore))
        );

        return ApiResponse::success([
            'company_id' => $company->id,
            'modules'    => $request->modules,
        ], 'Modules updated');
    }
}
