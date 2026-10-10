<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\BillingTerm;
use App\Models\CompanyAdmin;
use App\Services\YearlyPackageSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Super Admin CRUD for billing options — Monthly, Yearly, 2-Year, or any
// other duration. App\Services\YearlyPackageSync auto-generates and syncs a
// companion Package per active multi-month term per monthly package; the
// months = 1 term (Monthly) is the base package itself and gets no companion,
// only its discount.
//
// Every name here is treated as the identity of a term, not just a label:
// submitting "Monthly" or "Yearly" on the create form updates the row that
// already goes by that name instead of adding a second one. Before this, the
// only protection was not_in:Monthly,monthly on the name — which blocked the
// legitimate edit outright while still letting "Monthly1", "MONTHLY" and a
// second "Yearly" straight through, each one spawning its own parallel set of
// companion packages.
class BillingTermController extends Controller
{
    private const MAX_MONTHS = 1200;

    public function index(): JsonResponse
    {
        return ApiResponse::success(BillingTerm::orderBy('months')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);

        // The fix for "Monthly1": a name that already exists is an edit of
        // that term, not a new one. Matched case-insensitively so "monthly"
        // and "MONTHLY" resolve to the same row too.
        $existing = BillingTerm::whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])->first();

        if ($existing) {
            return $this->persist($validated, $existing, 'Billing term updated');
        }

        return $this->persist($validated, null, 'Billing term created', 201);
    }

    public function update(Request $request, BillingTerm $billingTerm): JsonResponse
    {
        return $this->persist($this->validatedPayload($request), $billingTerm, 'Billing term updated');
    }

    public function destroy(BillingTerm $billingTerm): JsonResponse
    {
        if ($billingTerm->isBase()) {
            return ApiResponse::error(
                'Monthly is the base billing term and cannot be deleted. Set its discount to 0 instead.',
                422
            );
        }

        // Same CASCADE DELETE hazard as deleting a package directly — a
        // companion package any admin is on must never be hard-deleted.
        $companionIds = $billingTerm->packages()->pluck('id');
        $adminsOnTerm = $companionIds->isNotEmpty()
            ? CompanyAdmin::whereIn('package_id', $companionIds)->count()
            : 0;
        if ($adminsOnTerm > 0) {
            return ApiResponse::error("Cannot delete — {$adminsOnTerm} compan" . ($adminsOnTerm === 1 ? 'y is' : 'ies are') . ' currently on this term. Deactivate it instead.', 422);
        }

        DB::transaction(function () use ($billingTerm) {
            $billingTerm->delete();
            // The term's companion Package rows are now orphaned (no
            // BillingTerm to belong to) — syncAll() cleans them up since
            // they're excluded from the (now term-less) active set for every
            // monthly package.
            YearlyPackageSync::syncAll();
        });

        return ApiResponse::success(null, 'Billing term deleted');
    }

    public function toggle(BillingTerm $billingTerm): JsonResponse
    {
        // Monthly is always on offer — it's the base package, not an upgrade
        // that can be withdrawn. Deactivating it would only silently drop its
        // discount while every monthly plan stayed purchasable.
        if ($billingTerm->isBase()) {
            return ApiResponse::error(
                'Monthly is the base billing term and is always available. Set its discount to 0 to remove the monthly discount.',
                422
            );
        }

        DB::transaction(function () use ($billingTerm) {
            $billingTerm->update(['is_active' => !$billingTerm->is_active]);
            YearlyPackageSync::syncAll();
        });

        return ApiResponse::success(['is_active' => $billingTerm->is_active]);
    }

    private function validatedPayload(Request $request): array
    {
        // Trailing whitespace is one of the ways a "new" term used to sneak
        // past as a duplicate — normalize before anything compares names.
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        return $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'months'           => ['required', 'integer', 'min:1', 'max:' . self::MAX_MONTHS],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active'        => ['boolean'],
        ]);
    }

    // Shared create/update path, so both routes enforce the same duplicate
    // and base-term rules. $term is null for a genuinely new term.
    private function persist(array $validated, ?BillingTerm $term, string $message, int $status = 200): JsonResponse
    {
        $isBase = $term?->isBase() ?? false;

        // Monthly's length defines what "base term" means — YearlyPackageSync
        // keys off months = 1 to decide a term has no companion package. Let
        // it be re-dated and every monthly plan would suddenly grow a
        // duplicate companion row.
        if ($isBase && (int) $validated['months'] !== BillingTerm::BASE_MONTHS) {
            return ApiResponse::error('Monthly is the base billing term — its duration is fixed at 1 month.', 422, [
                'months' => ['Monthly is the base billing term — its duration is fixed at 1 month.'],
            ]);
        }

        $nameClash = BillingTerm::whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])
            ->when($term, fn ($query) => $query->whereKeyNot($term->getKey()))
            ->first();

        if ($nameClash) {
            return ApiResponse::error("A billing term named \"{$nameClash->name}\" already exists.", 422, [
                'name' => ["A billing term named \"{$nameClash->name}\" already exists."],
            ]);
        }

        // One term per duration. Two 12-month terms under different names
        // ("Yearly" and "Annual") aren't a SQL collision, but they do generate
        // two companion packages per plan and show up as two identical-looking
        // buttons on the register/payment screens. This is also what stops a
        // second months = 1 term from becoming a rival Monthly.
        $monthsClash = BillingTerm::where('months', $validated['months'])
            ->when($term, fn ($query) => $query->whereKeyNot($term->getKey()))
            ->first();

        if ($monthsClash) {
            $duration = $validated['months'] === 1 ? '1 month' : "{$validated['months']} months";
            $detail = $monthsClash->isBase()
                ? "\"{$monthsClash->name}\" is already the {$duration} term — edit it instead of adding another."
                : "\"{$monthsClash->name}\" already covers {$duration}. Edit that term instead of adding a duplicate.";

            return ApiResponse::error($detail, 422, ['months' => [$detail]]);
        }

        // Monthly can never be switched off (see toggle()) — a payload that
        // says otherwise is corrected rather than refused, since is_active
        // isn't a field the Billing Terms form actually offers.
        $isActive = $isBase ? true : ($validated['is_active'] ?? $term?->is_active ?? true);

        $attributes = [
            'name'             => $validated['name'],
            'months'           => $validated['months'],
            'discount_percent' => $validated['discount_percent'],
            'is_active'        => $isActive,
        ];

        // Wrapped so a mid-sync failure can't leave a Billing Term committed
        // with its companion packages only half-generated.
        $saved = DB::transaction(function () use ($attributes, $term) {
            if ($term) {
                $term->update($attributes);
            } else {
                $term = BillingTerm::create($attributes);
            }

            YearlyPackageSync::syncAll();

            return $term->fresh();
        });

        return ApiResponse::success($saved, $message, $status);
    }
}
