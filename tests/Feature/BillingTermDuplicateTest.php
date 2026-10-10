<?php

namespace Tests\Feature;

use App\Models\BillingTerm;
use App\Models\Package;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super Admin billing-term create/update — the fix for duplicate terms and
 * for "Monthly1".
 *
 * Monthly used to exist only as a hardcoded label on the register/payment
 * screens, and BillingTermController refused the name outright
 * (not_in:Monthly,monthly). With no way to edit it and no uniqueness on the
 * table, the only way to change the monthly discount was to type a name that
 * got past that rule — which then generated a parallel set of companion
 * packages. A second "Yearly" went through just as easily.
 *
 * Now:
 *   - Monthly is a real row (months = 1), seeded by migration;
 *   - a name that already exists is an EDIT of that term, never a second row
 *     (test_*_updates_the_existing_* below);
 *   - name and duration are each unique;
 *   - the months = 1 term generates no companion package (it IS the base
 *     package) and its discount lands on the base packages instead;
 *   - existing package/subscription links are never re-pointed by any of it.
 */
class BillingTermDuplicateTest extends TestCase
{
    use DatabaseTransactions;

    private SuperAdmin $superAdmin;
    private Package $monthlyPackage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = SuperAdmin::create([
            'name' => 'Terms Super Admin',
            'email' => 'terms+' . uniqid() . '@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->monthlyPackage = Package::create([
            'name' => 'Terms Test Plan ' . uniqid(),
            'tier' => 'basic',
            'billing_cycle' => 'monthly',
            'price' => 20,
            'price_usd' => 20,
            'discount_percent' => 0,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function asSuperAdmin(): self
    {
        Sanctum::actingAs($this->superAdmin, ['*'], 'super_admin');

        return $this;
    }

    private function base(): BillingTerm
    {
        return BillingTerm::where('months', BillingTerm::BASE_MONTHS)->firstOrFail();
    }

    // ── Monthly exists and is editable ──────────────────────────────────────

    public function test_monthly_is_a_real_billing_term_row(): void
    {
        $monthly = $this->base();

        $this->assertSame(1, $monthly->months);
        $this->assertTrue($monthly->isBase());

        $names = collect($this->asSuperAdmin()->getJson('/api/super-admin/billing-terms')->assertOk()->json('data'))
            ->pluck('name');
        $this->assertContains($monthly->name, $names, 'Monthly must be listed like any other term.');
    }

    /** The headline bug: saving "Monthly" must edit Monthly, not create Monthly1. */
    public function test_submitting_monthly_to_create_updates_the_existing_monthly_record(): void
    {
        $monthly = $this->base();
        $countBefore = BillingTerm::count();

        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Monthly', 'months' => 1, 'discount_percent' => 7.5,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Billing term updated')
            ->assertJsonPath('data.id', $monthly->id);

        $this->assertSame($countBefore, BillingTerm::count(), 'No second term may be created.');
        $this->assertSame('7.50', $monthly->fresh()->discount_percent);
        $this->assertDatabaseMissing('billing_terms', ['name' => 'Monthly1']);
    }

    public function test_submitting_an_existing_yearly_updates_it_instead_of_duplicating(): void
    {
        $yearly = BillingTerm::create(['name' => 'Yearly', 'months' => 12, 'discount_percent' => 20, 'is_active' => true]);

        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Yearly', 'months' => 12, 'discount_percent' => 35,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $yearly->id);

        $this->assertSame(1, BillingTerm::whereRaw('LOWER(name) = ?', ['yearly'])->count());
        $this->assertSame('35.00', $yearly->fresh()->discount_percent);
    }

    /** Case and stray whitespace were two more ways past the old check. */
    public function test_name_match_ignores_case_and_surrounding_whitespace(): void
    {
        $monthly = $this->base();

        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => '  mOnThLy  ', 'months' => 1, 'discount_percent' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $monthly->id);

        $this->assertSame('3.00', $monthly->fresh()->discount_percent);
    }

