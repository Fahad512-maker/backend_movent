<?php

namespace Tests\Unit;

use App\Services\RoleDefaultPermissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for App\Services\RoleDefaultPermissions::forRoles() — the union that
 * turns "this user holds several roles" into one permission set.
 *
 * The contract under test is the one rule the multi-role feature rests on:
 * holding a second role can only ever ADD permissions. If a union ever came
 * back missing something one of its roles grants alone, a Company Admin would
 * silently take access away from a user just by giving them another job.
 *
 * Pure PHPUnit (no Laravel boot, no database) — forRole()/forRoles() read only
 * the hardcoded MAP and ModuleCatalog, both of which are plain arrays.
 */
class RoleDefaultPermissionsUnionTest extends TestCase
{
    /** Every module the pairings below touch, so nothing is filtered out for being unpurchased. */
    private const MODULES = ['sales', 'project_management', 'invoice', 'client', 'account'];

    /**
     * The heart of it: the union must be a SUPERSET of each role taken alone.
     *
     * Run across several real pairings rather than one, because the three
     * branches of forRole() behave differently — company_admin/admin compute
     * "everything", viewer computes "every canView*", and the rest read MAP.
     */
    #[DataProvider('rolePairs')]
    public function test_union_contains_everything_each_role_grants_alone(string $a, string $b): void
    {
        $union = RoleDefaultPermissions::forRoles([$a, $b], self::MODULES);

        foreach ([$a, $b] as $role) {
            foreach (RoleDefaultPermissions::forRole($role, self::MODULES) as $moduleKey => $permKeys) {
                $missing = array_diff($permKeys, $union[$moduleKey] ?? []);

                $this->assertSame(
                    [],
                    array_values($missing),
                    "Union of [{$a}, {$b}] dropped " . implode(', ', $missing) . " from module '{$moduleKey}', "
                    . "which '{$role}' grants on its own — a second role must never remove a permission."
                );
            }
        }
    }

    public static function rolePairs(): array
    {
        return [
            'seller + project manager' => ['seller', 'project_manager'],
            'seller + lead manager'    => ['seller', 'lead_manager'],
            'pm + qa'                  => ['project_manager', 'qa'],
            'viewer + seller'          => ['viewer', 'seller'],
            'hr + finance'             => ['hr', 'finance'],
            'designer + developer'     => ['designer', 'developer'],
        ];
    }

    /**
     * A Seller has Sales permissions a Project Manager has none of, and vice
     * versa for Projects. The union must genuinely carry both sides — the test
     * above would still pass if forRoles() returned one role's set unchanged
     * and that role happened to be the larger one.
     */
    public function test_union_is_strictly_larger_than_either_role_alone(): void
    {
        $seller = RoleDefaultPermissions::forRole('seller', self::MODULES);
        $pm     = RoleDefaultPermissions::forRole('project_manager', self::MODULES);
        $union  = RoleDefaultPermissions::forRoles(['seller', 'project_manager'], self::MODULES);

        $count = fn (array $set) => array_sum(array_map('count', $set));

        $this->assertGreaterThan($count($seller), $count($union), 'Union should exceed Seller alone.');
        $this->assertGreaterThan($count($pm), $count($union), 'Union should exceed Project Manager alone.');

        // Seller's Sales grants survive even though PM brings none.
        $this->assertNotEmpty($seller['sales'] ?? [], 'Fixture assumption: Seller grants Sales permissions.');
        $this->assertSame(
            [],
            array_values(array_diff($seller['sales'], $union['sales'] ?? [])),
            "Seller's Sales permissions were lost when combined with a role that has none."
        );
    }

    /**
     * Order must not matter — a union is a set, not a sequence.
     *
     * Compared as sets: flipping the roles legitimately changes the ORDER keys
     * land in (seller's keys merge first either way), and that ordering is not
     * part of the contract. Only membership is.
     */
    public function test_order_of_roles_does_not_change_the_result(): void
    {
        $sorted = function (array $set): array {
            ksort($set);

            return array_map(function (array $keys) {
                sort($keys);

                return $keys;
            }, $set);
        };

        $this->assertSame(
            $sorted(RoleDefaultPermissions::forRoles(['seller', 'project_manager'], self::MODULES)),
            $sorted(RoleDefaultPermissions::forRoles(['project_manager', 'seller'], self::MODULES))
        );
    }

    /** The same role twice is the same role once. */
    public function test_duplicate_roles_do_not_duplicate_permissions(): void
    {
        $once  = RoleDefaultPermissions::forRoles(['seller'], self::MODULES);
        $twice = RoleDefaultPermissions::forRoles(['seller', 'seller'], self::MODULES);

        $this->assertSame($once, $twice);

        foreach ($twice as $moduleKey => $permKeys) {
            $this->assertSame(
                array_values(array_unique($permKeys)),
                array_values($permKeys),
                "Module '{$moduleKey}' came back with duplicate permission keys."
            );
        }
    }

    /** A single role through forRoles() must equal forRole() exactly. */
    public function test_single_role_matches_the_legacy_single_role_call(): void
    {
        foreach (['seller', 'project_manager', 'viewer', 'company_admin', 'hr'] as $role) {
            $this->assertSame(
                RoleDefaultPermissions::forRole($role, self::MODULES),
                RoleDefaultPermissions::forRoles([$role], self::MODULES),
                "forRoles(['{$role}']) diverged from forRole('{$role}')."
            );
        }
    }

    /** Junk in the list is ignored, not fatal — it must not poison a valid role beside it. */
    public function test_unknown_and_empty_roles_are_ignored(): void
    {
        $this->assertSame([], RoleDefaultPermissions::forRoles([], self::MODULES));
        $this->assertSame([], RoleDefaultPermissions::forRoles(['not_a_real_role'], self::MODULES));

        $this->assertSame(
            RoleDefaultPermissions::forRoles(['seller'], self::MODULES),
            RoleDefaultPermissions::forRoles(['seller', 'not_a_real_role', ''], self::MODULES)
        );
    }

    /**
     * Modules the company has not purchased stay out, however many roles are
     * combined — the union must not become a back door around module scoping.
     */
    public function test_union_never_grants_a_module_the_company_does_not_have(): void
    {
        $union = RoleDefaultPermissions::forRoles(
            ['seller', 'project_manager', 'lead_manager', 'company_admin'],
            ['sales'] // Projects/Invoice deliberately not purchased.
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($union), ['sales', 'account'])),
            'Union leaked a module outside the purchased list (account is always available).'
        );
    }

    /**
     * canManageSettings is never a default for ANY role
     * (RoleDefaultPermissions::ALWAYS_EXPLICIT_PERMISSIONS). Combining roles
     * must not resurrect it — forRoles() skips re-filtering on the grounds that
     * a union of already-filtered sets is filtered, and this is what proves it.
     */
    public function test_always_explicit_permissions_stay_out_of_a_union(): void
    {
        $union = RoleDefaultPermissions::forRoles(
            ['company_admin', 'admin', 'viewer', 'seller', 'project_manager'],
            self::MODULES
        );

        $this->assertNotContains(
            'canManageSettings',
            $union['account'] ?? [],
            'canManageSettings must never be granted by default, not even to a union including company_admin.'
        );
    }
}
