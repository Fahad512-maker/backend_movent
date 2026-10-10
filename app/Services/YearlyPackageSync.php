<?php

namespace App\Services;

use App\Models\BillingTerm;
use App\Models\Package;
use Illuminate\Support\Str;

// Keeps every monthly Package's multi-year companion rows in sync with
// whatever BillingTerm rows the Super Admin has defined (Yearly, 2-Year,
// 5-Year, or anything else — see billing_terms migration). One companion
// per active MULTI-month term per monthly package, priced at monthly price ×
// term months, discounted by that term's discount_percent.
//
// The months = 1 term (Monthly) is handled differently on purpose: the base
// monthly Package is already that term's offering, so generating a companion
// for it would produce a second "Starter (Monthly)" row duplicating
// "Starter". Its discount is written onto the base package instead, which is
// what makes Monthly's discount editable from the Billing Terms screen at all
// — the Packages screen hard-codes discount_percent: 0 for monthly packages
// and never offers the field, so this is the only writer of that value.
class YearlyPackageSync
{
    // Re-derives every companion from its current monthly parent — call
    // after any BillingTerm changes (created/edited/deleted/(de)activated),
    // since every affected companion needs to move together.
    public static function syncAll(): void
    {
        $terms = BillingTerm::where('is_active', true)->get();

        Package::whereNull('monthly_package_id')
            ->where('billing_cycle', 'monthly')
            ->get()
            ->each(fn (Package $monthly) => static::syncOne($monthly, $terms));
    }

    // Call after creating/editing a monthly package (price, features,
    // modules, active/visible state) so its companions never drift out of
    // sync with what they're mirroring. $terms lets syncAll() avoid
    // re-querying BillingTerm for every monthly package.
    public static function syncOne(Package $monthly, ?\Illuminate\Support\Collection $terms = null): void
    {
        $terms ??= BillingTerm::where('is_active', true)->get();
        $moduleKeys = $monthly->modules->pluck('module_key');
        $keepIds = [];

        // The base monthly package carries the Monthly term's discount
        // directly. No active Monthly term (deleted on an old database, or
        // deactivated) means no monthly discount — 0, exactly as before this
        // term existed. Written unconditionally so PackageController::update(),
        // which posts discount_percent: 0 for every monthly package, can't
        // leave the value drifted after an unrelated package edit — that
        // controller calls syncOne() right after saving.
        $baseTerm = $terms->firstWhere('months', BillingTerm::BASE_MONTHS);
        $baseDiscount = $baseTerm ? (float) $baseTerm->discount_percent : 0.0;
        if ((float) $monthly->discount_percent !== $baseDiscount) {
            $monthly->update(['discount_percent' => $baseDiscount]);
        }

        foreach ($terms as $term) {
            // See the class comment — the base package IS this term's row.
            if ($term->months <= BillingTerm::BASE_MONTHS) {
                continue;
            }

            $price = round((float) $monthly->price_usd * $term->months, 2);

            $companion = Package::updateOrCreate(
                ['monthly_package_id' => $monthly->id, 'billing_term_id' => $term->id],
                [
                    'name'                  => $monthly->name . ' (' . $term->name . ')',
                    'tier'                  => $monthly->tier,
                    'billing_cycle'         => Str::slug($term->name, '_'), // display/back-compat only
                    'price'                 => $price,
                    'price_usd'             => $price,
                    'trial_days'            => $monthly->trial_days,
                    'discount_percent'      => $term->discount_percent,
                    'max_companies'         => $monthly->max_companies,
                    'max_users_per_company' => $monthly->max_users_per_company,
                    'description'           => $monthly->description,
                    'features'              => $monthly->features,
                    'is_active'             => $monthly->is_active,
                    'is_visible'            => $monthly->is_visible,
                    // Never independently "popular" — that badge follows
                    // whichever tier the monthly parent is, not a second
                    // entry for the same tier.
                    'is_popular'            => false,
                ]
            );
            $keepIds[] = $companion->id;

            $companion->modules()->delete();
            if ($moduleKeys->isNotEmpty()) {
                $companion->modules()->createMany(
                    $moduleKeys->map(fn ($key) => ['module_key' => $key, 'is_enabled' => true])->toArray()
                );
            }
        }

        // Retire companions for terms that were deleted or deactivated since
        // the last sync (simply excluded from $terms above). company_admins.
        // package_id CASCADE DELETEs the admin's entire account when its
        // package row is deleted — so a companion any real admin is on is
        // NEVER deleted here, only hidden (is_active/is_visible false), same
        // as retiring a BillingTerm itself. Only a companion nobody is on
        // gets fully cleaned up.
        Package::where('monthly_package_id', $monthly->id)
            ->whereNotIn('id', $keepIds)
            ->withCount('companyAdmins')
            ->get()
            ->each(function (Package $orphan) {
                if ($orphan->company_admins_count > 0) {
                    $orphan->update(['is_active' => false, 'is_visible' => false]);
                    return;
                }
                $orphan->modules()->delete();
                $orphan->delete();
            });
    }

    // Deletes every companion of a monthly package that's itself being
    // deleted. Same CASCADE DELETE hazard as above — a companion with any
    // company_admins on it is retired (hidden) instead of deleted so their
    // account survives; PackageController::destroy() already refuses to
    // delete the monthly parent itself while it (or a companion) has admins,
    // so this only ever runs for a package with none.
    public static function deleteCompanions(Package $monthly): void
    {
        Package::where('monthly_package_id', $monthly->id)
            ->withCount('companyAdmins')
            ->get()
            ->each(function (Package $companion) {
                if ($companion->company_admins_count > 0) {
                    $companion->update(['is_active' => false, 'is_visible' => false]);
                    return;
                }
                $companion->modules()->delete();
                $companion->delete();
            });
    }
}