    public function test_monthly_discount_is_editable_through_update(): void
    {
        $monthly = $this->base();

        $this->asSuperAdmin()
            ->putJson("/api/super-admin/billing-terms/{$monthly->id}", [
                'name' => $monthly->name, 'months' => 1, 'discount_percent' => 12,
            ])
            ->assertOk();

        $this->assertSame('12.00', $monthly->fresh()->discount_percent);
    }

    // ── Duplicate prevention ────────────────────────────────────────────────

    public function test_a_second_term_cannot_reuse_an_existing_name(): void
    {
        BillingTerm::create(['name' => 'Half Year', 'months' => 6, 'discount_percent' => 5, 'is_active' => true]);
        $other = BillingTerm::create(['name' => 'Three Year', 'months' => 36, 'discount_percent' => 30, 'is_active' => true]);

        // Renaming one term onto another's name is the update-side version of
        // the same collision.
        $this->asSuperAdmin()
            ->putJson("/api/super-admin/billing-terms/{$other->id}", [
                'name' => 'Half Year', 'months' => 36, 'discount_percent' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame('Three Year', $other->fresh()->name);
    }

    public function test_a_second_term_cannot_reuse_an_existing_duration(): void
    {
        BillingTerm::create(['name' => 'Yearly', 'months' => 12, 'discount_percent' => 20, 'is_active' => true]);

        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Annual', 'months' => 12, 'discount_percent' => 25,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['months']]);

        $this->assertSame(1, BillingTerm::where('months', 12)->count());
    }

    /** "Monthly1" is only ever typed to dodge a refusal — it has no duration left to take. */
    public function test_a_rival_monthly_cannot_be_created_under_another_name(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Monthly1', 'months' => 1, 'discount_percent' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['months']]);

        $this->assertDatabaseMissing('billing_terms', ['name' => 'Monthly1']);
        $this->assertSame(1, BillingTerm::where('months', 1)->count());
    }

    // ── Base-term guards ────────────────────────────────────────────────────

    public function test_monthly_duration_cannot_be_changed(): void
    {
        $monthly = $this->base();

        $this->asSuperAdmin()
            ->putJson("/api/super-admin/billing-terms/{$monthly->id}", [
                'name' => $monthly->name, 'months' => 6, 'discount_percent' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['months']]);

        $this->assertSame(1, $monthly->fresh()->months);
    }

    public function test_monthly_cannot_be_deleted_or_deactivated(): void
    {
        $monthly = $this->base();

        $this->asSuperAdmin()->deleteJson("/api/super-admin/billing-terms/{$monthly->id}")->assertStatus(422);
        $this->asSuperAdmin()->patchJson("/api/super-admin/billing-terms/{$monthly->id}/toggle")->assertStatus(422);

        $monthly->refresh();
        $this->assertTrue($monthly->exists);
        $this->assertTrue($monthly->is_active);
    }

    // ── Package side-effects ────────────────────────────────────────────────

    /** A companion for Monthly would be a duplicate of the base package itself. */
    public function test_monthly_generates_no_companion_package(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Monthly', 'months' => 1, 'discount_percent' => 10,
            ])
            ->assertOk();

        $this->assertSame(
            0,
            Package::where('monthly_package_id', $this->monthlyPackage->id)
                ->where('billing_term_id', $this->base()->id)
                ->count(),
            'The base package already IS the monthly offering.'
        );
    }

    public function test_monthly_discount_lands_on_the_base_packages(): void
    {
        $monthly = $this->base();

        $this->asSuperAdmin()
            ->putJson("/api/super-admin/billing-terms/{$monthly->id}", [
                'name' => $monthly->name, 'months' => 1, 'discount_percent' => 15,
            ])
            ->assertOk();

        $this->assertSame('15.00', $this->monthlyPackage->fresh()->discount_percent);

        // And back off again — 0 restores the pre-existing behaviour exactly.
        $this->asSuperAdmin()
            ->putJson("/api/super-admin/billing-terms/{$monthly->id}", [
                'name' => $monthly->name, 'months' => 1, 'discount_percent' => 0,
            ])
            ->assertOk();

        $this->assertSame('0.00', $this->monthlyPackage->fresh()->discount_percent);
    }

