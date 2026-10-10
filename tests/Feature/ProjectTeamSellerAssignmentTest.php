<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\ProjectTeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage for PUT /api/admin/projects/{id}/team — the Team
 * Resources roster — focused on Sellers, which were capped at one per project
 * until 2026-09-15.
 *
 * Exercises the real route, so the whole chain is under test: middleware, the
 * request validation, the eligibility whitelist, the remaining one-PM cap, and
 * the rows that actually land in project_team_members. A model-level test would
 * have missed the parts that were genuinely broken.
 *
 * The properties that matter here, all of which had to hold before this could
 * ship: adding a Seller must not disturb the Sellers already on the team, must
 * not disturb the Project Manager or anyone else, must not create a duplicate,
 * and must not reach across companies.
 */
class ProjectTeamSellerAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $companyId;
    private int $otherCompanyId;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name'       => 'Team Test Admin',
            'email'      => 'teamadmin+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyId      = $this->makeCompany($adminId, 'Team Co');
        $this->otherCompanyId = $this->makeCompany($adminId, 'Other Co');

        $this->projectId = DB::table('projects')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Test Project',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCompany(int $adminId, string $name): int
    {
        $companyId = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The route sits behind middleware('module:projects').
        // company_modules has no created_at — only updated_at.
        DB::table('company_modules')->insert([
            'company_id' => $companyId, 'module_key' => 'projects', 'is_enabled' => true,
            'updated_at' => now(),
        ]);

        return $companyId;
    }

    /** A staff member with an ACTIVE assignment — what the endpoint validates against. */
    private function makeStaff(string $roleType, ?int $companyId = null): User
    {
        $companyId ??= $this->companyId;

        $user = User::create([
            'company_id' => $companyId,
            'name'       => ucfirst($roleType) . ' ' . uniqid(),
            'email'      => $roleType . '+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => $roleType,
            'is_active'  => true,
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param array<int, User> $users */
    private function assignTeam(array $users, string $roleInProject = 'team_member')
    {
        return $this->actingAs($this->admin, 'admin')->putJson(
            "/api/admin/projects/{$this->projectId}/team",
            ['members' => array_map(
                fn (User $u) => ['user_id' => $u->id, 'role_in_project' => $roleInProject],
                $users
            )]
        );
    }

    private function teamUserIds(): array
    {
        return ProjectTeamMember::where('project_id', $this->projectId)
            ->orderBy('user_id')->pluck('user_id')->all();
    }

    // ── The core ask ─────────────────────────────────────────────────────────

    public function test_a_single_seller_can_be_added_to_the_team(): void
    {
        $seller = $this->makeStaff('seller');

        $this->assignTeam([$seller])->assertOk();

        $this->assertSame([$seller->id], $this->teamUserIds());
    }

    public function test_several_sellers_can_be_added_in_one_request(): void
    {
        $sellers = [$this->makeStaff('seller'), $this->makeStaff('seller'), $this->makeStaff('seller')];

        $this->assignTeam($sellers)->assertOk();

        $expected = collect($sellers)->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $this->teamUserIds());
    }

    /**
     * The regression that blocked this: the second Seller used to come back
     * 422 "A project can only have one Seller on its team."
     */
    public function test_sellers_can_be_added_one_at_a_time_without_a_cap(): void
    {
        $first  = $this->makeStaff('seller');
        $second = $this->makeStaff('seller');
        $third  = $this->makeStaff('seller');

        $this->assignTeam([$first])->assertOk();
        $this->assignTeam([$second])->assertOk();
        $this->assignTeam([$third])->assertOk();

        $this->assertCount(3, $this->teamUserIds());
    }

    // ── Nothing existing may be disturbed ────────────────────────────────────

    public function test_adding_a_seller_leaves_the_sellers_already_on_the_team_alone(): void
    {
        $first  = $this->makeStaff('seller');
        $second = $this->makeStaff('seller');

        $this->assignTeam([$first])->assertOk();
        $this->assignTeam([$second])->assertOk();

        $this->assertContains($first->id, $this->teamUserIds(), 'The first Seller was dropped by the second add.');
        $this->assertContains($second->id, $this->teamUserIds());
    }

    public function test_adding_a_seller_preserves_the_project_manager_and_other_members(): void
    {
        $pm       = $this->makeStaff('project_manager');
        $designer = $this->makeStaff('designer');
        $seller   = $this->makeStaff('seller');

        $this->assignTeam([$pm], 'project_manager')->assertOk();
        $this->assignTeam([$designer])->assertOk();
        $this->assignTeam([$seller])->assertOk();

        $this->assertSame(
            collect([$pm->id, $designer->id, $seller->id])->sort()->values()->all(),
            $this->teamUserIds()
        );

        // The PM must also keep its role_in_project, not be flattened to team_member.
        $this->assertSame(
            'project_manager',
            ProjectTeamMember::where('project_id', $this->projectId)->where('user_id', $pm->id)->value('role_in_project')
        );
    }

    public function test_re_adding_the_same_seller_does_not_duplicate_the_row(): void
    {
        $seller = $this->makeStaff('seller');

        $this->assignTeam([$seller])->assertOk();
        $this->assignTeam([$seller])->assertOk();

        $this->assertSame(
            1,
            ProjectTeamMember::where('project_id', $this->projectId)->where('user_id', $seller->id)->count()
        );
    }

    // ── Guards that must still hold ──────────────────────────────────────────

    public function test_a_seller_from_another_company_is_rejected(): void
    {
        $outsider = $this->makeStaff('seller', $this->otherCompanyId);

        $this->assignTeam([$outsider])->assertStatus(422);

        $this->assertSame([], $this->teamUserIds(), 'A cross-company Seller must never land on the team.');
    }

    public function test_a_second_project_manager_is_still_refused(): void
    {
        $firstPm  = $this->makeStaff('project_manager');
        $secondPm = $this->makeStaff('project_manager');

        $this->assignTeam([$firstPm], 'project_manager')->assertOk();
        $this->assignTeam([$secondPm], 'project_manager')->assertStatus(422);

        $this->assertSame([$firstPm->id], $this->teamUserIds());
    }

    /**
     * 2026-09-28: the PM cap moved from counting a candidate's intrinsic
     * role_type to counting role_in_project='project_manager' specifically —
     * so "PM Design"/"PM Developer"/"PM SEO" (all role_type='project_manager'
     * company-wide) can now coexist on one project's Production team, as
     * long as only ONE of them is actually submitted as the literal PM.
     */
    public function test_a_second_pm_role_user_can_join_as_a_plain_team_member(): void
    {
        $literalPm = $this->makeStaff('project_manager');
        $pmDesign  = $this->makeStaff('project_manager');

        $this->assignTeam([$literalPm], 'project_manager')->assertOk();
        $this->assignTeam([$pmDesign], 'team_member')->assertOk();

        $this->assertSame(
            collect([$literalPm->id, $pmDesign->id])->sort()->values()->all(),
            $this->teamUserIds()
        );
        $this->assertSame(
            'project_manager',
            ProjectTeamMember::where('project_id', $this->projectId)->where('user_id', $literalPm->id)->value('role_in_project'),
        );
        $this->assertSame(
            'team_member',
            ProjectTeamMember::where('project_id', $this->projectId)->where('user_id', $pmDesign->id)->value('role_in_project'),
        );
    }

    public function test_an_ineligible_role_is_still_refused(): void
    {
        $hr = $this->makeStaff('hr');

        $this->assignTeam([$hr])->assertStatus(422);

        $this->assertSame([], $this->teamUserIds());
    }
}
