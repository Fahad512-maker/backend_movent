<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\User;
use App\Models\UserCompanyPermission;
use Illuminate\Support\Facades\DB;

/**
 * Applies role-default permission changes to EXISTING users.
 *
 * The problem this solves: RoleDefaultPermissions::MAP is only ever read when
 * a user is created/invited/role-changed (see Api\Admin\UserController::
 * withRoleDefaultPermissions()). So the moment a new feature adds a
 * permission key, new users get it and every existing user silently does not
 * — the feature just doesn't appear for them, with no error. Historically
 * that was patched with a hand-written backfill migration per key (~47 of
 * them in database/migrations); this replaces that ritual entirely.
 *
 * Strictly ADDITIVE. It never revokes: dropping a key from MAP does not strip
 * it from existing users, because "this role shouldn't have X anymore" is a
 * deliberate decision that deserves its own reviewed migration, not a silent
 * side effect of a deploy.
 *
 * The permission_rollouts ledger is what makes re-running safe: once a
 * (role, module, permission) triple has been rolled out it is never
 * considered again, so a permission a Company Admin deliberately unchecked
 * for one user is not resurrected on the next deploy.
 */
class PermissionRolloutService
{
    /**
     * @return array{pending: int, granted: int, users_touched: int, details: array<int, string>}
     */
    public static function sync(bool $dryRun = false): array
    {
        $allModules = array_keys(ModuleCatalog::all());

        // roleOptions() is the authoritative list of roles that actually have
        // defaults — including 'company_admin'/'viewer', which forRole()
        // computes dynamically rather than reading from MAP. Legacy role_type
        // values (invoice_admin, payment_manager, …) have no defaults, so
        // forRole() returns nothing for them and they're skipped naturally.
        $roles = array_column(RoleDefaultPermissions::roleOptions(), 'value');

        $rolledOut = DB::table('permission_rollouts')
            ->get(['role_type', 'module_key', 'permission_key'])
            ->map(fn ($r) => "{$r->role_type}|{$r->module_key}|{$r->permission_key}")
            ->flip();

        $details      = [];
        $pendingCount = 0;
        $granted      = 0;
        $usersTouched = [];
        $now          = now();

        foreach ($roles as $role) {
            $defaults = RoleDefaultPermissions::forRole($role, $allModules);

            // Resolved once per role, not once per permission — a role with
            // 60 default keys would otherwise re-run the same user/company
            // lookup 60 times.
            $targets = null;

            foreach ($defaults as $moduleKey => $permKeys) {
                foreach ($permKeys as $permKey) {
                    if ($rolledOut->has("{$role}|{$moduleKey}|{$permKey}")) {
                        continue;
                    }

                    $pendingCount++;
                    $targets ??= self::targetsFor($role);
                    $grantedHere = 0;

                    foreach ($targets as $target) {
                        if (!self::companyHasModule($target['company_id'], $moduleKey)) {
                            continue;
                        }

                        $exists = UserCompanyPermission::where('user_id', $target['user_id'])
                            ->where('company_id', $target['company_id'])
                            ->where('module_key', $moduleKey)
                            ->where('permission_key', $permKey)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        if (!$dryRun) {
                            UserCompanyPermission::create([
                                'company_user_id' => $target['company_user_id'],
                                'user_id'         => $target['user_id'],
                                'company_id'      => $target['company_id'],
                                'module_key'      => $moduleKey,
                                'permission_key'  => $permKey,
                                'data_scope'      => null,
                            ]);
                        }

                        $granted++;
                        $grantedHere++;
                        $usersTouched[$target['user_id']] = true;
                        $details[] = sprintf(
                            '  %s  user #%d (%s) @ company %d  %s.%s',
                            $dryRun ? 'WOULD GRANT' : 'GRANTED    ',
                            $target['user_id'],
                            $target['user_name'],
                            $target['company_id'],
                            $moduleKey,
                            $permKey
                        );
                    }

                    if ($grantedHere === 0) {
                        $details[] = sprintf(
                            '  %s  %s.%s (%s) — nobody needed it',
                            $dryRun ? 'NEW KEY    ' : 'RECORDED   ',
                            $moduleKey,
                            $permKey,
                            $role
                        );
                    }

                    // Recorded even when it granted nothing: the key HAS now
                    // been rolled out, and leaving it unrecorded would make
                    // every future deploy re-evaluate it forever.
                    if (!$dryRun) {
                        DB::table('permission_rollouts')->insert([
                            'role_type'      => $role,
                            'module_key'     => $moduleKey,
                            'permission_key' => $permKey,
                            'rolled_out_at'  => $now,
                        ]);
                    }
                }
            }
        }

        return [
            'pending'       => $pendingCount,
            'granted'       => $granted,
            'users_touched' => count($usersTouched),
            'details'       => $details,
        ];
    }

    /**
     * Every (user, company) pair a role's defaults should reach.
     *
     * Two sources, because neither alone is complete: active
     * company_user_assignments rows are the modern source of truth, but a
     * significant share of users (seeded//legacy accounts) have NO assignment
     * row at all and are reachable only via their users.company_id column —
     * skipping those would silently miss most of the user base.
     *
     * @return array<int, array{user_id:int, user_name:string, company_id:int, company_user_id:?int}>
     */
    private static function targetsFor(string $role): array
    {
        $out = [];

        $assignments = CompanyUserAssignment::where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('role_type', $role)->where('is_active', true))
            ->with('user:id,name')
            ->get(['id', 'user_id', 'company_id']);

        foreach ($assignments as $a) {
            $out["{$a->user_id}-{$a->company_id}"] = [
                'user_id'         => (int) $a->user_id,
                'user_name'       => $a->user?->name ?? 'Unknown',
                'company_id'      => (int) $a->company_id,
                'company_user_id' => (int) $a->id,
            ];
        }

        $orphans = User::where('role_type', $role)
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->whereDoesntHave('companyAssignments')
            ->get(['id', 'name', 'company_id']);

        foreach ($orphans as $u) {
            $out["{$u->id}-{$u->company_id}"] ??= [
                'user_id'         => (int) $u->id,
                'user_name'       => $u->name,
                'company_id'      => (int) $u->company_id,
                'company_user_id' => null,
            ];
        }

        return array_values($out);
    }

    /** @var array<int, array<int, string>> company_id => catalog module keys */
    private static array $moduleCache = [];

    // Mirrors the module gate forRole() itself applies at user-creation time
    // (see Api\Admin\UserController::withRoleDefaultPermissions()): 'account'
    // is never a purchased module and is always available; everything else
    // must be a module the company actually has enabled.
    private static function companyHasModule(int $companyId, string $moduleKey): bool
    {
        if ($moduleKey === 'account') {
            return true;
        }

        self::$moduleCache[$companyId] ??= Company::find($companyId)?->modules()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->map(fn ($key) => ModuleCatalog::dbKeyToCatalog($key))
            ->unique()
            ->values()
            ->toArray() ?? [];

        return in_array($moduleKey, self::$moduleCache[$companyId], true);
    }
}
