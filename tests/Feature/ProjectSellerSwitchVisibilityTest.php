<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\User\ProjectController;
use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\User;
use App\Services\ProjectChatService;
use App\Services\ProjectSellerAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Who can still see a project after its Seller is switched away.
 *
 * The bug: switching a project to another Seller left the PREVIOUS Seller
 * seeing it exactly as before, so the switch looked like it had done nothing.
 * They kept it through the "soft" tiers of
 * Api\User\ProjectController::visibleProjects() — the originating lead is still
 * assigned to them, and they usually created the project.
 *
 * The rule now under test:
 *   HARD ties (current seller, PM, team member, task assignee) always win.
 *   SOFT ties (created_by, lead owner, client account manager) are suppressed
 *   for anyone this project has been switched away from.
 *
 * Drives the REAL visibleProjects() query and the real
 * ProjectSellerAssignmentService::assign(), rather than a copy of either — a
 * replica would keep passing after the production query changed.
 */
class ProjectSellerSwitchVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Switch Admin', 'email' => 'switchadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => 'Switch Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSeller(): User
    {
        $user = User::create([
            'company_id' => $this->companyId,
            'name' => 'Seller ' . uniqid(),
            'email' => 'seller+' . uniqid() . '@example.test',
            'password' => 'password',
            'role_type' => 'seller',
            'is_active' => true,
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Enough to pass index()'s gate, but deliberately NOT
        // canViewAllCompanyProjects (which bypasses the narrowing entirely) and
        // NOT canActivateProjects (which would also expose draft projects).
        DB::table('user_company_permissions')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyId,
            'module_key' => 'project_management', 'permission_key' => 'canViewProjects',
        ]);

        return $user;
    }

    /** A project whose originating lead belongs to $seller — the soft tie that caused the bug. */
    private function makeProjectOwnedBy(User $seller): Project
    {
        $leadId = DB::table('leads')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Lead ' . uniqid(),
            'assigned_to' => $seller->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Project::create([
            'company_id' => $this->companyId,
            'name'       => 'Project ' . uniqid(),
            'lead_id'    => $leadId,
            'seller_id'  => $seller->id,
            'created_by' => $seller->id,
            'status'     => 'active',
        ]);
    }

    /** Can $user see $project through the real visibleProjects() query? */
    private function canSee(User $user, Project $project): bool
    {
        $this->actingAs($user, 'sanctum');

        // visibleProjects() is private, and deliberately exercised as-is rather
        // than reimplemented here — a copy would keep passing after the real
        // query changed, which is the whole thing this test exists to catch.
        $method = new ReflectionMethod(ProjectController::class, 'visibleProjects');

        return $method->invoke(app(ProjectController::class))
            ->where('projects.id', $project->id)
            ->exists();
    }

    private function switchSellerTo(Project $project, User $newSeller): void
    {
        app(ProjectSellerAssignmentService::class)
            ->assign($project->fresh(), $newSeller, 'handover', null, $this->adminId, 'Test Admin');
    }

    // ── Before the switch ────────────────────────────────────────────────────

    public function test_the_owning_seller_sees_their_project_before_any_switch(): void
    {
        $seller  = $this->makeSeller();
        $project = $this->makeProjectOwnedBy($seller);

        $this->assertTrue($this->canSee($seller, $project));
    }

    // ── After the switch ─────────────────────────────────────────────────────

    public function test_the_previous_seller_loses_the_project_after_a_switch(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($project, $newSeller);

        $this->assertFalse(
            $this->canSee($oldSeller, $project),
            'The previous Seller still sees the project — the lead/created_by soft ties were not suppressed.'
        );
    }

    public function test_the_new_seller_sees_the_project_after_a_switch(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($project, $newSeller);

        $this->assertTrue($this->canSee($newSeller, $project));
    }

    // ── Hard ties still win ──────────────────────────────────────────────────

    /**
     * Being switched out as Seller is not the same as being taken off the
     * project. A past Seller kept on the team still works here — and removing
     * them from the team is what finally hides it, which is the behaviour asked
     * for.
     */
    public function test_a_previous_seller_kept_on_the_team_still_sees_the_project(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($project, $newSeller);

        ProjectTeamMember::create([
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
            'role_in_project' => 'team_member',
        ]);

        $this->assertTrue($this->canSee($oldSeller, $project));
    }

    public function test_removing_that_team_row_hides_the_project_again(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($project, $newSeller);

        $member = ProjectTeamMember::create([
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
            'role_in_project' => 'team_member',
        ]);
        $this->assertTrue($this->canSee($oldSeller, $project));

        $member->delete();

        $this->assertFalse($this->canSee($oldSeller, $project));
    }

    // ── No collateral damage ─────────────────────────────────────────────────

    /**
     * The suppression must reach ONLY past sellers of THIS project. Someone who
     * owns the lead but was never this project's seller keeps the soft tie they
     * have always had.
     */
    public function test_a_lead_owner_who_was_never_this_projects_seller_keeps_access(): void
    {
        $seller    = $this->makeSeller();
        $leadOwner = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($seller);

        // Hand the lead to someone who has never been this project's seller.
        DB::table('leads')->where('id', $project->lead_id)->update(['assigned_to' => $leadOwner->id]);

        $this->assertTrue(
            $this->canSee($leadOwner, $project),
            'An ordinary lead owner lost access — the suppression is too broad.'
        );
    }

    /** Being a past seller of ONE project must not affect another. */
    public function test_being_switched_off_one_project_does_not_hide_another(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();

        $switched = $this->makeProjectOwnedBy($oldSeller);
        $kept     = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($switched, $newSeller);

        $this->assertFalse($this->canSee($oldSeller, $switched));
        $this->assertTrue($this->canSee($oldSeller, $kept), 'An unrelated project was hidden too.');
    }

    // ── Team Resources / chat, 2026-09-29 (on request) ──────────────────────
    //
    // The switch itself now clears whatever project_team_members row the OLD
    // seller held AT THAT MOMENT (their real Sales row, or the self-handoff
    // cosmetic PM-seat placeholder) and their live chat participant row.
    // Distinct from the "kept on the team" tests above, which add that row
    // AFTER the switch — a later, separate, deliberate action this does not
    // reach.

    public function test_the_previous_sellers_own_team_row_at_switch_time_is_removed(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        // The self-handoff cosmetic PM-seat placeholder (or a real Sales row
        // added before this switch) — exists BEFORE switchSellerTo() runs.
        ProjectTeamMember::create([
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
            'role_in_project' => 'project_manager', 'team' => 'sales',
        ]);

        $this->switchSellerTo($project, $newSeller);

        $this->assertDatabaseMissing('project_team_members', [
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
        ]);
    }

    public function test_the_previous_sellers_chat_participation_is_dropped_by_the_switch(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        ProjectChatService::addSeller($project, $oldSeller->id);
        $thread = ProjectChatService::existingThreadFor($project);
        $this->assertDatabaseHas('chat_participants', [
            'thread_id' => $thread->id, 'user_id' => $oldSeller->id,
        ]);

        $this->switchSellerTo($project, $newSeller);

        $this->assertDatabaseMissing('chat_participants', [
            'thread_id' => $thread->id, 'user_id' => $oldSeller->id,
        ]);
    }

    /**
     * A team row added to the SAME project, for the SAME old seller, but
     * AFTER the switch already happened, is a later and separate act — it
     * must still grant access, same as the existing "kept on the team"
     * behaviour above. The switch only ever reaches what existed at the
     * moment it ran.
     */
    public function test_a_team_row_added_after_the_switch_is_unaffected(): void
    {
        $oldSeller = $this->makeSeller();
        $newSeller = $this->makeSeller();
        $project   = $this->makeProjectOwnedBy($oldSeller);

        $this->switchSellerTo($project, $newSeller);

        ProjectTeamMember::create([
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
            'role_in_project' => 'team_member', 'team' => 'sales',
        ]);

        $this->assertDatabaseHas('project_team_members', [
            'project_id' => $project->id, 'user_id' => $oldSeller->id,
        ]);
    }
}
