<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\CompanyUserAssignment;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Services\SuperAdminNotificationService;
use App\Support\CrossAccountEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = CompanyAdmin::with('package')
            ->withCount('companies')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($admins);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => [
                'required', 'email', 'unique:company_admins,email',
                function ($attribute, $value, $fail) {
                    if (CrossAccountEmail::existsAsUser($value)) {
                        $fail('This email is already registered as a staff or client account.');
                    }
                },
            ],
            'password'              => ['required', 'string', 'min:8'],
            'phone'                 => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'package_id'            => ['nullable', 'exists:packages,id'],
            'subscription_status'   => ['required', 'in:trial,trial_expired,active,pending_payment,grace_period,suspended,cancelled'],
            'trial_ends_at'         => ['nullable', 'date'],
            'subscription_ends_at'  => ['nullable', 'date'],
        ]);

        $admin = CompanyAdmin::create($validated + ['is_active' => true]);

        SuperAdminNotificationService::companyAdminCreated($admin);

        return ApiResponse::success($admin->load('package'), 'Company admin created', 201);
    }

    public function show(CompanyAdmin $admin): JsonResponse
    {
        $admin->load(['package.billingTerm', 'companies']);

        $catalog = \App\Models\Module::where('is_active', true)->pluck('label', 'key');

        $modulesByCompany = $admin->companies->map(fn ($company) => [
            'company_id'   => $company->id,
            'company_name' => $company->name,
            // enabled = usable right now; purchased = selected at some point,
            // kept true through a suspension since disableAllModules() only
            // ever flips is_enabled off, it never deletes the company_modules
            // rows (see Company::catalogKeys()).
            'modules'      => collect($company->catalogKeys(onlyEnabled: false))
                ->map(fn ($key) => [
                    'key'       => $key,
                    'label'     => $catalog[$key] ?? $key,
                    'purchased' => true,
                    'enabled'   => in_array($key, $company->catalogKeys(), true),
                ])->values(),
        ]);

        $payments = $admin->subscriptionPayments()
            ->with('package:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($payment) => [
                'id'           => $payment->id,
                'package_name' => $payment->package->name ?? null,
                'amount'       => $payment->amount,
                'currency'     => $payment->currency,
                'gateway'      => $payment->gateway,
                'status'       => $payment->status,
                'period_start' => $payment->period_start,
                'period_end'   => $payment->period_end,
                'type'         => $payment->meta['type'] ?? 'subscription',
                'created_at'   => $payment->created_at,
            ]);

        $data = $admin->toArray();
        $data['modules_by_company'] = $modulesByCompany;
        $data['payments'] = $payments;

        return ApiResponse::success($data);
    }

    public function update(Request $request, CompanyAdmin $admin): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => [
                'required', 'email', 'unique:company_admins,email,' . $admin->id,
                function ($attribute, $value, $fail) {
                    if (CrossAccountEmail::existsAsUser($value)) {
                        $fail('This email is already registered as a staff or client account.');
                    }
                },
            ],
            'password'              => ['nullable', 'string', 'min:8'],
            'phone'                 => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'package_id'            => ['nullable', 'exists:packages,id'],
            'subscription_status'   => ['required', 'in:trial,trial_expired,active,pending_payment,grace_period,suspended,cancelled'],
            'trial_ends_at'         => ['nullable', 'date'],
            'subscription_ends_at'  => ['nullable', 'date'],
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        // Manual Super Admin override of subscription_status — keep the
        // suspension-tracking columns consistent with whatever
        // CheckSubscriptionExpiry would have set, so a manually-suspended
        // admin displays/behaves identically to an auto-suspended one, and
        // reactivating (back to trial/active) always clears the trail.
        $wasBlocked = $admin->isBlockedFromAccess();
        $blockedStatuses = ['trial_expired', 'suspended', 'cancelled'];
        $willBeBlocked = in_array($validated['subscription_status'], $blockedStatuses, true);
        if ($willBeBlocked && !$wasBlocked) {
            $validated['suspended_at'] = now();
            $validated['suspended_reason'] = 'Manually set by Super Admin';
        } elseif (!$willBeBlocked && $validated['subscription_status'] !== 'grace_period') {
            $validated['suspended_at'] = null;
            $validated['suspended_reason'] = null;
            $validated['grace_period_ends_at'] = null;
        }

        $admin->update($validated);

        // Same disable/enable symmetry as the automatic lifecycle command
        // (CheckSubscriptionLifecycle) — a manual override must behave
        // identically, not just change the status label.
        if ($willBeBlocked && !$wasBlocked) {
            $admin->disableAllModules();
        } elseif (!$willBeBlocked && $wasBlocked) {
            $admin->enableExistingModules();
        }

        return ApiResponse::success($admin->load('package'), 'Admin updated');
    }

    // is_active is the approval switch for a tenant owner's account — worth a
    // line in the platform feed either way it moves.
    public function toggleStatus(CompanyAdmin $admin): JsonResponse
    {
        $wasActive = $admin->is_active;
        $admin->update(['is_active' => !$admin->is_active]);

        // DEACTIVATING ENDS THE SESSIONS, IT DOESN'T JUST BLOCK THE NEXT LOGIN.
        //
        // Anyone signed in right now holds a Sanctum token that would
        // otherwise keep working until they chose to log out — so the switch
        // would have looked like it had done nothing to the very people it
        // was aimed at. Revoking here makes their next request a 401, which
        // the client turns into a logout.
        //
        // THE WHOLE TENANT, NOT JUST ITS OWNER. Deactivation takes down the
        // Company Admin and every staff member of every company they own;
        // leaving the staff signed in would mean a switched-off tenant whose
        // work carries on regardless, which is precisely what the switch is
        // for. Scoped through companies.admin_id — this admin's own companies
        // only — so no other tenant's users are touched.
        //
        // Http\Middleware\EnsureTenantActive refuses both guards on the
        // authenticated side regardless, so this is not the only thing
        // standing in the way — it is what makes "immediately" true rather
        // than "once their token happens to be used against a route that
        // checks".
        //
        // Reactivation deliberately does NOT restore anything: everyone logs
        // in again and gets a fresh token.
        if (!$admin->is_active) {
            $admin->tokens()->delete();

            // ofCompany()-style reach rather than users.company_id: a staff
            // member added to a second company only ever gets a
            // company_user_assignments row, their users.company_id stays
            // pinned to whoever created the account — so matching on that
            // column alone would miss exactly the people this is for.
            $companyIds = Company::where('admin_id', $admin->id)->pluck('id');
            $userIds = CompanyUserAssignment::whereIn('company_id', $companyIds)
                ->pluck('user_id')
                ->merge(User::whereIn('company_id', $companyIds)->pluck('id'))
                ->unique();

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();
        }

        SuperAdminNotificationService::companyAdminStatusChanged($admin, $admin->is_active);

        return ApiResponse::success(['is_active' => $admin->is_active]);
    }

    // access_override is a SEPARATE switch from subscription_status — Super
    // Admin restoring access to a trial-expired/suspended/cancelled tenant
    // without that tenant actually having paid. subscription_status (and
    // therefore billing/reporting) is left exactly as it is; only
    // CompanyAdmin::isBlockedFromAccess() changes its answer. Reversing this
    // (turning the override back off) simply lets the real subscription
    // state block access again, same as it would have all along.
    //
    // Only reachable via this controller, which sits entirely behind
    // auth:super_admin (routes/api.php) — no Company Admin or staff guard
    // can ever hit this endpoint, by construction rather than by a
    // permission check.
    public function toggleAccessOverride(CompanyAdmin $admin): JsonResponse
    {
        $admin->update(['access_override' => !$admin->access_override]);

        SuperAdminNotificationService::accessOverrideChanged($admin, $admin->access_override);

        return ApiResponse::success([
            'access_override'              => $admin->access_override,
            'effective_subscription_status' => $admin->effective_subscription_status,
            'is_blocked_from_access'        => $admin->is_blocked_from_access,
        ]);
    }
}
