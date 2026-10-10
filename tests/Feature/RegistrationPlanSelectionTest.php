<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\Module;
use App\Models\Package;
use App\Models\SubscriptionPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registration now creates the account BEFORE the Packages / Custom Modules
 * step, instead of after it.
 *
 * The register screen used to collect the plan on step 2 and only then call
 * POST /public/register, which required package_id and selected_modules — so
 * anyone landing on /register was taken to the plan picker before they had an
 * account. Both fields are optional now: the account is created on step 1
 * against a stand-in package (company_admins.package_id is NOT NULL and
 * CASCADE DELETEs, so there always has to be one), and the plan the user
 * actually picks is saved afterwards through
 * POST /admin/subscription/select-plan.
 *
 * That endpoint is free — it charges nothing — so the tests below also pin
 * down who may call it (test_select_plan_is_refused_*).
 */
class RegistrationPlanSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private Package $cheapPackage;
    private Package $dearPackage;

    protected function setUp(): void
    {
        parent::setUp();

        // movent_test carries schema but no catalog data (see phpunit.xml) —
        // register()'s trial grant and the custom-module checks both read the
        // real Module rows, so seed them. Rolled back with everything else.
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        // Trials off, so start_type=trial and =paid both land on
        // pending_payment and the plan step is what's under test, not the
        // trial's full-catalog grant.
        $this->setTrialPolicy(false);

        // HR sub-modules on purpose: they belong to no dependency category
        // (see ModuleDependency), so these fixtures test the plan flow rather
        // than re-testing the module dependency rules.
        $this->cheapPackage = $this->package('Reg Cheap', 10, ['employees']);
        $this->dearPackage  = $this->package('Reg Dear', 90, ['employees', 'recruitment']);
    }

    /**
     * Written straight to row id = 1 rather than through
     * SubscriptionPolicy::current(). That helper is firstOrCreate(['id' => 1])
     * and `id` isn't fillable, so it can't actually pin the row to 1 — under
     * DatabaseTransactions (where MySQL's auto-increment counter does NOT roll
     * back with the data) it ends up inserting a fresh row on every call and
     * returning a model with no attributes loaded, which reads as null for
     * everything. Not a problem for a live install whose single row already
     * sits at id = 1; it just can't be relied on to configure policy here.
     */
    private function setTrialPolicy(bool $enabled, int $days = 14): void
    {
        DB::table('subscription_policies')->updateOrInsert(
            ['id' => 1],
            ['trial_enabled' => $enabled, 'trial_duration_days' => $days]
        );
    }

    private function package(string $name, float $price, array $moduleKeys): Package
    {
        $package = Package::create([
            'name' => $name . ' ' . uniqid(), 'tier' => 'basic', 'billing_cycle' => 'monthly',
            'price' => $price, 'price_usd' => $price, 'discount_percent' => 0,
            'is_active' => true, 'is_visible' => true,
        ]);

        foreach ($moduleKeys as $key) {
            $package->modules()->create(['module_key' => $key]);
        }

        return $package;
    }

    private function registrationPayload(array $overrides = []): array
    {
        $unique = uniqid();

        return array_merge([
            'company_name' => 'Reg Co ' . $unique,
            'name'         => 'Reg Owner',
            'email'        => 'regowner+' . $unique . '@example.test',
            'password'     => 'password123',
            'password_confirmation' => 'password123',
            'currency'     => 'USD',
            'start_type'   => 'paid',
            'timezone'     => 'America/New_York',
            'country'      => 'US',
        ], $overrides);
    }

    // â”€â”€ Step 1: the account is created with no plan chosen â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_registration_succeeds_without_a_package_or_module_selection(): void
    {
        $response = $this->postJson('/api/public/register', $this->registrationPayload())
            ->assertStatus(201);

        $this->assertNotEmpty($response->json('data.token'), 'Step 1 must return a usable admin token.');
        $this->assertSame('pending_payment', $response->json('data.admin.subscription_status'));

        $admin = CompanyAdmin::findOrFail($response->json('data.admin.id'));
        // The stand-in: cheapest visible base-monthly plan, so the resumed
        // checkout's order summary always has something real to price.
        $this->assertSame($this->cheapPackage->id, $admin->package_id);
        $this->assertFalse((bool) $admin->is_custom_selection);
        $this->assertDatabaseHas('companies', ['admin_id' => $admin->id]);

        // No modules yet — nothing has been chosen. The stand-in's own set is
        // deliberately NOT granted (see register()); the plan step turns the
        // real selection on.
        $this->assertEmpty($admin->companies->first()->modules()->pluck('module_key')->all());
    }

    /** The old required payload still has to work, unchanged. */
    public function test_registration_still_honors_an_explicitly_supplied_package(): void
    {
        $response = $this->postJson('/api/public/register', $this->registrationPayload([
            'package_id'       => $this->dearPackage->id,
            'selected_modules' => ['employees', 'recruitment'],
        ]))->assertStatus(201);

        $admin = CompanyAdmin::findOrFail($response->json('data.admin.id'));
        $this->assertSame($this->dearPackage->id, $admin->package_id);
        $this->assertEqualsCanonicalizing(
            ['employees', 'recruitment'],
            $admin->companies->first()->modules()->pluck('module_key')->all()
        );
    }

    public function test_registration_still_rejects_an_invalid_package_id(): void
    {
        $this->postJson('/api/public/register', $this->registrationPayload(['package_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('package_id');
    }

    /** Every other rule on step 1 is untouched by the reordering. */
    public function test_registration_validation_is_preserved(): void
    {
        $this->postJson('/api/public/register', $this->registrationPayload(['email' => 'not-an-email']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/public/register', $this->registrationPayload([
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/public/register', $this->registrationPayload([
            'password_confirmation' => 'does-not-match',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/public/register', $this->registrationPayload(['company_name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('company_name');
    }

    public function test_a_duplicate_email_is_still_refused(): void
    {
        $payload = $this->registrationPayload();
        $this->postJson('/api/public/register', $payload)->assertStatus(201);

        $this->postJson('/api/public/register', array_merge($payload, [
            'company_name' => 'Second Co ' . uniqid(),
        ]))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // â”€â”€ Step 2: the plan is saved against the account that now exists â”€â”€â”€â”€â”€â”€â”€

    private function registerThenActAsAdmin(): CompanyAdmin
    {
        $id = $this->postJson('/api/public/register', $this->registrationPayload())
            ->assertStatus(201)->json('data.admin.id');

        $admin = CompanyAdmin::findOrFail($id);
        Sanctum::actingAs($admin, ['role:admin'], 'admin');

        return $admin;
    }

    public function test_select_plan_saves_the_chosen_package_and_its_modules(): void
    {
        $admin = $this->registerThenActAsAdmin();

        $this->postJson('/api/admin/subscription/select-plan', [
            'package_id' => $this->dearPackage->id,
            'max_users'  => 10,
            'max_companies' => 2,
        ])->assertOk()->assertJsonPath('data.package_id', $this->dearPackage->id);

        $admin->refresh();
        $this->assertSame($this->dearPackage->id, $admin->package_id);
        $this->assertSame(10, $admin->max_users_per_company);
        $this->assertSame(2, $admin->max_companies);
        $this->assertFalse((bool) $admin->is_custom_selection);

        $this->assertEqualsCanonicalizing(
            ['employees', 'recruitment'],
            $admin->companies->first()->modules()->where('is_enabled', true)->pluck('module_key')->all()
        );

        // Still unpaid — selecting a plan must never activate the account.
        $this->assertSame('pending_payment', $admin->subscription_status);
    }

    /** Changing the pick on step 2 must not leave the previous plan's modules behind. */
    public function test_select_plan_replaces_a_previously_chosen_packages_modules(): void
    {
        $admin = $this->registerThenActAsAdmin();
        $company = $admin->companies->first();

        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $this->dearPackage->id])->assertOk();
        $this->assertEqualsCanonicalizing(['employees', 'recruitment'], $company->modules()->pluck('module_key')->all());

        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $this->cheapPackage->id])->assertOk();
        $this->assertEqualsCanonicalizing(['employees'], $company->modules()->pluck('module_key')->all());
    }

    public function test_select_plan_accepts_a_custom_module_selection(): void
    {
        $admin = $this->registerThenActAsAdmin();
        // HR again — a whole category that pulls in no dependencies of its own.
        $module = Module::where('key', 'hr')->firstOrFail();

        $this->postJson('/api/admin/subscription/select-plan', [
            'package_id'          => $this->cheapPackage->id,
            'is_custom_selection' => true,
            'selected_modules'    => $module->sub_modules,
        ])->assertOk();

        $admin->refresh();
        $this->assertTrue((bool) $admin->is_custom_selection);
        $this->assertEqualsCanonicalizing(
            $module->sub_modules,
            $admin->companies->first()->modules()->pluck('module_key')->all()
        );
    }

    /** A hand-picked set is vetted here, not just at the till. */
    public function test_select_plan_rejects_a_partial_category_selection(): void
    {
        $this->registerThenActAsAdmin();
        // One slice of HR, not the whole category — paying for part of a
        // category while asking to have it enabled.
        $module = Module::where('key', 'hr')->firstOrFail();
        $this->assertGreaterThan(1, count($module->sub_modules));

        $this->postJson('/api/admin/subscription/select-plan', [
            'package_id'          => $this->cheapPackage->id,
            'is_custom_selection' => true,
            'selected_modules'    => [$module->sub_modules[0]],
        ])->assertStatus(422);
    }

    public function test_select_plan_rejects_an_unknown_module_key(): void
    {
        $this->registerThenActAsAdmin();

        $this->postJson('/api/admin/subscription/select-plan', [
            'package_id'          => $this->cheapPackage->id,
            'is_custom_selection' => true,
            'selected_modules'    => ['not_a_real_module'],
        ])->assertStatus(422);
    }

    public function test_select_plan_rejects_a_hidden_or_inactive_package(): void
    {
        $this->registerThenActAsAdmin();
        $retired = $this->package('Reg Retired', 50, ['employees']);
        $retired->update(['is_active' => false]);

        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $retired->id])
            ->assertStatus(422);
    }

    public function test_select_plan_requires_a_package(): void
    {
        $this->registerThenActAsAdmin();

        $this->postJson('/api/admin/subscription/select-plan', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('package_id');
    }

    // â”€â”€ Who may call it â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_select_plan_is_refused_for_an_unauthenticated_caller(): void
    {
        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $this->cheapPackage->id])
            ->assertStatus(401);
    }

    /** It charges nothing — an already-paying account must not swap plan through it. */
    public function test_select_plan_is_refused_once_the_subscription_is_active(): void
    {
        $admin = $this->registerThenActAsAdmin();
        $admin->update(['subscription_status' => 'active']);

        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $this->dearPackage->id])
            ->assertStatus(422);

        $this->assertSame($this->cheapPackage->id, $admin->fresh()->package_id);
    }

    public function test_select_plan_is_refused_for_an_account_that_has_already_paid(): void
    {
        $admin = $this->registerThenActAsAdmin();

        DB::table('subscription_payments')->insert([
            'admin_id' => $admin->id, 'package_id' => $admin->package_id,
            'amount' => 10, 'currency' => 'USD', 'gateway' => 'stripe',
            'gateway_ref' => 'test-' . uniqid(), 'status' => 'paid',
            'period_start' => now(), 'period_end' => now()->addMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/admin/subscription/select-plan', ['package_id' => $this->dearPackage->id])
            ->assertStatus(422);

        $this->assertSame($this->cheapPackage->id, $admin->fresh()->package_id);
    }

    // â”€â”€ The saved plan is what checkout then prices â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_order_summary_prices_the_plan_saved_at_step_two(): void
    {
        $this->registerThenActAsAdmin();

        $this->postJson('/api/admin/subscription/select-plan', [
            'package_id' => $this->dearPackage->id, 'max_users' => 5,
        ])->assertOk();

        $this->getJson('/api/admin/subscription/order-summary')
            ->assertOk()
            ->assertJsonPath('data.package_id', $this->dearPackage->id)
            ->assertJsonPath('data.total_usd', 90)
            ->assertJsonPath('data.seats', '5 Users');
    }

    /** A sign-up abandoned before step 2 can still resume checkout. */
    public function test_order_summary_works_even_if_step_two_was_never_reached(): void
    {
        $this->registerThenActAsAdmin();

        $this->getJson('/api/admin/subscription/order-summary')
            ->assertOk()
            ->assertJsonPath('data.package_id', $this->cheapPackage->id);
    }

    // â”€â”€ Trial path is unchanged â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_a_granted_trial_still_gets_the_full_catalog_with_no_plan_step(): void
    {
        $this->setTrialPolicy(true, 14);

        $response = $this->postJson('/api/public/register', $this->registrationPayload(['start_type' => 'trial']))
            ->assertStatus(201);

        $this->assertSame('trial', $response->json('data.admin.subscription_status'));

        $admin = CompanyAdmin::findOrFail($response->json('data.admin.id'));
        $granted = $admin->companies->first()->modules()->pluck('module_key')->all();
        $catalog = Module::where('is_active', true)->pluck('sub_modules')->flatten()->unique()->values()->all();

        $this->assertEqualsCanonicalizing($catalog, $granted, 'A trial still gets every module.');
        $this->assertNotNull($admin->trial_ends_at);
    }
}

