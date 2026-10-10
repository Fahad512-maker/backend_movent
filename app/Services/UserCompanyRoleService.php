<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCompanyRole;

/**
 * Writes the roles a user holds in one company.
 *
 * Both add/edit-user paths (Api\Admin\UserController and
 * Api\User\UserManagementController) go through here so the two can never drift
 * on what "assigned roles" means — they already drifted once on permissions.
 */
class UserCompanyRoleService
{
    /**
     * Replace this user's roles IN THIS COMPANY with $roles.
     *
     * Scoped to one company on purpose: a user active in several companies has
     * an independent role set in each, and syncing one must not disturb another.
     *
     * The first entry becomes the primary role — what users.role_type mirrors
     * and what the roster's "Role" column shows. Order is therefore meaningful
     * on the way in, even though it means nothing to the permission union.
     *
     * An EMPTY $roles is a no-op, not a wipe. Every existing caller that never
     * heard of roles omits the field entirely, and treating that as "remove all
     * their roles" would strip the roster bare on the first unrelated profile
     * edit. Clearing roles is done by deleting the company assignment itself.
     *
     * @param  array<int, string> $roles
     * @return array<int, string> the roles actually written, normalised
     */
    public static function sync(User $user, int $companyId, array $roles): array
    {
        $roles = self::normalise($roles);

        if ($roles === []) {
            return [];
        }

        // Replaced wholesale rather than diffed: the set is tiny, and a rewrite
        // is the only way re-ordering the primary role reliably takes effect.
        UserCompanyRole::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->delete();

        foreach ($roles as $i => $role) {
            UserCompanyRole::create([
                'user_id'    => $user->id,
                'company_id' => $companyId,
                'role'       => $role,
                'is_primary' => $i === 0,
            ]);
        }

        // The relation may already be loaded from before this write (the
        // controllers call rolesIn() straight after), so drop the stale copy.
        $user->unsetRelation('companyRoles');

        return $roles;
    }

    /**
     * Resolve the roles for one company assignment out of a request payload,
     * newest shape first.
     *
     * Three shapes are accepted, because three callers exist and only the
     * wizard has been updated:
     *   - per-assignment `roles: []`  — the multi-role Add/Edit User wizard
     *   - top-level `roles: []`       — same roles applied to every company
     *   - legacy `role_type: 'seller'`— every other caller, unchanged
     *
     * @param  array<string, mixed> $assignment
     * @return array<int, string>
     */
    public static function resolveFromPayload(array $assignment, ?array $topLevelRoles, ?string $legacyRoleType): array
    {
        $roles = self::normalise((array) ($assignment['roles'] ?? []));

        if ($roles === []) {
            $roles = self::normalise((array) ($topLevelRoles ?? []));
        }

        if ($roles === [] && $legacyRoleType) {
            $roles = [$legacyRoleType];
        }

        return $roles;
    }

    /**
     * Trim, drop blanks, de-duplicate, keep the caller's order (so entry 0 is
     * still the primary role).
     *
     * @param  array<int, mixed> $roles
     * @return array<int, string>
     */
    private static function normalise(array $roles): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($r) => is_string($r) ? trim($r) : '', $roles),
            fn (string $r) => $r !== ''
        )));
    }
}