    /** A multi-month term still behaves exactly as it did before. */
    public function test_a_multi_month_term_still_generates_its_companion_package(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Four Year', 'months' => 48, 'discount_percent' => 50,
            ])
            ->assertStatus(201);

        $term = BillingTerm::where('months', 48)->firstOrFail();
        $companion = Package::where('monthly_package_id', $this->monthlyPackage->id)
            ->where('billing_term_id', $term->id)
            ->firstOrFail();

        $this->assertSame("{$this->monthlyPackage->name} (Four Year)", $companion->name);
        $this->assertEquals(960, (float) $companion->price_usd); // 20 × 48
        $this->assertSame('50.00', $companion->discount_percent);
    }

    /**
     * The register and /payment screens render the Monthly tab's "-N%" badge
     * off the BASE package row (billing_term === null), since Monthly has no
     * companion of its own. Both endpoints must therefore carry the Monthly
     * term's discount on that row — and report it as 0 again once Super Admin
     * clears it, which is what makes the badge hide itself.
     */
    public function test_monthly_discount_is_visible_on_the_base_row_of_both_plan_endpoints(): void
    {
        $monthly = $this->base();

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $this->monthlyPackage->id, 'name' => 'Plans Reader',
            'email' => 'plansreader+' . uniqid() . '@example.test',
            'password' => bcrypt('password'), 'subscription_status' => 'pending_payment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = \App\Models\CompanyAdmin::findOrFail($adminId);

        $baseRowFrom = function (array $rows): array {
            $row = collect($rows)->firstWhere('id', $this->monthlyPackage->id);
            $this->assertNotNull($row, 'The base monthly package must be listed.');
            $this->assertNull($row['billing_term'], 'Monthly must stay the base row, with no billing term of its own.');

            return $row;
        };

        foreach ([15, 0] as $percent) {
            $this->asSuperAdmin()
                ->putJson("/api/super-admin/billing-terms/{$monthly->id}", [
                    'name' => $monthly->name, 'months' => 1, 'discount_percent' => $percent,
                ])->assertOk();

            // What the register screen reads.
            $public = $baseRowFrom($this->getJson('/api/public/packages')->assertOk()->json('data'));
            $this->assertEquals($percent, $public['discount_percent'], "/public/packages must report {$percent}%.");

            // What the /payment screen reads.
            Sanctum::actingAs($admin, ['role:admin'], 'admin');
            $plans = $baseRowFrom($this->getJson('/api/admin/subscription/plans')->assertOk()->json('data'));
            $this->assertEquals($percent, $plans['discount_percent'], "/admin/subscription/plans must report {$percent}%.");
        }
    }

    /** "Preserve existing package/subscription relationships" — nothing is re-pointed. */
    public function test_editing_monthly_does_not_repoint_existing_companion_packages(): void
    {
        $term = BillingTerm::create(['name' => 'Yearly', 'months' => 12, 'discount_percent' => 20, 'is_active' => true]);
        \App\Services\YearlyPackageSync::syncAll();

        $companion = Package::where('monthly_package_id', $this->monthlyPackage->id)
            ->where('billing_term_id', $term->id)
            ->firstOrFail();

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $companion->id, 'name' => 'Yearly Admin',
            'email' => 'yearlyadmin+' . uniqid() . '@example.test',
            'password' => bcrypt('password'), 'subscription_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asSuperAdmin()
            ->postJson('/api/super-admin/billing-terms', [
                'name' => 'Monthly', 'months' => 1, 'discount_percent' => 5,
            ])
            ->assertOk();

        $this->assertDatabaseHas('company_admins', ['id' => $adminId, 'package_id' => $companion->id]);
        $this->assertDatabaseHas('packages', ['id' => $companion->id, 'billing_term_id' => $term->id]);
    }
}
