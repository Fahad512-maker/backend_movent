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
 * The cosmetic ProjectTeamMember row created for whoever lands in
 * project_manager_id (Api\Admin\ProjectController::addAsProjectManager(),
 * and its twins in Api\User\ProjectController::store() and
 * PaymentProjectStartService) must be tagged `team` the same way every other
 * row is: a Seller (a self-handoff project, where the Seller defaults into
 * their own PM seat) belongs in Sales Team Resources, never Production —
 * otherwise "Do not show Sellers inside Production Team Resources" silently
 * breaks for every self-handoff project.
 *
 * Drives the real POST /api/admin/projects route rather than the private
 * addAsProjectManager() method directly.
 */
class ProjectManagerSeatTeamTaggingTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name'       => 'Tagging Test Admin',
            'email'      => 'taggingadmin+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Tagging Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The route sits behind middleware('module:projects').
        DB::table('company_modules')->insert([
            'company_id' => $this->companyId, 'module_key' => 'projects', 'is_enabled' => true,
            'updated_at' => now(),
        ]);
    }

    private function makeStaff(string $roleType): User
    {
        $user = User::create([
            'company_id' => $this->companyId,
            'name'       => ucfirst($roleType) . ' ' . uniqid(),
            'email'      => $roleType . '+' . uniqid() . '@example.test',
            'password'   => 'password',
            'role_type'  => $roleType,
            'is_active'  => true,
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function createProject(User $projectManager): int
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/api/admin/projects', [
            'company_id'         => $this->companyId,
            'name'               => 'Project ' . uniqid(),
            'project_manager_id' => $projectManager->id,
        ]);

        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_a_seller_placed_in_the_project_manager_seat_is_tagged_sales(): void
    {
        $seller = $this->makeStaff('seller');
        $projectId = $this->createProject($seller);

        $this->assertSame('sales', ProjectTeamMember::where('project_id', $projectId)
            ->where('user_id', $seller->id)->value('team'));
    }

    public function test_a_real_project_manager_in_the_seat_is_still_tagged_production(): void
    {
        $pm = $this->makeStaff('project_manager');
        $projectId = $this->createProject($pm);

        $this->assertSame('production', ProjectTeamMember::where('project_id', $projectId)
            ->where('user_id', $pm->id)->value('team'));
    }
}
