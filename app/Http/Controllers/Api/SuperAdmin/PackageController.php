<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Package;
use App\Services\YearlyPackageSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    // A package must be built from granular module keys (e.g. "leads", "invoices"),
    // never the coarse category keys (e.g. "sales") shown in the Modules admin UI —
    // the registration/middleware module checks only ever match granular keys.
    private function invalidModuleKeys(array $keys): array
    {
        $validKeys = Module::query()->pluck('sub_modules')->flatten()->unique()->all();

        return array_values(array_diff($keys, $validKeys));
    }

    // Excludes system-generated yearly companions (see YearlyPackageSync) —
    // this list is for the packages a Super Admin actually builds/manages;
    // the yearly side of each is derived automatically from Subscription
    // Policy's Yearly Discount % and shown to Admins at checkout, not managed
    // here as its own row.
    public function index(): JsonResponse
    {
        $packages = Package::with('modules')
            ->withCount('companyAdmins')
            ->whereNull('monthly_package_id')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($packages);
    }

    public function show(Package $package): JsonResponse
    {
        return ApiResponse::success($package->load('modules'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'tier'                   => ['required', 'in:basic,professional,enterprise,custom'],
            'price'                  => ['required', 'numeric', 'min:0'],
            // price_pkr is deliberately NOT accepted here — USD is the
            // system's only supported currency now. Existing packages keep
            // whatever value that column already has, untouched.
            'price_usd'              => ['nullable', 'numeric', 'min:0'],
            'trial_days'             => ['nullable', 'integer', 'min:0'],
            'discount_percent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_companies'          => ['nullable', 'integer', 'min:1'],
            'max_users_per_company'  => ['nullable', 'integer', 'min:1'],
            'description'            => ['nullable', 'string'],
            'is_visible'             => ['boolean'],
            'is_popular'             => ['boolean'],
            'modules'                => ['array'],
            'modules.*'              => ['string'],
        ]);

        $invalidKeys = $this->invalidModuleKeys($validated['modules'] ?? []);
        if (!empty($invalidKeys)) {
            return ApiResponse::error('Invalid module keys.', 422, [
                'modules' => ['Unknown module key(s): ' . implode(', ', $invalidKeys)],
            ]);
        }

        // Wrapped so a mid-sync failure can't leave a package committed with
        // its multi-year companions only half-generated.
        $package = DB::transaction(function () use ($validated) {
            $package = Package::create([
                'name'                  => $validated['name'],
                'tier'                  => $validated['tier'],
                'price'                 => $validated['price'],
                'price_usd'             => $validated['price_usd'] ?? null,
                // Always monthly — every multi-year companion is derived
                // automatically from this row by YearlyPackageSync, priced
                // via each active Billing Term's discount %, never hand-built here.
                'billing_cycle'         => 'monthly',
                'trial_days'            => $validated['trial_days'] ?? 14,
                'discount_percent'      => $validated['discount_percent'] ?? 0,
                'max_companies'         => $validated['max_companies'] ?? null,
                'max_users_per_company' => $validated['max_users_per_company'] ?? null,
                'description'           => $validated['description'] ?? null,
                'is_active'             => true,
                'is_visible'            => $validated['is_visible'] ?? true,
                'is_popular'            => $validated['is_popular'] ?? false,
            ]);

            if (!empty($validated['modules'])) {
                $package->modules()->createMany(
                    collect($validated['modules'])->map(fn($key) => [
                        'module_key' => $key,
                        'is_enabled' => true,
                    ])->toArray()
                );
            }

            YearlyPackageSync::syncOne($package->fresh('modules'));
            return $package;
        });

        return ApiResponse::success($package->load('modules'), 'Package created', 201);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        if ($package->isCompanion()) {
            return ApiResponse::error('This is an auto-generated Yearly/2-Year/3-Year package — edit its monthly package instead, or change the discount percentages in Subscription Policy.', 422);
        }

        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'tier'                   => ['required', 'in:basic,professional,enterprise,custom'],
            'price'                  => ['required', 'numeric', 'min:0'],
            // price_pkr is deliberately NOT accepted here — USD is the
            // system's only supported currency now. Existing packages keep
            // whatever value that column already has, untouched.
            'price_usd'              => ['nullable', 'numeric', 'min:0'],
            'trial_days'             => ['nullable', 'integer', 'min:0'],
            'discount_percent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_companies'          => ['nullable', 'integer', 'min:1'],
            'max_users_per_company'  => ['nullable', 'integer', 'min:1'],
            'description'            => ['nullable', 'string'],
            'is_visible'             => ['boolean'],
            'is_popular'             => ['boolean'],
            'modules'                => ['array'],
            'modules.*'              => ['string'],
        ]);

        $invalidKeys = $this->invalidModuleKeys($validated['modules'] ?? []);
        if (!empty($invalidKeys)) {
            return ApiResponse::error('Invalid module keys.', 422, [
                'modules' => ['Unknown module key(s): ' . implode(', ', $invalidKeys)],
            ]);
        }

        DB::transaction(function () use ($validated, $package) {
            $package->update([
                'name'                  => $validated['name'],
                'tier'                  => $validated['tier'],
                'price'                 => $validated['price'],
                'price_usd'             => $validated['price_usd'] ?? null,
                'trial_days'            => $validated['trial_days'] ?? $package->trial_days,
                'discount_percent'      => $validated['discount_percent'] ?? $package->discount_percent,
                'max_companies'         => $validated['max_companies'] ?? null,
                'max_users_per_company' => $validated['max_users_per_company'] ?? null,
                'description'           => $validated['description'] ?? null,
                'is_visible'            => $validated['is_visible'] ?? $package->is_visible,
                'is_popular'            => $validated['is_popular'] ?? $package->is_popular,
            ]);

            // Sync modules
            $package->modules()->delete();
            if (!empty($validated['modules'])) {
                $package->modules()->createMany(
                    collect($validated['modules'])->map(fn($key) => [
                        'module_key' => $key,
                        'is_enabled' => true,
                    ])->toArray()
                );
            }

            YearlyPackageSync::syncOne($package->fresh('modules'));
        });

        return ApiResponse::success($package->load('modules'), 'Package updated');
    }

    public function destroy(Package $package): JsonResponse
    {
        if ($package->isCompanion()) {
            return ApiResponse::error('This is an auto-generated Yearly/2-Year/3-Year package — delete its monthly package instead.', 422);
        }

        // company_admins.package_id CASCADE DELETEs the admin's entire
        // account when its package row is deleted — refuse rather than take
        // out real companies with it. Checks both this row and every
        // multi-year companion, since an admin could be on any of them.
        $companionIds = $package->companions()->pluck('id');
        $adminsOnThis = $package->companyAdmins()->count();
        $adminsOnCompanions = $companionIds->isNotEmpty()
            ? \App\Models\CompanyAdmin::whereIn('package_id', $companionIds)->count()
            : 0;
        if ($adminsOnThis + $adminsOnCompanions > 0) {
            return ApiResponse::error('Cannot delete — ' . ($adminsOnThis + $adminsOnCompanions) . ' compan' . (($adminsOnThis + $adminsOnCompanions) === 1 ? 'y is' : 'ies are') . ' currently on this package or one of its multi-year options. Deactivate it instead.', 422);
        }

        DB::transaction(function () use ($package) {
            YearlyPackageSync::deleteCompanions($package);
            $package->modules()->delete();
            $package->delete();
        });
        return ApiResponse::success(null, 'Package deleted');
    }

    public function toggle(Package $package): JsonResponse
    {
        if ($package->isCompanion()) {
            return ApiResponse::error('This is an auto-generated Yearly/2-Year/3-Year package — toggle its monthly package instead.', 422);
        }

        DB::transaction(function () use ($package) {
            $package->update(['is_active' => !$package->is_active]);
            YearlyPackageSync::syncOne($package->fresh('modules'));
        });
        return ApiResponse::success(['is_active' => $package->is_active]);
    }
}
