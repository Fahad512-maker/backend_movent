<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Super Admin deactivates a Company Admin — what that has to mean.
 *
 * THE DISTINCTION UNDER TEST: company_admins.is_active is the Super Admin's
 * switch over a whole TENANT, and it is NOT the subscription lifecycle.
 * Nobody inside the tenant can pay their way back in or wait it out — only
 * the Super Admin can reverse it. So it has to take down the owner AND every
 * staff member of every company they own, on all three counts at once: live
 * sessions end the moment the switch is flipped, a fresh login is refused
 * with the exact wording, and a token that somehow outlived the flip is
 * refused on every protected route.
 *
 * The owner and their staff are told different things deliberately — it is
 * not the staff member's own account that was deactivated — so the two
 * messages are asserted separately rather than as one shared string.
 *
 * Exercises the real routes on every guard (super_admin, admin, sanctum, and
 * the unauthenticated login endpoints), so the token revocation, the
 * middleware and both login gates are under test rather than any copy of
 * their logic.
 */
class TenantDeactivationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'password123';

    private SuperAdmin $superAdmin;
    private CompanyAdmin $admin;
    private int $companyId;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = SuperAdmin::create([
            'name'     => 'Platform Owner',
            'email'    => 'super+' . uniqid() . '@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->admin     = $this->makeAdmin();
        $this->companyId = $this->makeCompany($this->admin);
        $this->staff     = $this->makeStaff($this->companyId);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeAdmin(): CompanyAdmin
    {
        // packages.name is unique, and a single test can build several
        // tenants (see the blast-radius cases below).
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package ' . uniqid(), 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CompanyAdmin::create([
            'package_id' => $packageId,
            'name'       => 'Tenant Owner',
            'email'      => 'tenant+' . uniqid() . '@example.test',
            'password'   => self::PASSWORD,
            'is_active'  => true,
            // 'trial' passes the payment gate, so nothing but is_active is
            // ever the reason a request is refused in here.
            'subscription_status' => 'trial',
        ]);
    }

    private function makeCompany(CompanyAdmin $admin): int
    {
        return DB::table('companies')->insertGetId([
            'admin_id' => $admin->id, 'name' => 'Co ' . uniqid(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStaff(int $companyId): User
    {
        $user = User::create([
            'company_id' => $companyId,
            'name'       => 'Staff ' . uniqid(),
            'email'      => 'staff+' . uniqid() . '@example.test',
            'password'   => self::PASSWORD,
            'role_type'  => 'project_manager',
            'is_active'  => true,
            'status'     => 'active',
        ]);

        DB::table('company_user_assignments')->insert([
            'user_id' => $user->id, 'company_id' => $companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** A live admin session: the token an already-signed-in admin is holding. */
    private function liveToken(): string
    {
        return $this->admin->createToken('admin-token', ['role:admin'])->plainTextToken;
    }

    /** The Super Admin's own switch — the real route, on the real guard. */
    private function toggle(bool $expectActive)
    {
        return $this->actingAs($this->superAdmin, 'super_admin')
            ->patchJson("/api/super-admin/admins/{$this->admin->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', $expectActive);
    }

    private function deactivate(): void
    {
        $this->toggle(false);
    }

    private function reactivate(): void
    {
        $this->toggle(true);
    }

    private function login()
    {
        return $this->postJson('/api/admin/login', [
            'email'    => $this->admin->email,
            'password' => self::PASSWORD,
        ]);
    }

    private function staffLogin(?User $user = null)
    {
        return $this->postJson('/api/user/login', [
            'email'    => ($user ?? $this->staff)->email,
            'password' => self::PASSWORD,
        ]);
    }

    /** A live staff session: the token an already-signed-in user is holding. */
    private function liveStaffToken(?User $user = null): string
    {
        return ($user ?? $this->staff)->createToken('user-token', ['role:user'])->plainTextToken;
    }

    private function tokenCount(): int
    {
        return $this->admin->tokens()->count();
    }

    private function staffTokenCount(?User $user = null): int
    {
        return ($user ?? $this->staff)->tokens()->count();
    }

    // ── The live session ends immediately ────────────────────────────────────

    public function test_deactivating_revokes_every_token_the_admin_is_holding(): void
    {
        $this->liveToken();
        $this->liveToken();
        $this->assertSame(2, $this->tokenCount());

        $this->deactivate();

        $this->assertSame(0, $this->tokenCount(), 'A deactivated admin must not be left holding a usable session.');
    }

    /** The practical consequence: their very next request is unauthenticated. */
    public function test_a_live_session_stops_working_the_moment_the_switch_is_flipped(): void
    {
        $token = $this->liveToken();

        $this->withToken($token)->getJson('/api/admin/me')->assertOk();

        $this->deactivate();

        // The auth manager is a singleton that lives for the whole test, so
        // once a guard has resolved a user it hands that same instance to
        // every later request in this method without re-reading the token.
        // Production has no such carry-over — each request resolves from
        // scratch — so forgetting the guards here is what makes the next call
        // a faithful "their next request", not a workaround for a real leak.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(401);
    }

    // ── Logging back in ──────────────────────────────────────────────────────

    public function test_logging_in_again_is_refused_with_the_exact_message(): void
    {
        $this->deactivate();

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been deactivated by the Super Admin. Please contact the administrator for assistance.')
            ->assertJsonPath('errors.error_code', 'account_deactivated');
    }

    /** The one constant every refusal path shares — drift here is the bug. */
    public function test_the_message_is_the_one_the_model_publishes(): void
    {
        $this->deactivate();

        $this->login()->assertJsonPath('message', CompanyAdmin::DEACTIVATED_MESSAGE);
    }

    public function test_a_wrong_password_is_still_just_invalid_credentials(): void
    {
        $this->deactivate();

        $this->postJson('/api/admin/login', ['email' => $this->admin->email, 'password' => 'not-the-password'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    // ── A token that outlived the flip ───────────────────────────────────────

    /**
     * Revocation is not the only thing standing in the way. If a token exists
     * for a deactivated admin by any route — is_active flipped straight in the
     * database, a token minted in the same instant — the middleware still
     * refuses it, and says why.
     */
    public function test_a_surviving_token_is_refused_on_a_protected_route(): void
    {
        $this->admin->update(['is_active' => false]);
        $token = $this->liveToken();

        $this->withToken($token)->getJson('/api/admin/companies')
            ->assertStatus(403)
            ->assertJsonPath('message', CompanyAdmin::DEACTIVATED_MESSAGE)
            ->assertJsonPath('errors.error_code', 'account_deactivated');
    }

    /** Including `me`, which is otherwise exempt from the other account gates. */
    public function test_even_me_is_refused_for_a_deactivated_admin(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->withToken($this->liveToken())->getJson('/api/admin/me')->assertStatus(403);
    }

    /** ...but logout still works, so the token is really handed back. */
    public function test_logout_still_works_so_the_token_is_actually_deleted(): void
    {
        $this->admin->update(['is_active' => false]);
        $token = $this->liveToken();

        $this->withToken($token)->postJson('/api/admin/logout')->assertOk();

        $this->assertSame(0, $this->tokenCount());
    }

    // ── Nothing changes for an active admin ──────────────────────────────────

    public function test_an_active_admin_reaches_protected_routes_as_before(): void
    {
        $this->withToken($this->liveToken())->getJson('/api/admin/companies')->assertOk();
    }

    public function test_an_active_admin_can_still_log_in(): void
    {
        $this->login()->assertOk()->assertJsonPath('data.type', 'admin');
    }

    // ── Reactivation ─────────────────────────────────────────────────────────

    public function test_reactivating_lets_them_log_in_again(): void
    {
        $this->deactivate();
        $this->login()->assertStatus(403);

        $this->reactivate();

        $this->login()->assertOk();
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    // ── The tenant's STAFF go down with it ───────────────────────────────────

    public function test_deactivating_revokes_the_staffs_tokens_too(): void
    {
        $this->liveStaffToken();
        $this->assertSame(1, $this->staffTokenCount());

        $this->deactivate();

        $this->assertSame(0, $this->staffTokenCount(), 'A switched-off tenant must not leave its staff signed in.');
    }

    /**
     * Reached through company_user_assignments, not users.company_id.
     *
     * A staff member added to a second company only ever gets an assignment
     * row — their users.company_id stays pinned to whoever created the
     * account — so matching on that column alone would miss exactly the
     * people this is for.
     */
    public function test_a_staff_member_assigned_by_row_only_is_revoked_as_well(): void
    {
        $otherAdmin = $this->makeAdmin();
        $guest = $this->makeStaff($this->makeCompany($otherAdmin));

        // Now also assigned into THIS admin's company, the way a second
        // company is granted — their company_id still points elsewhere.
        DB::table('company_user_assignments')->insert([
            'user_id' => $guest->id, 'company_id' => $this->companyId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->liveStaffToken($guest);

        $this->deactivate();

        $this->assertSame(0, $this->staffTokenCount($guest));
    }

    public function test_staff_cannot_log_in_and_are_told_it_is_the_company(): void
    {
        $this->deactivate();

        $this->staffLogin()
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your company account has been deactivated by the Super Admin. Please contact your administrator for assistance.')
            ->assertJsonPath('message', CompanyAdmin::TENANT_DEACTIVATED_MESSAGE)
            ->assertJsonPath('errors.error_code', 'company_deactivated');
    }

    /** Staff are NOT told their own account was deactivated — it wasn't. */
    public function test_the_staff_message_is_not_the_owners_message(): void
    {
        $this->deactivate();

        $this->staffLogin()->assertJsonPath('message', fn ($m) => $m !== CompanyAdmin::DEACTIVATED_MESSAGE);
        $this->assertTrue($this->staff->fresh()->is_active, 'The staff row itself must not be touched by a tenant-level switch.');
    }

    public function test_a_surviving_staff_token_is_refused_on_a_protected_route(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->withToken($this->liveStaffToken())->getJson('/api/user/profile')
            ->assertStatus(403)
            ->assertJsonPath('message', CompanyAdmin::TENANT_DEACTIVATED_MESSAGE)
            ->assertJsonPath('errors.error_code', 'company_deactivated');
    }

    /** Including `me`, which is otherwise exempt from the other tenant gates. */
    public function test_even_staff_me_is_refused(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->withToken($this->liveStaffToken())->getJson('/api/user/me')->assertStatus(403);
    }

    public function test_staff_logout_still_works(): void
    {
        $this->admin->update(['is_active' => false]);
        $this->withToken($this->liveStaffToken())->postJson('/api/user/logout')->assertOk();

        $this->assertSame(0, $this->staffTokenCount());
    }

    public function test_reactivating_lets_staff_back_in(): void
    {
        $this->deactivate();
        $this->staffLogin()->assertStatus(403);

        $this->reactivate();

        $this->staffLogin()->assertOk();
    }

    // ── Blast radius: no other tenant is touched ─────────────────────────────

    public function test_another_tenants_staff_keep_their_session_and_their_login(): void
    {
        $otherAdmin = $this->makeAdmin();
        $outsider = $this->makeStaff($this->makeCompany($otherAdmin));
        $outsiderToken = $this->liveStaffToken($outsider);

        $this->deactivate();

        $this->assertSame(1, $this->staffTokenCount($outsider), 'Only the deactivated tenant\'s users may be signed out.');
        $this->withToken($outsiderToken)->getJson('/api/user/profile')->assertOk();
        $this->staffLogin($outsider)->assertOk();
    }

    public function test_another_tenants_admin_is_untouched(): void
    {
        $otherAdmin = $this->makeAdmin();
        $otherToken = $otherAdmin->createToken('admin-token', ['role:admin'])->plainTextToken;

        $this->deactivate();

        $this->assertSame(1, $otherAdmin->tokens()->count());
        $this->withToken($otherToken)->getJson('/api/admin/me')->assertOk();
    }

    // ── Staff are unaffected while the tenant is on ──────────────────────────

    public function test_staff_reach_protected_routes_while_the_tenant_is_active(): void
    {
        $this->withToken($this->liveStaffToken())->getJson('/api/user/profile')->assertOk();
    }

    public function test_staff_can_still_log_in_while_the_tenant_is_active(): void
    {
        $this->staffLogin()->assertOk();
    }
}
