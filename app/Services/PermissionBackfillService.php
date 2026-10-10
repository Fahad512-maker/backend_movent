<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\User;
use App\Models\UserCompanyPermission;

// Grants each EXISTING staff member the role-default permissions for
// module(s) their company just gained — additive only, never touches a
// module the user already has ANY permission row for (whether from a prior
// grant or the Company Admin's own deliberate edit, including one that left
// every box unchecked). New staff created AFTER a module is already active
// get this for free (Api\Admin\UserController::withRoleDefaultPermissions()
// / saveAssignments()); this is the missing counterpart for staff who
// existed BEFORE the module was enabled — without it, a company that buys
// e.g. HR after its team is already set up sees the module appear with
// every permission unchecked for everyone already there, with no way to
// tell that apart from a company that genuinely has zero HR access.
//
// Call this from every place a Company's company_modules rows go from
// disabled/absent to enabled: Api\Admin\ModulePurchaseController::purchase(),
// Api\SuperAdmin\CompanyController::syncModules(), and
// Api\Admin\SubscriptionPaymentController wherever a package swap adds
// modules a company didn't have before.
class PermissionBackfillService
{
    /**
     * @param  array<int, string>  $catalogModuleKeys  Top-level catalog keys
     *         just enabled for $company (e.g. ['hr']) — NOT the granular
     *         company_modules keys (e.g. 'employees', 'payroll').
     */
    public static function grantDefaultsForNewModules(Company $company, array $catalogModuleKeys): void
    {
        $catalogModuleKeys = array_values(array_unique(array_filter($catalogModuleKeys)));
        if (empty($catalogModuleKeys)) {
            return;
        }

        // Every staff member this company actually has: its own
        // users.company_id rows, plus anyone separately assigned here as a
        // secondary company — the same union the rest of this codebase
        // already treats as "belongs to this company."
        $userIds = User::where('company_id', $company->id)->pluck('id')
            ->merge(
                CompanyUserAssignment::where('company_id', $company->id)
                    ->where('status', 'active')
                    ->pluck('user_id')
            )
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $userIds)->get(['id']);
        $assignmentIds = CompanyUserAssignment::where('company_id', $company->id)
            ->whereIn('user_id', $userIds)
            ->pluck('id', 'user_id');

        foreach ($users as $user) {
            // Company-scoped roles only — role_type is not consulted here for
            // the same reason User::rolesIn() itself never falls back to it:
            // it isn't company-scoped, so it could hand a user their role
            // from a DIFFERENT company. No rows means nothing to backfill for
            // this company, same as it means no permissions today either.
            $roles = $user->rolesIn($company->id);
            if (empty($roles)) {
                continue;
            }

            $defaults = RoleDefaultPermissions::forRoles($roles, $catalogModuleKeys);
            if (empty($defaults)) {
                continue;
            }

            $assignmentId = $assignmentIds[$user->id] ?? null;

            foreach ($defaults as $moduleKey => $permKeys) {
                $hasAnyForModule = $assignmentId
                    ? UserCompanyPermission::where('company_user_id', $assignmentId)->where('module_key', $moduleKey)->exists()
                    : UserCompanyPermission::where('user_id', $user->id)->where('company_id', $company->id)->where('module_key', $moduleKey)->exists();

                // Already has rows for this module — never overwrite a prior
                // grant or a deliberate edit, even one that unchecked every box.
                if ($hasAnyForModule) {
                    continue;
                }

                foreach ((array) $permKeys as $permKey) {
                    if (!ModuleCatalog::isValidPermission($moduleKey, $permKey)) {
                        continue;
                    }
                    UserCompanyPermission::create([
                        'company_user_id' => $assignmentId,
                        'user_id'         => $user->id,
                        'company_id'      => $company->id,
                        'module_key'      => $moduleKey,
                        'permission_key'  => $permKey,
                    ]);
                }
            }
        }
    }
}
