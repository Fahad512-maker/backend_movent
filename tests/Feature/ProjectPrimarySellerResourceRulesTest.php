<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\User\ProjectController as UserProjectController;
use App\Models\CompanyAdmin;
use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\User;
use App\Models\UserCompanyRole;
use App\Services\ProjectSellerAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Primary Seller vs Team Resources — the rules that keep the two apart.
 *
 * THE DISTINCTION UNDER TEST: projects.seller_id is the project's own seller
 * (its "primary seller"). Sales Team Resources is the list of OTHER sellers
 * that person brought in. The same human must never be in both, and holding
 * the Seller role must never make someone selectable as a Production resource
 * — even when they also hold Project Manager and carry a "PM Design"-style
 * designation, and even when they are the primary seller, who may MANAGE
 * production resources without being one.
 *
 * Exercises the real routes on both guards, so validation, the eligibility
 * whitelist and the rows that actually land in project_team_members are all
 * under test. Mirrors the scenario list the change was specified with.
 */
class ProjectPrimarySellerResourceRulesTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $adminId;
    private int $companyId;
    private int $otherCompanyId;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name'       => 'Primary Seller Admin',
            'email'      => 'psadmin+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($this->adminId);

        $this->companyId      = $this->makeCompany('Primary Co');
        $this->otherCompanyId = $this->makeCompany('Other Co');

        $this->project = Project::create([
            'company_id' => $this->companyId,
            'name'       => 'AI Application',
            'status'     => 'active',
        ]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name): int
    {
        $companyId = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The routes sit behind middleware('module:projects'). company_modules
        // has no created_at — only updated_at.
        DB::table('company_modules')->insert([
            'company_id' => $companyId, 'module_key' => 'projects', 'is_enabled' => true,
            'updated_at' => now(),
        ]);

        return $companyId;
    }

    /**
     * @param array<int, string> $extraRoles held IN ADDITION to $roleType, via
     *        user_company_roles — the multi-role mechanism holdingRole() reads.
     */
    private function makeStaff(string $roleType, array $extraRoles = [], ?int $companyId = null, bool $active = true): User
    {
        $companyId ??= $this->companyId;

        $user = User::create([
            'company_id' => $companyId,
            'name'       => ucfirst($roleType) . ' ' . uniqid(),
            'email'      => $roleType . '+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => $roleType,
            'is_active'  => $active,
            'status'     => $active ? 'active' : 'inactive',
        ]);

        // The assignment itself stays 'active' even for an inactive user —
        // company_user_assignments.status has its own narrower enum, and what
        // makes someone unassignable is the USER being deactivated, which is
        // exactly what assignableSeller() checks.
        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // holdingRole() reads user_company_roles when the user has any row for
        // the company, falling back to role_type only when they have none — so
        // the primary role has to be written here too, or adding a second role
        // would silently drop the first.
        foreach (array_unique(array_merge([$roleType], $extraRoles)) as $role) {
            UserCompanyRole::create([
                'user_id'    => $user->id,
                'company_id' => $companyId,
                'role'       => $role,
                'is_primary' => $role === $roleType,
            ]);
        }

        return $user;
    }

    private function switchSellerTo(User $seller): void
    {
        app(ProjectSellerAssignmentService::class)
            ->assign($this->project->fresh(), $seller, 'handover', null, $this->adminId, 'Test Admin');
    }

    private function grantPermission(User $user, string $permissionKey): void
    {
        DB::table('user_company_permissions')->insert([
            'user_id' => $user->id, 'company_id' => $user->company_id,
            'module_key' => 'project_management', 'permission_key' => $permissionKey,
        ]);
    }

    /**
     * assignTeam as a STAFF member (the user guard), which is where the
     * "only this project's own seller may staff it" rule lives.
     *
     * Called on the controller rather than over HTTP: the route's middleware
     * chain (active-company header, module and subscription gates) is not what
     * is under test, and the real method still runs — same convention as the
     * seller-picker test below.
     *
     * @param array<int, User> $users
     */
    private function assignTeamAsStaff(User $actor, array $users, string $roleInProject = 'team_member'): JsonResponse
    {
        $this->actingAs($actor, 'sanctum');

        $request = Request::create("/api/projects/{$this->project->id}/team", 'PUT', [
            'members' => array_map(
                fn (User $u) => ['user_id' => $u->id, 'role_in_project' => $roleInProject],
                $users
            ),
        ]);
        $this->app->instance('request', $request);

        return app(UserProjectController::class)->assignTeam($request, $this->project->id);
    }

    /** @param array<int, User> $users */
    private function assignTeam(array $users, string $roleInProject = 'team_member')
    {
        return $this->actingAs($this->admin, 'admin')->putJson(
            "/api/admin/projects/{$this->project->id}/team",
            ['members' => array_map(
                fn (User $u) => ['user_id' => $u->id, 'role_in_project' => $roleInProject],
                $users
            )]
        );
    }

    private function teamUserIds(): array
    {
        return ProjectTeamMember::where('project_id', $this->project->id)
            ->orderBy('user_id')->pluck('user_id')->all();
    }

    private function teamOf(User $user): ?ProjectTeamMember
    {
        return ProjectTeamMember::where('project_id', $this->project->id)
            ->where('user_id', $user->id)->first();
    }

    // ── Scenario 1 — switching makes the new person the primary seller ───────

    public function test_switching_makes_the_new_user_the_primary_seller_with_no_sales_team_row(): void
    {
        $sellerA = $this->makeStaff('seller');
        $sellerB = $this->makeStaff('seller');

        $this->switchSellerTo($sellerA);
        $this->switchSellerTo($sellerB);

        $this->assertSame($sellerB->id, (int) $this->project->fresh()->seller_id);
        $this->assertNull($this->teamOf($sellerB), 'The primary seller is not a Sales Team resource.');
    }

    /** Rule 1: a double-role user becomes the PRIMARY seller, not a secondary one. */
    public function test_a_seller_plus_project_manager_can_become_the_primary_seller(): void
    {
        $teamLead = $this->makeStaff('project_manager', ['seller']);

        $this->switchSellerTo($teamLead);

        $this->assertSame($teamLead->id, (int) $this->project->fresh()->seller_id);
        $this->assertNull($this->teamOf($teamLead));
    }

    // ── Scenario 2 & 3 — secondary sellers ───────────────────────────────────

    public function test_adding_another_seller_makes_them_a_sales_resource_and_leaves_the_primary_alone(): void
    {
        $primary   = $this->makeStaff('seller');
        $secondary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $this->assignTeam([$secondary])->assertOk();

        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id, 'Adding a resource must never change the primary seller.');
        $this->assertSame('sales', $this->teamOf($secondary)?->team);
    }

    public function test_a_seller_plus_pm_added_as_a_resource_lands_in_sales_not_production(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));
        $sellerPm = $this->makeStaff('project_manager', ['seller']);

        $this->assignTeam([$sellerPm])->assertOk();

        $this->assertSame('sales', $this->teamOf($sellerPm)?->team, 'Holding Seller puts them in Sales whatever else they hold.');
    }

    // ── Scenario 4, 5, 9, 10 — who may NOT be a production resource ──────────

    public function test_a_seller_role_user_cannot_be_added_as_a_production_resource(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));
        $sellerPm = $this->makeStaff('project_manager', ['seller']);

        $this->assignTeam([$sellerPm], 'production_user')
            ->assertStatus(422)
            ->assertJsonPath('message', 'A user who holds the Seller role can\'t be added as a Production Team resource.');

        $this->assertNull($this->teamOf($sellerPm));
    }

    public function test_a_plain_seller_cannot_be_added_as_a_production_resource_either(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));
        $plainSeller = $this->makeStaff('seller');

        $this->assignTeam([$plainSeller], 'production_user')->assertStatus(422);
        $this->assertNull($this->teamOf($plainSeller));
    }

    /** A genuine production user is unaffected by the new guard. */
    public function test_a_production_user_can_still_be_added_as_a_production_resource(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));
        $designer = $this->makeStaff('designer');

        $this->assignTeam([$designer], 'production_user')->assertOk();

        $this->assertSame('production', $this->teamOf($designer)?->team);
    }

    /** Scenario 5: the primary seller manages production without being one. */
    public function test_the_primary_seller_cannot_be_added_as_a_team_resource_at_all(): void
    {
        $primary = $this->makeStaff('project_manager', ['seller']);
        $this->switchSellerTo($primary);

        $this->assignTeam([$primary])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This user is already the project\'s seller, so they can\'t also be added as a team resource.');

        $this->assertNull($this->teamOf($primary));
    }

    public function test_a_cross_company_user_is_still_rejected(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));
        $outsider = $this->makeStaff('designer', [], $this->otherCompanyId);

        $this->assignTeam([$outsider])->assertStatus(422);
        $this->assertNull($this->teamOf($outsider));
    }

    public function test_an_inactive_user_cannot_become_the_primary_seller(): void
    {
        $inactive = $this->makeStaff('seller', [], null, false);

        $this->assertNull(
            app(ProjectSellerAssignmentService::class)->assignableSeller($this->companyId, $inactive->id),
            'An inactive user must not be assignable as a seller.'
        );
    }

    // ── Scenario 7 & 8 — the switch leaves everything else alone ─────────────

    public function test_switching_does_not_add_the_old_primary_seller_to_the_sales_team(): void
    {
        $oldPrimary = $this->makeStaff('seller');
        $newPrimary = $this->makeStaff('seller');

        $this->switchSellerTo($oldPrimary);
        $this->switchSellerTo($newPrimary);

        $this->assertNull($this->teamOf($oldPrimary), 'The outgoing seller must not silently become a secondary seller.');
        $this->assertNull($this->teamOf($newPrimary));
    }

    public function test_switching_leaves_unrelated_sales_and_production_resources_untouched(): void
    {
        $oldPrimary = $this->makeStaff('seller');
        $this->switchSellerTo($oldPrimary);

        $secondaryB = $this->makeStaff('seller');
        $secondaryC = $this->makeStaff('seller');
        $designer   = $this->makeStaff('designer');
        $this->assignTeam([$secondaryB, $secondaryC])->assertOk();
        $this->assignTeam([$designer], 'production_user')->assertOk();

        $this->switchSellerTo($this->makeStaff('seller'));

        $this->assertEqualsCanonicalizing(
            [$secondaryB->id, $secondaryC->id, $designer->id],
            $this->teamUserIds(),
            'A seller switch must only ever clear the OUTGOING seller\'s own rows.'
        );
    }

    public function test_several_secondary_sellers_coexist_and_removing_one_leaves_the_others(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));

        $b = $this->makeStaff('seller');
        $c = $this->makeStaff('seller');
        $d = $this->makeStaff('seller');
        $this->assignTeam([$b])->assertOk();
        $this->assignTeam([$c])->assertOk();
        $this->assignTeam([$d])->assertOk();

        $this->assertEqualsCanonicalizing([$b->id, $c->id, $d->id], $this->teamUserIds());

        $this->actingAs($this->admin, 'admin')
            ->deleteJson("/api/admin/projects/{$this->project->id}/team/" . $this->teamOf($c)->id)
            ->assertOk();

        $this->assertEqualsCanonicalizing([$b->id, $d->id], $this->teamUserIds());
        $this->assertNotNull($this->project->fresh()->seller_id, 'Removing a secondary seller must not touch the primary.');
    }

    // ── Who may staff the project ────────────────────────────────────────────

    /**
     * The rule: once a project has a primary seller, they are the one seller
     * who staffs it — together with the secondary sellers THEY, the project's
     * PM, or a Company Admin put on it, who may grow Sales exactly one level
     * further.
     *
     * Which side of that line someone falls on is LINEAGE — their own
     * project_team_members.assigned_by — not how many roles they hold here
     * (2026-10-08; it was role count before). A single-role seller an
     * authority added is therefore allowed, which the old rule refused.
     *
     * assignTeam() below is the Company Admin route, which writes
     * assigned_by null: authority, not unknown.
     */
    public function test_a_single_role_secondary_seller_added_by_authority_can_add_another_seller(): void
    {
        $primary   = $this->makeStaff('seller');
        $secondary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);
        $this->assignTeam([$secondary])->assertOk();
        $this->grantPermission($secondary, 'canAssignTeamResources');

        $another  = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($secondary, [$another]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sales', $this->teamOf($another)?->team);
        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id, 'Adding must never move the primary seller.');
    }

    /**
     * THE CHAIN STOPS AT DEPTH TWO. A seller that a SECONDARY seller added is
     * a leaf sales resource: genuinely on the team, holding
     * canAssignTeamResources, and still refused.
     *
     * The leaf here deliberately holds a SECOND role. Under the old
     * role-count rule that alone would have let them keep growing the roster
     * indefinitely; lineage is what actually stops it.
     */
    public function test_a_seller_added_by_a_secondary_seller_cannot_add_anyone(): void
    {
        $primary   = $this->makeStaff('seller');
        $secondary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);
        $this->assignTeam([$secondary])->assertOk();
        $this->grantPermission($secondary, 'canAssignTeamResources');

        $leaf = $this->makeStaff('project_manager', ['seller']);
        $this->assertSame(200, $this->assignTeamAsStaff($secondary, [$leaf])->getStatusCode());
        $this->grantPermission($leaf, 'canAssignTeamResources');

        $another  = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($leaf, [$another]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Only this project\'s own seller, or a seller its Project Manager added, can add resources to it.',
            $response->getData(true)['message'] ?? null
        );
        $this->assertNull($this->teamOf($another), 'Nothing may be written by a refused request.');
    }

    /**
     * A double-role secondary seller is still allowed — but for the same
     * reason the single-role one above is (a Company Admin put them on the
     * project), NOT because of the second seat. Kept as its own test so the
     * "PM Sales" shape stays covered after the rule changed underneath it.
     */
    public function test_a_double_role_secondary_seller_can_add_another_seller_to_sales(): void
    {
        $primary   = $this->makeStaff('seller');
        $secondary = $this->makeStaff('project_manager', ['seller']);
        $this->switchSellerTo($primary);
        $this->assignTeam([$secondary])->assertOk();
        $this->grantPermission($secondary, 'canAssignTeamResources');

        $another  = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($secondary, [$another]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sales', $this->teamOf($another)?->team);
        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id, 'Adding must never move the primary seller.');
    }

    /** ...but only into SALES. Production is the primary seller's and the PM's. */
    public function test_a_double_role_secondary_seller_cannot_add_a_production_resource(): void
    {
        $secondary = $this->makeStaff('project_manager', ['seller']);
        $this->switchSellerTo($this->makeStaff('seller'));
        $this->assignTeam([$secondary])->assertOk();
        $this->grantPermission($secondary, 'canAssignTeamResources');

        $designer = $this->makeStaff('designer');
        $response = $this->assignTeamAsStaff($secondary, [$designer]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'As a secondary seller, you can only add another Seller to this project\'s Sales Team.',
            $response->getData(true)['message'] ?? null
        );
        $this->assertNull($this->teamOf($designer));
    }

    /**
     * THE SPECIFIED FLOW, END TO END, in the order it was specified:
     *
     *   Project Manager → adds a Seller (becomes a Secondary Seller)
     *   → that Secondary Seller adds another Seller
     *   → the newly added Seller has view-only Sales access.
     *
     * Deliberately one test rather than four: what is under test is the
     * CHAIN, and each step's assigned_by is what the next step's gate reads.
     */
    public function test_the_full_pm_to_secondary_seller_to_leaf_seller_flow(): void
    {
        $pm = $this->makeStaff('project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        $this->grantPermission($pm, 'canAssignTeamResources');

        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        // 1. The PM adds a Seller for the first time -> Secondary Seller.
        $secondary = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignTeamAsStaff($pm, [$secondary])->getStatusCode());
        $secondaryRow = $this->teamOf($secondary);
        $this->assertSame('sales', $secondaryRow?->team, 'A Seller added through Team Resources lands in Sales.');
        $this->assertSame($pm->id, (int) $secondaryRow?->assigned_by, 'Lineage is what makes them a Secondary Seller.');

        // 2. That Secondary Seller adds another Seller — single-role, which
        //    the old rule refused and this one allows.
        $this->grantPermission($secondary, 'canAssignTeamResources');
        $leaf = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignTeamAsStaff($secondary, [$leaf])->getStatusCode());
        $leafRow = $this->teamOf($leaf);
        $this->assertSame('sales', $leafRow?->team, 'They become another Sales Team resource.');
        $this->assertSame($secondary->id, (int) $leafRow?->assigned_by);

        // 3. That newly added Seller is view-only — on the team, holding the
        //    permission, and still refused.
        $this->grantPermission($leaf, 'canAssignTeamResources');
        $fourth   = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($leaf, [$fourth]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->teamOf($fourth), 'The chain must stop at depth two.');

        // 4. The primary seller is untouched throughout, and never became a
        //    Sales Team row of their own.
        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id);
        $this->assertNull($this->teamOf($primary));
    }

    /**
     * The add relationship is one-directional, and removal follows it.
     *
     * A secondary seller only ever manages the people THEY brought in — so
     * they cannot remove their own row either: the seller who added them owns
     * it. The frontend stops showing a resource their own row at all (see
     * getTeamGroups()'s ownSubTeamOnly), and this is the matching server-side
     * refusal, so hiding the button is not the only thing standing in the way.
     */
    public function test_a_secondary_seller_cannot_remove_the_row_that_added_them(): void
    {
        $primary   = $this->makeStaff('seller');
        $secondary = $this->makeStaff('project_manager', ['seller']);
        $this->switchSellerTo($primary);
        $this->assignTeam([$secondary])->assertOk();
        $this->grantPermission($secondary, 'canAssignTeamResources');

        $ownRow = $this->teamOf($secondary);
        $this->assertNotNull($ownRow);

        $this->actingAs($secondary, 'sanctum');
        $response = app(UserProjectController::class)->removeTeamMember($this->project->id, $ownRow->id);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->teamOf($secondary), 'A refused removal must leave the row in place.');
    }

    public function test_the_projects_own_seller_can_still_add(): void
    {
        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);
        $this->grantPermission($primary, 'canAssignTeamResources');

        $secondary = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($primary, [$secondary]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sales', $this->teamOf($secondary)?->team);
    }

    /** The PM seat's authority is its own — not affected by the seller rule. */
    public function test_the_projects_project_manager_can_still_add(): void
    {
        $this->switchSellerTo($this->makeStaff('seller'));

        $pm = $this->makeStaff('project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        $this->grantPermission($pm, 'canAssignTeamResources');

        $designer = $this->makeStaff('designer');
        $response = $this->assignTeamAsStaff($pm, [$designer], 'production_user');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('production', $this->teamOf($designer)?->team);
    }

    /**
     * A project with no primary seller yet has to stay staffable, or it could
     * never be set up at all — the restriction only bites once there IS a
     * primary seller to defer to.
     */
    public function test_a_seller_can_still_staff_a_project_that_has_no_primary_seller(): void
    {
        $seller = $this->makeStaff('seller');
        $this->project->update(['created_by' => $seller->id]);
        $this->grantPermission($seller, 'canAssignTeamResources');
        $this->assertNull($this->project->fresh()->seller_id);

        $another = $this->makeStaff('seller');
        $response = $this->assignTeamAsStaff($seller, [$another]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($this->teamOf($another));
    }

    // ── The switch picker ────────────────────────────────────────────────────

    /**
     * Rule 1 again, from the staff side: a Team Lead / "PM Sales" (Seller held
     * as a SECOND role) has always been assignable by the service, but the
     * user-guard picker filtered on role_type and never listed them.
     */
    public function test_the_staff_seller_picker_lists_a_multi_role_seller(): void
    {
        $teamLead = $this->makeStaff('project_manager', ['seller']);

        $actor = $this->makeStaff('project_manager');
        DB::table('user_company_permissions')->insert([
            'user_id' => $actor->id, 'company_id' => $this->companyId,
            'module_key' => 'project_management', 'permission_key' => 'canAssignProjectSeller',
        ]);

        // The REAL sellers() method, not a copy of its query — a replica would
        // keep passing after the production picker changed, which is the whole
        // thing this test exists to catch. Called directly rather than over
        // HTTP because the route's middleware chain (active-company header,
        // module and subscription gates) is not what is under test here; the
        // same convention ProjectSellerSwitchVisibilityTest already uses.
        $this->actingAs($actor, 'sanctum');
        $payload = app(UserProjectController::class)->sellers()->getData(true);

        $this->assertContains(
            $teamLead->id,
            array_column($payload['data'], 'id'),
            'A Seller-as-second-role user must be offered in the Switch Seller picker.'
        );
    }

    // ── Sales hierarchy — who SEES what, and who may re-own the project ──────

    /**
     * The Sales roster as the SERVER narrows it for one viewer, straight off
     * the real show() payload — not a replica of the scoping rule, so a change
     * to visibleSalesMembers() that the tiers no longer agree with fails here.
     *
     * @return array<int, int> user ids, sorted
     */
    private function visibleSalesIdsFor(User $viewer): array
    {
        $this->grantPermission($viewer, 'canViewProjects');
        $this->actingAs($viewer, 'sanctum');

        $request = Request::create("/api/projects/{$this->project->id}", 'GET');
        $this->app->instance('request', $request);

        $data = app(UserProjectController::class)->show($this->project->id)->getData(true)['data'] ?? [];

        return collect($data['visible_sales_members'] ?? [])
            ->pluck('user_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    private function assignSellerAsStaff(User $actor, User $newSeller): JsonResponse
    {
        $this->actingAs($actor, 'sanctum');

        $request = Request::create("/api/projects/{$this->project->id}/seller", 'PATCH', [
            'seller_id' => $newSeller->id,
        ]);
        $this->app->instance('request', $request);

        return app(UserProjectController::class)->assignSeller($request, $this->project->id);
    }

    private function removeTeamMemberAsStaff(User $actor, int $memberId): JsonResponse
    {
        $this->actingAs($actor, 'sanctum');

        return app(UserProjectController::class)->removeTeamMember($this->project->id, $memberId);
    }

    /** Scenario 1 — the PM's own name is not inside the Seller's Sales roster. */
    public function test_a_first_level_seller_sees_their_own_name_not_the_pm_or_primary_seller(): void
    {
        // A PM who ALSO holds Seller: their team row is tagged 'sales', so
        // without the tier rule they would show up inside the very roster
        // they own.
        $pm = $this->makeStaff('project_manager', ['seller']);
        $this->project->update(['project_manager_id' => $pm->id]);
        $this->grantPermission($pm, 'canAssignTeamResources');
        $this->assignTeam([$pm])->assertOk();

        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $sellerA = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignTeamAsStaff($pm, [$sellerA])->getStatusCode());

        $this->assertSame([$sellerA->id], $this->visibleSalesIdsFor($sellerA));
    }

    /** Scenario 4 — a lower-level Seller sees only themselves and their adder. */
    public function test_a_lower_level_seller_sees_only_themselves_and_whoever_added_them(): void
    {
        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $sellerA   = $this->makeStaff('seller');
        $unrelated = $this->makeStaff('seller');
        $this->assignTeam([$sellerA, $unrelated])->assertOk();
        $this->grantPermission($sellerA, 'canAssignTeamResources');

        $sellerB = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignTeamAsStaff($sellerA, [$sellerB])->getStatusCode());

        $expected = [$sellerA->id, $sellerB->id];
        sort($expected);
        $this->assertSame($expected, $this->visibleSalesIdsFor($sellerB), 'Only self + adder.');

        // ...while the first-level seller still reads the whole roster.
        $all = [$sellerA->id, $unrelated->id, $sellerB->id];
        sort($all);
        $this->assertSame($all, $this->visibleSalesIdsFor($sellerA));
    }

    /** Scenario 5 — view-only really is view-only, API included. */
    public function test_a_lower_level_seller_cannot_add_remove_or_change_the_primary_seller(): void
    {
        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $sellerA = $this->makeStaff('seller');
        $this->assignTeam([$sellerA])->assertOk();
        $this->grantPermission($sellerA, 'canAssignTeamResources');

        $sellerB = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignTeamAsStaff($sellerA, [$sellerB])->getStatusCode());

        // Every management permission granted, so what refuses below is the
        // hierarchy and nothing else.
        $this->grantPermission($sellerB, 'canAssignTeamResources');
        $this->grantPermission($sellerB, 'canAssignProjectSeller');

        $outsider = $this->makeStaff('seller');
        $this->assertSame(403, $this->assignTeamAsStaff($sellerB, [$outsider])->getStatusCode(), 'may not add');
        $this->assertNull($this->teamOf($outsider));

        $rowOfA = $this->teamOf($sellerA);
        $this->assertSame(403, $this->removeTeamMemberAsStaff($sellerB, $rowOfA->id)->getStatusCode(), 'may not remove');
        $this->assertNotNull($this->teamOf($sellerA), 'The refused removal must not have happened.');

        $this->assertSame(403, $this->assignSellerAsStaff($sellerB, $outsider)->getStatusCode(), 'may not re-own');
        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id);
    }

    /**
     * The same refusal for the FIRST-level seller: managing the Sales roster
     * is theirs, re-owning the project is not. canAssignProjectSeller is a
     * DEFAULT grant for role_type='project_manager', so the "PM Sales" shape
     * reaches this without anyone deliberately granting it.
     */
    public function test_a_first_level_seller_cannot_replace_the_primary_seller_either(): void
    {
        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $sellerA = $this->makeStaff('project_manager', ['seller']);
        $this->assignTeam([$sellerA])->assertOk();
        $this->grantPermission($sellerA, 'canAssignProjectSeller');

        $response = $this->assignSellerAsStaff($sellerA, $this->makeStaff('seller'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Only this project\'s Project Manager or its current Seller can change the project\'s Seller.',
            $response->getData(true)['message'] ?? null
        );
        $this->assertSame($primary->id, (int) $this->project->fresh()->seller_id);
    }

    /** Scenario 6 — the two people who always could, still can. */
    public function test_the_pm_and_the_primary_seller_can_still_change_the_seller(): void
    {
        $pm = $this->makeStaff('project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        $this->grantPermission($pm, 'canAssignProjectSeller');

        $primary = $this->makeStaff('seller');
        $this->switchSellerTo($primary);

        $next = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignSellerAsStaff($pm, $next)->getStatusCode(), 'the PM may');
        $this->assertSame($next->id, (int) $this->project->fresh()->seller_id);

        $this->grantPermission($next, 'canAssignProjectSeller');
        $after = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignSellerAsStaff($next, $after)->getStatusCode(), 'the sitting seller may');
        $this->assertSame($after->id, (int) $this->project->fresh()->seller_id);
    }

    /**
     * A project with no primary seller yet stays assignable at all.
     *
     * The actor here is deliberately NEITHER the PM nor a seller — just a
     * team member holding the permission — so the only thing letting them
     * through is the "no primary seller yet" branch, not one of the two
     * ownership exemptions.
     */
    public function test_a_project_with_no_primary_seller_can_still_be_assigned_one(): void
    {
        $actor = $this->makeStaff('production');
        $this->assignTeam([$actor])->assertOk();
        $this->grantPermission($actor, 'canAssignProjectSeller');
        $this->assertNull($this->project->fresh()->seller_id);

        $seller = $this->makeStaff('seller');
        $this->assertSame(200, $this->assignSellerAsStaff($actor, $seller)->getStatusCode());
        $this->assertSame($seller->id, (int) $this->project->fresh()->seller_id);

        // ...and once it HAS one, that same actor is refused.
        $this->assertSame(403, $this->assignSellerAsStaff($actor, $this->makeStaff('seller'))->getStatusCode());
        $this->assertSame($seller->id, (int) $this->project->fresh()->seller_id);
    }
}
