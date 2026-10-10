<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCompanyRole;
use App\Services\UserCompanyRoleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The database half of multi-role: User::rolesIn()/hasRoleIn()/holdingRole()
 * and UserCompanyRoleService::sync().
 *
 * Two properties are load-bearing and are what this file really guards:
 *
 *  1. COMPANY INDEPENDENCE. A user's roles in one company must be invisible to
 *     every other company, and editing one company's set must leave the others
 *     untouched. Get this wrong and a Seller in one tenant silently becomes a
 *     Seller in another.
 *
 *  2. THE LEGACY FALLBACK IS A FALLBACK. holdingRole() may read users.role_type
 *     only for a user with NO role rows in the company being asked about. That
 *     column is account-wide, so consulting it for a user whose roles ARE
 *     recorded would leak their primary company's role everywhere.
 *
 * DatabaseTransactions, not RefreshDatabase: this project has 281 migrations
 * and re-running them per class takes minutes. The schema is migrated once (see
 * phpunit.xml) and every test rolls back.
 */
class MultiRolePerCompanyTest extends TestCase
{
    use DatabaseTransactions;

    /** Two companies under one admin, so cross-company behaviour is testable. */
    private array $companyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Test Admin',
            'email' => 'admin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['Company A', 'Company B'] as $name) {
            $this->companyIds[] = DB::table('companies')->insertGetId([
                'admin_id' => $adminId, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeUser(string $roleType = 'seller'): User
    {
        return User::create([
            'company_id' => $this->companyIds[0],
            'name'       => 'Staff ' . uniqid(),
            'email'      => 'staff+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => $roleType,
            'is_active'  => true,
        ]);
    }

    private function fresh(User $user): User
    {
        return User::with('companyRoles')->findOrFail($user->id);
    }

    // ── Holding several roles at once ────────────────────────────────────────

    public function test_a_user_can_hold_several_roles_in_one_company(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('project_manager');

        UserCompanyRoleService::sync($user, $companyA, ['project_manager', 'seller']);

        $this->assertSame(['project_manager', 'seller'], $this->fresh($user)->rolesIn($companyA));
    }

    public function test_has_role_in_is_true_for_every_held_role_and_false_otherwise(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('project_manager');
        UserCompanyRoleService::sync($user, $companyA, ['project_manager', 'seller']);
        $user = $this->fresh($user);

        $this->assertTrue($user->hasRoleIn($companyA, 'seller'), 'A secondary role must still count as held.');
        $this->assertTrue($user->hasRoleIn($companyA, 'project_manager'));
        $this->assertTrue($user->hasRoleIn($companyA, ['hr', 'seller']), 'Any-of match should succeed.');
        $this->assertFalse($user->hasRoleIn($companyA, 'hr'));
    }

    /** The first entry is the primary — it is what users.role_type mirrors. */
    public function test_the_first_role_synced_becomes_the_primary(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser();

        UserCompanyRoleService::sync($user, $companyA, ['seller', 'project_manager']);
        $this->assertSame('seller', $this->fresh($user)->primaryRoleIn($companyA));

        UserCompanyRoleService::sync($user, $companyA, ['project_manager', 'seller']);
        $this->assertSame('project_manager', $this->fresh($user)->primaryRoleIn($companyA));

        $this->assertSame(
            1,
            UserCompanyRole::where('user_id', $user->id)->where('company_id', $companyA)->where('is_primary', true)->count(),
            'Exactly one row per company may be primary.'
        );
    }

    // ── Company independence ─────────────────────────────────────────────────

    public function test_roles_in_one_company_are_invisible_to_another(): void
    {
        [$companyA, $companyB] = $this->companyIds;
        $user = $this->makeUser();

        UserCompanyRoleService::sync($user, $companyA, ['seller']);
        $user = $this->fresh($user);

        $this->assertSame(['seller'], $user->rolesIn($companyA));
        $this->assertSame([], $user->rolesIn($companyB), 'Company B was never given a role set.');
        $this->assertFalse($user->hasRoleIn($companyB, 'seller'));
    }

    public function test_editing_one_companys_roles_leaves_the_other_untouched(): void
    {
        [$companyA, $companyB] = $this->companyIds;
        $user = $this->makeUser();

        UserCompanyRoleService::sync($user, $companyA, ['seller']);
        UserCompanyRoleService::sync($user, $companyB, ['project_manager', 'qa']);

        // Change A completely — B must not move.
        UserCompanyRoleService::sync($user, $companyA, ['hr']);
        $user = $this->fresh($user);

        $this->assertSame(['hr'], $user->rolesIn($companyA));
        $this->assertSame(['project_manager', 'qa'], $user->rolesIn($companyB));
    }

    // ── sync() semantics ─────────────────────────────────────────────────────

    /**
     * An empty list is "no change", never "remove everything". Every caller
     * that predates roles omits the field, and wiping on those would strip the
     * roster bare on the first unrelated profile edit.
     */
    public function test_syncing_an_empty_list_leaves_existing_roles_alone(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser();
        UserCompanyRoleService::sync($user, $companyA, ['seller', 'qa']);

        UserCompanyRoleService::sync($user, $companyA, []);

        $this->assertSame(['seller', 'qa'], $this->fresh($user)->rolesIn($companyA));
    }

    public function test_sync_replaces_rather_than_appends(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser();

        UserCompanyRoleService::sync($user, $companyA, ['seller', 'qa']);
        UserCompanyRoleService::sync($user, $companyA, ['hr']);

        $this->assertSame(['hr'], $this->fresh($user)->rolesIn($companyA));
        $this->assertSame(
            1,
            UserCompanyRole::where('user_id', $user->id)->where('company_id', $companyA)->count(),
            'Replaced roles must not linger as extra rows.'
        );
    }

    public function test_sync_normalises_duplicates_and_blanks(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser();

        UserCompanyRoleService::sync($user, $companyA, ['seller', '', 'seller', '  qa  ']);

        $this->assertSame(['seller', 'qa'], $this->fresh($user)->rolesIn($companyA));
    }

    // ── holdingRole() — the query scope every picker now uses ────────────────

    public function test_holding_role_finds_a_user_whose_seller_role_is_secondary(): void
    {
        [$companyA] = $this->companyIds;
        // Primary is PM, so the old ->where('role_type','seller') missed them.
        $user = $this->makeUser('project_manager');
        UserCompanyRoleService::sync($user, $companyA, ['project_manager', 'seller']);

        $this->assertTrue(
            User::whereKey($user->id)->holdingRole($companyA, 'seller')->exists(),
            'A Seller held as a second role must be found by the Seller pickers.'
        );
    }

    public function test_holding_role_does_not_leak_a_role_across_companies(): void
    {
        [$companyA, $companyB] = $this->companyIds;
        $user = $this->makeUser('seller');

        UserCompanyRoleService::sync($user, $companyA, ['seller']);
        UserCompanyRoleService::sync($user, $companyB, ['hr']);

        $this->assertTrue(User::whereKey($user->id)->holdingRole($companyA, 'seller')->exists());
        $this->assertFalse(
            User::whereKey($user->id)->holdingRole($companyB, 'seller')->exists(),
            'role_type says "seller" account-wide, but company B recorded HR only — it must win.'
        );
    }

    /** A pre-multi-role account (no role rows anywhere) still resolves by role_type. */
    public function test_holding_role_falls_back_to_role_type_when_no_rows_exist(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('seller');
        UserCompanyRole::where('user_id', $user->id)->delete();

        $this->assertTrue(
            User::whereKey($user->id)->holdingRole($companyA, 'seller')->exists(),
            'Legacy accounts must not vanish from every picker.'
        );
    }

    public function test_holding_role_accepts_a_list_of_roles(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('hr');
        UserCompanyRoleService::sync($user, $companyA, ['hr', 'qa']);

        $this->assertTrue(User::whereKey($user->id)->holdingRole($companyA, ['designer', 'qa'])->exists());
        $this->assertFalse(User::whereKey($user->id)->holdingRole($companyA, ['designer', 'developer'])->exists());
    }

    /**
     * rolesIn() deliberately does NOT fall back to role_type — unlike
     * holdingRole(), which needs the fallback for legacy accounts. Consulting
     * an account-wide column here would report a company-specific answer the
     * data never recorded.
     */
    public function test_roles_in_never_falls_back_to_the_legacy_column(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('seller');
        UserCompanyRole::where('user_id', $user->id)->delete();

        $this->assertSame([], $this->fresh($user)->rolesIn($companyA));
    }

    // ── Project-team eligibility (both assignTeam() guards) ──────────────────

    /**
     * The whitelist both assignTeam() endpoints check a candidate against.
     *
     * A copy, and therefore a liability: keep it in step with $eligibleRoles in
     * Api\Admin\ProjectController::assignTeam() and Api\User\ProjectController::
     * assignTeam(). 'seller' joined the list on 2026-09-15.
     */
    private const TEAM_ELIGIBLE = ['project_manager', 'production', 'developer', 'designer', 'qa', 'team_member', 'seller'];

    private function isTeamEligible(User $user, int $companyId): bool
    {
        // Mirrors the guard in Api\Admin\ProjectController::assignTeam() and
        // Api\User\ProjectController::assignTeam() exactly.
        return !User::whereKey($user->id)
            ->whereNot(fn ($q) => $q->holdingRole($companyId, self::TEAM_ELIGIBLE))
            ->exists();
    }

    /**
     * The bug this fixed: a QA who holds the role as a SECOND role was refused
     * by assignTeam() and never offered by the picker, because role_type only
     * ever carries their primary.
     */
    public function test_a_user_whose_eligible_role_is_secondary_can_join_a_team(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('hr'); // primary is NOT team-eligible
        UserCompanyRoleService::sync($user, $companyA, ['hr', 'qa']);

        $this->assertTrue($this->isTeamEligible($user, $companyA));
    }

    /** A user with no team-eligible role at all is still refused — no gate was widened. */
    public function test_a_user_with_no_eligible_role_is_still_refused(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('hr');
        UserCompanyRoleService::sync($user, $companyA, ['hr', 'finance']);

        $this->assertFalse($this->isTeamEligible($user, $companyA));
    }

    /**
     * A Seller may sit on the project team (changed 2026-09-15 on request).
     *
     * The thing that made this safe is in ProjectChatController::
     * projectForChat(): it now skips the internal-member branch for a Seller
     * holding client access, so team membership no longer downgrades them to an
     * internal 'worker' and costs them the client-facing slice the Upseller
     * flow depends on. If that resolver is ever re-ordered, this passing test
     * will NOT catch it — the regression is in the chat resolver, not here.
     */
    public function test_a_seller_is_team_eligible(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('seller');
        UserCompanyRoleService::sync($user, $companyA, ['seller']);

        $this->assertTrue($this->isTeamEligible($user, $companyA));
    }

    /** A Seller held as a SECOND role counts too — that was the original complaint. */
    public function test_a_secondary_seller_role_is_team_eligible(): void
    {
        [$companyA] = $this->companyIds;
        $user = $this->makeUser('hr');
        UserCompanyRoleService::sync($user, $companyA, ['hr', 'seller']);

        $this->assertTrue($this->isTeamEligible($user, $companyA));
    }

    /** Eligibility is per company, like everything else about roles. */
    public function test_team_eligibility_is_scoped_to_the_company_asked_about(): void
    {
        [$companyA, $companyB] = $this->companyIds;
        $user = $this->makeUser('hr');

        UserCompanyRoleService::sync($user, $companyA, ['qa']);
        UserCompanyRoleService::sync($user, $companyB, ['hr']);

        $this->assertTrue($this->isTeamEligible($user, $companyA));
        $this->assertFalse(
            $this->isTeamEligible($user, $companyB),
            'A QA in company A must not be team-eligible in company B.'
        );
    }
}
