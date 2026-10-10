<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Mail\WelcomeMail;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\Module;
use App\Models\Package;
use App\Models\PaymentGateway;
use App\Rules\ValidPhoneNumber;
use App\Services\ModuleDependency;
use App\Support\CompanyName;
use App\Support\CrossAccountEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class PublicController extends Controller
{
    public function packages(): JsonResponse
    {
        // A package's own module list (package_modules) never expires or
        // syncs itself when Super Admin flips a Module's is_active off — it's
        // a separate table, set once when the package was built. Without
        // this filter, a globally-deactivated module still showed up (and
        // was still selectable) for anyone registering via a pre-built
        // package, even though the "build your own plan" flow (modules()
        // below) already correctly hid it. Module.key is a top-level catalog
        // key ('hr', 'finance') while package_modules.module_key is a
        // granular sub-module key ('employees', 'finance_dashboard') — the
        // two are different namespaces, so this checks each inactive
        // Module's sub_modules array, same pattern as
        // App\Http\Middleware\CheckCompanyModule.
        $disabledSubModuleKeys = Module::where('is_active', false)
            ->pluck('sub_modules')
            ->flatten()
            ->unique()
            ->all();

        // Every package shows the same trial length here — the Super Admin's
        // global Subscription Policy, not Package::trial_days (legacy column,
        // no longer read anywhere else — see register() below). 0 when
        // trials are switched off company-wide, so the frontend badge hides.
        $policy = \App\Models\SubscriptionPolicy::current();
        $effectiveTrialDays = $policy->trial_enabled ? $policy->trial_duration_days : 0;

        // Includes both monthly packages and their auto-generated multi-year
        // companions, one per active Billing Term (see YearlyPackageSync) —
        // the register page lets the user pick a billing term up front and
        // shows its discount immediately, rather than deferring that choice
        // to checkout.
        $packages = Package::with(['modules', 'billingTerm'])
            ->where('is_visible', true)
            ->where('is_active', true)
            ->orderBy('price_usd')
            ->get()
            ->map(fn($pkg) => [
                'id'               => $pkg->id,
                'name'             => $pkg->name,
                'tier'             => $pkg->tier,
                'billing_cycle'    => $pkg->billing_cycle,
                // Null for the base monthly package — every other row is a
                // Billing Term companion. months drives price/period math on
                // the frontend without it needing to know term names in advance.
                'billing_term'     => $pkg->billingTerm ? [
                    'id'     => $pkg->billingTerm->id,
                    'name'   => $pkg->billingTerm->name,
                    'months' => $pkg->billingTerm->months,
                ] : null,
                'discount_percent' => (float) $pkg->discount_percent,
                'price_pkr'        => (float) $pkg->price_pkr,
                'price_usd'        => (float) $pkg->price_usd,
                'trial_days'       => $effectiveTrialDays,
                'is_popular'       => (bool) $pkg->is_popular,
                'features'         => $pkg->features ?? [],
                'modules'          => array_values(array_diff($pkg->modules->pluck('module_key')->toArray(), $disabledSubModuleKeys)),
            ]);

        return ApiResponse::success($packages);
    }

    // GET /public/modules — active modules for the "build your own plan" registration flow
    public function modules(): JsonResponse
    {
        $modules = Module::where('is_active', true)
            ->orderBy('label')
            ->get(['key', 'label', 'description', 'sub_modules', 'price_pkr', 'price_usd']);

        return ApiResponse::success($modules);
    }

    // GET /public/payment-gateways — active platform gateways for registration payment
    public function activeGateways(): JsonResponse
    {
        $gateways = PaymentGateway::where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'is_active']);

        if ($gateways->isEmpty()) {
            return ApiResponse::success([], 'No payment gateways configured');
        }

        return ApiResponse::success($gateways);
    }

    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $available = !CompanyAdmin::where('email', $request->email)->exists()
            && !CrossAccountEmail::existsAsUser($request->email);

        return ApiResponse::success(['available' => $available]);
    }

    public function checkCompanyName(Request $request): JsonResponse
    {
        $request->validate(['company_name' => ['required', 'string']]);

        // New signup — no admin account exists yet, so there's nothing of
        // this (not-yet-created) admin's own to collide with. Name
        // uniqueness is scoped per admin, not global; see CompanyName.
        $available = !CompanyName::exists($request->company_name, null);

        return ApiResponse::success(['available' => $available]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name'    => ['required', 'string', 'max:200'],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => [
                'required', 'email', 'unique:company_admins,email',
                function ($attribute, $value, $fail) {
                    if (CrossAccountEmail::existsAsUser($value)) {
                        $fail('This email is already registered as a staff or client account.');
                    }
                },
            ],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
            'phone'           => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            // Both optional since the register screen creates the account
            // FIRST and only then opens the Packages / Custom Modules step —
            // see the provisional-package resolution below. A caller that
            // already knows the plan (the trial path, or any existing
            // integration posting the old full payload) still has it honored
            // exactly as before.
            'package_id'      => ['nullable', 'exists:packages,id'],
            'selected_modules'=> ['nullable', 'array', 'min:1'],
            'selected_modules.*' => ['string'],
            'currency'        => ['required', 'in:USD'],
            'start_type'      => ['required', 'in:trial,paid'],
            // True only for the "Build Your Own Plan" flow, where the user
            // hand-picked individual modules — that explicit choice is always
            // honored. False/omitted means they just picked a pre-built
            // package (Starter/Business/…), which only matters for pricing
            // once the trial ends; see the full-catalog trial grant below.
            'is_custom_selection' => ['nullable', 'boolean'],
            'timezone'        => ['nullable', 'string', 'max:100'],
            'country'         => ['nullable', 'string', 'max:5'],
            'max_users'       => ['nullable', 'integer', 'min:1'],
            'max_companies'   => ['nullable', 'integer', 'min:1'],
        ]);

        $validated['company_name'] = CompanyName::normalize($validated['company_name']);
        // New admin, no existing companies yet — nothing to collide with (see checkCompanyName above).
        CompanyName::throwIfTaken($validated['company_name'], 'company_name', null);

        // company_admins.package_id is NOT NULL (and CASCADE DELETEs the whole
        // account with its package), so an admin always has to be created
        // against a real package — even though the register screen now defers
        // the actual plan choice until after the account exists. The cheapest
        // base monthly plan stands in until then; the Packages / Custom
        // Modules step replaces it via
        // Admin\SubscriptionPaymentController::selectPlan(), and payment
        // replaces it again via process(). Without this an abandoned sign-up
        // would have no package for the "Complete Payment" resume flow's
        // order summary to price.
        $package = !empty($validated['package_id'])
            ? Package::with('modules')->findOrFail($validated['package_id'])
            : Package::with('modules')
                ->where('is_active', true)
                ->where('is_visible', true)
                ->whereNull('billing_term_id')
                ->orderBy('price_usd')
                ->first();

        if (!$package) {
            return ApiResponse::error('No subscription plans are available right now. Please try again later.', 422);
        }

        // Whether this request actually chose anything, as opposed to being
        // step 1 of the register screen (account first, plan after).
        $hasSelection = !empty($validated['selected_modules']);
        $validated['selected_modules'] ??= [];

        // Determine subscription dates — trial duration always comes from the
        // Super Admin's global Subscription Policy, the single source of
        // truth for every new sign-up regardless of package (Package::trial_days
        // is legacy marketing copy only, see packages() below — it no longer
        // affects the actual trial granted). trial_enabled is the master
        // on/off switch: if Super Admin has turned trials off company-wide,
        // even a start_type=trial request goes straight to pending_payment.
        $policy = \App\Models\SubscriptionPolicy::current();
        $trialEndsAt = $subEndsAt = null;
        $status = 'pending_payment';

        if ($validated['start_type'] === 'trial' && $policy->trial_enabled) {
            $status      = 'trial';
            $trialEndsAt = now()->addDays($policy->trial_duration_days);
        }

        $isCustomSelection = (bool) ($validated['is_custom_selection'] ?? false);
        $disabledSubModuleKeys = Module::where('is_active', false)->pluck('sub_modules')->flatten()->unique()->all();

        if ($status === 'trial' && !$isCustomSelection) {
            // Full trial, every module — picking a package during a trial
            // only decides what you'll be billed for once the trial converts,
            // not what you get to try. Only an explicit "Build Your Own Plan"
            // selection narrows this.
            $validated['selected_modules'] = Module::where('is_active', true)
                ->pluck('sub_modules')
                ->flatten()
                ->unique()
                ->values()
                ->all();
        } elseif ($hasSelection) {
            // Strip any module keys not in the package (prevent injection of unowned modules).
            // If the package has no modules defined (e.g. admin-created custom package),
            // skip the filter and save whatever the user selected.
            $packageModuleKeys = $package->modules->pluck('module_key')->toArray();
            if (!$isCustomSelection && !empty($packageModuleKeys)) {
                $validated['selected_modules'] = array_values(array_intersect(
                    $validated['selected_modules'],
                    $packageModuleKeys
                ));
            }

            // Also strip any module Super Admin has since globally deactivated —
            // packages() (the display side) already hides these, but a direct
            // API call could still submit one from a stale page or by hand. Same
            // sub_modules-array check as packages() above (harmless no-op for
            // the "build your own" flow, where selected_modules only ever came
            // from modules(), already is_active-filtered).
            $validated['selected_modules'] = array_values(array_diff($validated['selected_modules'], $disabledSubModuleKeys));
            $validated['selected_modules'] = array_values(array_unique($validated['selected_modules']));
        }

        // Both checks apply only to a set somebody actually chose. Step 1 of
        // the register screen sends none at all and gets no modules enabled —
        // the plan step turns them on a moment later via
        // Admin\SubscriptionPaymentController::selectPlan(), and payment
        // re-derives them again. Falling back to the stand-in package's own
        // modules here instead would be worse than useless: that set was never
        // chosen by anyone, and if it happens to be dependency-invalid (a
        // Sales-only plan, say — Sales requires Invoice) it would fail the
        // sign-up with a module error before any plan had even been shown.
        if ($hasSelection || $status === 'trial') {
            if (empty($validated['selected_modules'])) {
                return ApiResponse::error('Please select at least one valid module.', 422, [
                    'selected_modules' => ['Please select at least one valid module.'],
                ]);
            }

            $dependencyErrors = ModuleDependency::errors($validated['selected_modules']);
            if (!empty($dependencyErrors)) {
                return ApiResponse::error('Module dependencies are not valid.', 422, [
                    'selected_modules' => $dependencyErrors,
                ]);
            }
        }

        // Create company admin
        $admin = CompanyAdmin::create([
            'name'                   => $validated['name'],
            'email'                  => $validated['email'],
            'password'               => Hash::make($validated['password']),
            'phone'                  => $validated['phone'] ?? null,
            'package_id'             => $package->id,
            'is_custom_selection'    => $isCustomSelection,
            'subscription_status'    => $status,
            'trial_ends_at'          => $trialEndsAt,
            'subscription_ends_at'   => $subEndsAt,
            'is_active'              => true,
            // Seats/companies picked at checkout — takes precedence over the
            // shared Package's defaults (see UserController/ClientController
            // limit checks). Null here means "unlimited", same as the Package.
            'max_users_per_company'  => $validated['max_users'] ?? null,
            'max_companies'          => $validated['max_companies'] ?? null,
            // Explicit, not left to the column default — this is the
            // tenant-level currency Company::invoicingProfile() treats as
            // authoritative for every invoice. Always 'USD' (validated
            // above), same as the Company row created just below.
            'currency'               => $validated['currency'],
        ]);

        // Create company
        $company = Company::create([
            'admin_id'       => $admin->id,
            'name'           => $validated['company_name'],
            // USA is the primary target market — default here if the
            // frontend's timezone selector somehow didn't send one.
            'timezone'       => $validated['timezone'] ?? 'America/New_York',
            'currency'       => $validated['currency'],
            'country'        => $validated['country'] ?? null,
            'storage_folder' => 'companies/0/', // temp, updated below
            'is_active'      => true,
        ]);

        // Update storage folder with real ID and create it
        $storageFolder = 'companies/' . $company->id . '/';
        $company->update(['storage_folder' => $storageFolder]);
        Storage::makeDirectory($storageFolder);

        // Only enable the modules the company actually selected/purchased
        foreach ($validated['selected_modules'] as $moduleKey) {
            $company->modules()->create([
                'module_key' => $moduleKey,
                'is_enabled' => true,
            ]);
        }

        // Send welcome email (non-blocking)
        app()->terminating(function () use ($admin, $company, $trialEndsAt, $package, $policy, $status) {
            try {
                $priceUsd = round((float) $package->price_usd * (1 - (float) $package->discount_percent / 100), 2);
                Mail::to($admin->email)->send(new WelcomeMail($admin, $company, $trialEndsAt, $package, $priceUsd));
            } catch (\Throwable) {
                // Don't fail registration if mail fails.
            }

        // Super Admin's bell: a sign-up is two platform events, not one — a
        // new tenant owner account, and a new company. Both are recorded so
        // the console shows them under their own categories. The service
        // swallows its own failures, so registration can't break on this.
        \App\Services\SuperAdminNotificationService::companyAdminRegistered($admin, $package->name);
        \App\Services\SuperAdminNotificationService::companyRegistered($company, $admin);

        // "Trial Started" — in-app bell + a dedicated event email, on top of
        // (not instead of) the WelcomeMail above, which is registration
        // welcome copy rather than a trial-lifecycle event.
            if ($status === 'trial') {
                try {
                    \App\Services\SubscriptionNotificationService::trialStarted($admin, $policy->trial_duration_days);
                } catch (\Throwable) {
                    // Don't fail registration if this fails.
                }
            }
        });

        // Issue Sanctum token
        $token = $admin->createToken('company-admin-token', ['role:admin'])->plainTextToken;

        // Reload admin with companies + their modules so the frontend cookie is complete from the start
        $admin->load('companies.modules');

        return ApiResponse::success([
            'token'      => $token,
            'admin'      => [
                'id'                  => $admin->id,
                'name'                => $admin->name,
                'email'               => $admin->email,
                'subscription_status' => $admin->subscription_status,
                'companies'           => $admin->companies->map(fn($c) => [
                    'id'        => $c->id,
                    'name'      => $c->name,
                    'is_active' => $c->is_active,
                ]),
                'modules'             => $company->modules
                    ->where('is_enabled', true)
                    ->pluck('module_key')
                    ->values()
                    ->toArray(),
            ],
            'start_type' => $validated['start_type'],
        ], 'Registration successful', 201);
    }

    // A pending_payment account can't complete AdminAuthController::login()
    // (blocked on purpose — see that method), so it has no way to reach the
    // authenticated /admin/subscription-payment/* endpoints. This re-verifies
    // the same credentials and issues a token specifically so the "Complete
    // Payment" link on the login screen can resume checkout — every OTHER
    // admin route stays blocked for that token via the 'subscription.active'
    // middleware (routes/api.php) for as long as payment is still pending.
    public function resumePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = CompanyAdmin::where('email', $validated['email'])->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            return ApiResponse::error('Invalid credentials', 401);
        }

        if (!$admin->is_active) {
            return ApiResponse::error('Your account has been deactivated', 403);
        }

        if ($admin->subscription_status !== 'pending_payment') {
            return ApiResponse::error('Your account is already active — please log in normally.', 422);
        }

        $token = $admin->createToken('admin-resume-payment-token', ['role:admin'])->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'admin' => new AdminResource($admin->load('companies.modules', 'package')),
        ], 'You can now complete your payment');
    }
}
