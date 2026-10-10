<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Makes Monthly a real, editable Billing Term row and stops duplicate terms
// from ever being created again.
//
// Until now "Monthly" existed only as a hardcoded label on the register /
// payment screens (the base package, billing_term_id = null), and
// BillingTermController refused the name outright via not_in:Monthly,monthly.
// With no way to edit it and no uniqueness anywhere on this table, a Super
// Admin who wanted to change Monthly's discount had no option but to type a
// name that got past that rule — "Monthly1" — which then generated a whole
// parallel set of companion packages. Same for a second "Yearly".
//
// After this migration a term with months = 1 IS the base monthly offering:
// App\Services\YearlyPackageSync never builds a companion package for it
// (that would duplicate the base package itself) and instead writes its
// discount onto the base monthly packages.
return new class extends Migration
{
    public function up(): void
    {
        // Leading/trailing whitespace is the other way two "same" names slip
        // past a uniqueness check — normalize before indexing.
        DB::statement('UPDATE billing_terms SET name = TRIM(name)');

        // Any pre-existing duplicates (the exact mess this migration ends)
        // would make the unique index below un-creatable. They are NOT merged:
        // packages.billing_term_id and the company_admins sitting on those
        // companion packages must keep pointing where they already point.
        // The oldest row of each name keeps the name; the rest are suffixed
        // with their id and deactivated, so Super Admin can see what happened
        // and retire them deliberately. Deactivating only hides a term's
        // companion packages (YearlyPackageSync) — no subscription is touched.
        $duplicateNames = DB::table('billing_terms')
            ->selectRaw('LOWER(name) as normalized, MIN(id) as keeper')
            ->groupBy('normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateNames as $duplicate) {
            DB::table('billing_terms')
                ->whereRaw('LOWER(name) = ?', [$duplicate->normalized])
                ->where('id', '>', $duplicate->keeper)
                ->orderBy('id')
                ->get(['id', 'name'])
                ->each(function ($term) {
                    DB::table('billing_terms')->where('id', $term->id)->update([
                        'name'       => mb_substr($term->name, 0, 90) . ' #' . $term->id,
                        'is_active'  => false,
                        'updated_at' => now(),
                    ]);
                });
        }

        // Monthly, as a row. Skipped when this database already has a
        // months = 1 term under some other name (that one is already the base
        // term) or a row literally called Monthly.
        $hasBaseTerm = DB::table('billing_terms')->where('months', 1)->exists()
            || DB::table('billing_terms')->whereRaw('LOWER(name) = ?', ['monthly'])->exists();

        if (! $hasBaseTerm) {
            // Seeded from whatever the base monthly packages already carry
            // rather than a flat 0, so a discount somebody had set by hand on
            // every monthly package survives becoming term-driven. Mixed
            // values can't be represented by one term — those fall back to 0
            // and stay editable from the Billing Terms screen.
            $existingDiscounts = DB::table('packages')
                ->whereNull('monthly_package_id')
                ->where('billing_cycle', 'monthly')
                ->pluck('discount_percent')
                ->map(fn ($value) => (float) $value)
                ->unique();

            DB::table('billing_terms')->insert([
                'name'             => 'Monthly',
                'months'           => 1,
                'discount_percent' => $existingDiscounts->count() === 1 ? $existingDiscounts->first() : 0,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        // The real guard. billing_terms uses utf8mb4_unicode_ci, a
        // case-insensitive PAD SPACE collation, so this one index rejects
        // "yearly", "YEARLY" and "Yearly " as duplicates of "Yearly" without
        // any extra normalization. Duplicate `months` is a softer problem (two
        // differently-named 12-month terms collide semantically, not in SQL)
        // and is enforced in BillingTermController's validation instead — a
        // unique index there could make this migration un-runnable on data it
        // cannot safely repair.
        if (! Schema::hasIndex('billing_terms', 'billing_terms_name_unique')) {
            Schema::table('billing_terms', function (Blueprint $table) {
                $table->unique('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('billing_terms', 'billing_terms_name_unique')) {
            Schema::table('billing_terms', function (Blueprint $table) {
                $table->dropUnique('billing_terms_name_unique');
            });
        }

        // Only the untouched seeded row goes away — if anyone is already on a
        // companion of it, or its discount was edited, it stays. packages.
        // billing_term_id is nullOnDelete, and base monthly packages carry
        // null there anyway, so no package loses its term.
        DB::table('billing_terms')
            ->where('name', 'Monthly')
            ->where('months', 1)
            ->where('discount_percent', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('packages')
                    ->whereColumn('packages.billing_term_id', 'billing_terms.id');
            })
            ->delete();
    }
};
