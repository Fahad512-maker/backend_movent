<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\SubscriptionPayment;
use App\Services\PaymentGatewayCharger;
use App\Services\SubscriptionNotificationService;
use App\Services\SuperAdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SubscriptionPaymentController extends Controller
{
    private PaymentGatewayCharger $charger;

    public function __construct(PaymentGatewayCharger $charger)
    {
        $this->charger = $charger;
    }

    // Never null in practice — every route on this controller is behind
    // auth:admin (see routes/api.php). Typed so the module/plan helpers below
    // can take a CompanyAdmin rather than a bare Authenticatable.
    private function admin(): \App\Models\CompanyAdmin
    {
        return auth('admin')->user();
    }

    private function platformGateway(string $name): PaymentGateway
    {
        return $this->charger->platformGateway($name);
    }

    // Mirrors the register page's SEAT_OPTIONS/COMPANY_OPTIONS surcharge
    // tables (frontend/app/register/page.tsx) — that picker is the only place
    // these seat/company tiers are chosen, so its prices must match exactly.
    // Neither table lived server-side before: orderSummary() reported the
    // bare package price (silently dropping any addon the admin picked at
    // registration) and process() priced a plain renewal the same way, which
    // would have rejected a legitimate addon-inclusive amount as "does not
    // match" the moment anything tried to actually send one.
    private const SEAT_ADDON_USD = [10 => 0.0, 25 => 3.0, 50 => 6.0, 100 => 12.0];
    private const COMPANY_ADDON_USD = [1 => 0.0, 3 => 2.0, 5 => 4.0];

    // CompanyAdmin::max_users_per_company/max_companies null is ambiguous —
    // it means EITHER "picked Unlimited" (SEAT_OPTIONS' null-value tier) OR
    // "never went through this picker at all" (every pre-existing admin,
    // anything provisioned outside the register flow). There's no column
    // distinguishing the two, and defaulting null to the $25/$10 Unlimited
    // surcharge would silently overcharge every admin in the second bucket.
    // Undercharging the rarer genuine-Unlimited case is the safer direction
    // until that's tracked explicitly — so null costs nothing extra here,
    // same as any other value with no matching tier below.
    private function addonUsd(?int $seats, ?int $companies): float
    {
        $seatAddon    = $seats === null ? 0.0 : (self::SEAT_ADDON_USD[$seats] ?? 0.0);
        $companyAddon = $companies === null ? 0.0 : (self::COMPANY_ADDON_USD[$companies] ?? 0.0);
        return $seatAddon + $companyAddon;
    }

    // Replaces every company's enabled module set with exactly $moduleKeys.
    // Shared by selectPlan() and both of process()'s re-derive branches so a
    // plan chosen at registration and the same plan paid for a moment later
    // can't end up applying different rules.
    private function applyModuleSet(\App\Models\CompanyAdmin $admin, array $moduleKeys): void
    {
        foreach ($admin->companies as $company) {
            $ownedBefore = $company->catalogKeys();

            $company->modules()->whereNotIn('module_key', $moduleKeys)->delete();
            foreach ($moduleKeys as $moduleKey) {
                $company->modules()->updateOrCreate(['module_key' => $moduleKey], ['is_enabled' => true]);
            }

            // Existing staff never get a newly-granted module's permissions
            // just by it becoming available — see PermissionBackfillService.
            \App\Services\PermissionBackfillService::grantDefaultsForNewModules(
                $company,
                array_values(array_diff($company->catalogKeys(), $ownedBefore))
            );
        }
    }

    // Integrity check for a hand-picked ("Build Your Own Plan") module set,
    // shared by selectPlan() and by process() — where it runs BEFORE the card
    // is charged, so a bad selection never results in a charge with nothing
    // usable to show for it. $keys is filtered in place (silently dropping
    // anything Super Admin has since deactivated); the returned collection is
    // the fully-covered Modules, which process() also prices off.
    //
    // @return array{0: ?JsonResponse, 1: \Illuminate\Support\Collection}
    private function vetCustomModules(array &$keys): array
    {
        $activeModules = \App\Models\Module::where('is_active', true)->get(['key', 'sub_modules', 'price_usd']);
        $empty = collect();

        $disabledSubModuleKeys = \App\Models\Module::where('is_active', false)
            ->pluck('sub_modules')->flatten()->unique()->all();
        $keys = array_values(array_unique(array_diff($keys, $disabledSubModuleKeys)));

        if (empty($keys)) {
            return [ApiResponse::error('Please select at least one valid module.', 422, [
                'selected_modules' => ['Please select at least one valid module.'],
            ]), $empty];
        }

        $dependencyErrors = \App\Services\ModuleDependency::errors($keys);
        if (!empty($dependencyErrors)) {
            return [ApiResponse::error('Module dependencies are not valid.', 422, [
                'selected_modules' => $dependencyErrors,
            ]), $empty];
        }

        // Every granular key must belong to some active module's sub_modules —
        // catches an unknown/garbage key or one whose parent module Super Admin
        // has since deactivated (on top of the already-active-only filter
        // above), i.e. direct API manipulation.
        $knownGranularKeys = $activeModules->pluck('sub_modules')->flatten()->unique()->values()->all();
        if (!empty(array_diff($keys, $knownGranularKeys))) {
            return [ApiResponse::error('One or more selected modules are not available.', 422), $empty];
        }

        // A module only counts (and is only priced) once EVERY one of its
        // granular sub-modules was submitted — mirrors
        // ModulePurchaseController::catalog()'s "owned category" test, and
        // matches how the picker only ever offers whole categories, never a
        // partial slice of one.
        $coveredModules = $activeModules->filter(
            fn ($m) => !empty($m->sub_modules) && empty(array_diff($m->sub_modules, $keys))
        );
        $coveredGranularKeys = $coveredModules->pluck('sub_modules')->flatten()->unique()->values()->all();

        // Anything submitted that isn't part of a FULLY covered category would
        // let someone pay for less than a whole category while still asking to
        // have it enabled.
        if (!empty(array_diff($keys, $coveredGranularKeys))) {
            return [ApiResponse::error('Selected modules must be chosen as complete categories.', 422), $empty];
        }

        return [null, $coveredModules];
    }

    // POST /api/admin/subscription/select-plan — saves the Packages / Custom
    // Modules choice the register screen now collects AFTER the account has
    // been created, without charging anything.
    //
    // PublicController::register() starts a sign-up on a stand-in package
    // (company_admins.package_id is NOT NULL) precisely so this step can
    // replace it. Persisting the choice here rather than only passing it along
    // to /payment matters: orderSummary() prices off the saved package for the
    // "Complete Payment" resume flow, and process() only re-derives a module
    // set when the package actually changes — for a pending_payment admin
    // whose package was already set here it takes the enableExistingModules()
    // path, which trusts whatever this wrote.
    public function selectPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id'          => 'required|integer|exists:packages,id',
            'selected_modules'    => 'nullable|array|min:1',
            'selected_modules.*'  => 'string',
            'is_custom_selection' => 'nullable|boolean',
            'max_users'           => 'nullable|integer|min:1',
            'max_companies'       => 'nullable|integer|min:1',
        ]);

        $admin = $this->admin();

        // Only a sign-up that has never paid may set its plan for free.
        // Anything else changes plan through process(), which charges for it.
        $hasPaid = SubscriptionPayment::where('admin_id', $admin->id)->where('status', 'paid')->exists();
        if ($admin->subscription_status !== 'pending_payment' || $hasPaid) {
            return ApiResponse::error('Your plan can only be changed from the checkout screen.', 422);
        }

        $package = \App\Models\Package::with('modules')
            ->where('is_active', true)
            ->where('is_visible', true)
            ->find($data['package_id']);

        if (!$package) {
            return ApiResponse::error('That plan is not available.', 422);
        }

        $isCustomSelection = (bool) ($data['is_custom_selection'] ?? false);
        $moduleKeys = $package->modules->pluck('module_key')->all();

        if ($isCustomSelection) {
            $moduleKeys = $data['selected_modules'] ?? [];
            [$error] = $this->vetCustomModules($moduleKeys);
            if ($error) {
                return $error;
            }
        }

        if (empty($moduleKeys)) {
            return ApiResponse::error('Please select at least one valid module.', 422, [
                'selected_modules' => ['Please select at least one valid module.'],
            ]);
        }

        $admin->update([
            'package_id'            => $package->id,
            'is_custom_selection'   => $isCustomSelection,
            // Null means "unlimited", same as the Package's own defaults.
            'max_users_per_company' => $data['max_users'] ?? null,
            'max_companies'         => $data['max_companies'] ?? null,
        ]);

        $this->applyModuleSet($admin->load('companies'), $moduleKeys);

        return ApiResponse::success([
            'package_id'          => $package->id,
            'package_name'        => $package->name,
            'is_custom_selection' => $isCustomSelection,
            'modules'             => array_values($moduleKeys),
        ], 'Plan selected');
    }

    // GET /api/admin/subscription/order-summary — reconstructs the "Order
    // Summary" the /payment page shows from the admin's actual saved
    // package/seat/company allotment, for whenever localStorage.pending_order
    // (set once, client-side, at registration) isn't available — e.g. the
    // "Complete Payment" resume flow lands here on a session that never saw
    // the original registration page, or (the common case) an account
    // reactivating after trial_expired/suspended/cancelled, where there was
    // never a registration-page localStorage write to begin with. `modules`
    // is read from the company's actual enabled module set (Company::catalogKeys(),
    // same ground truth Api\SuperAdmin\CompanyController::syncModules() uses)
    // rather than re-deriving from the saved package, so a custom ("Build
    // Your Own Plan") selection round-trips back into the picker pre-checked
    // instead of silently reporting nothing — previously hardcoded to
    // mode='standard'/modules=[] here, which left a reactivating custom-plan
    // admin with no way to see what they'd actually had before.
    public function orderSummary(): JsonResponse
    {
        $admin   = $this->admin()->load(['package', 'companies']);
        $package = $admin->package;

        if (!$package) {
            return ApiResponse::error('No package found for this account.', 404);
        }

        $seats     = $admin->max_users_per_company ?? $package->max_users_per_company;
        $companies = $admin->max_companies ?? $package->max_companies;

        // applyModuleSet() keeps every company under one admin on the same
        // module set, so the first company's catalog keys represent them all.
        $firstCompany = $admin->companies->first();
        $ownedCategoryKeys = $firstCompany ? $firstCompany->catalogKeys(onlyEnabled: false) : [];

        // Must match process()'s own expectedAmount formula exactly (same
        // package, same discount_percent, same addon) — this total_usd is
        // what the payment page sends back as `amount` on a plain renewal, so
        // leaving discount_percent/the addon unapplied here made every
        // discounted package (e.g. Yearly) or any seat/company addon chosen
        // at registration fail process()'s price check — or, after
        // process()'s own check started trusting this total, silently
        // dropped the addon from what was actually charged instead.
        $discount = (float) ($package->discount_percent ?? 0);
        // Raw admin columns, NOT the $seats/$companies display fallbacks
        // above — those fall back to the package's own default when the
        // admin never set one, which isn't an addon anyone paid for.
        $addonUsd = $this->addonUsd($admin->max_users_per_company, $admin->max_companies);

        return ApiResponse::success([
            'package_id'             => $package->id,
            'package_name'           => $package->name,
            'mode'                   => $admin->is_custom_selection ? 'custom' : 'standard',
            'modules'                => $ownedCategoryKeys,
            'required_dependencies'  => [],
            'seats'                  => $seats ? "{$seats} Users" : 'Unlimited',
            'companies'              => $companies ? "{$companies} " . ($companies == 1 ? 'Company' : 'Companies') : 'Unlimited',
            'addon_usd'              => $addonUsd,
            'total_pkr'              => round((float) ($package->price_pkr ?? 0) * (1 - $discount / 100), 2),
            'total_usd'              => round((float) ($package->price_usd ?? 0) * (1 - $discount / 100) + $addonUsd, 2),
            'currency'               => 'USD',
            'trial_days'             => \App\Models\SubscriptionPolicy::current()->trial_duration_days,
        ]);
    }

    // GET /api/admin/subscription/plans — every Super-Admin-configured plan a
    // Company Admin may pick from (trial expiry / renewal screen). A
    // suspended/trial_expired admin must be able to reach this to choose
    // their Monthly/Yearly plan before paying — see this route's
    // ->withoutMiddleware(EnsureSubscriptionActive::class) in routes/api.php.
    public function plans(): JsonResponse
    {
        // trial_days is display copy only here — every plan shows the same
        // Super Admin global Subscription Policy value (see PublicController::
        // packages() / register(), same precedence), not Package::trial_days.
        $trialDays = \App\Models\SubscriptionPolicy::current()->trial_duration_days;

        $plans = \App\Models\Package::with(['billingTerm', 'modules'])
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('price_usd')
            ->get(['id', 'name', 'tier', 'price_usd', 'billing_cycle', 'billing_term_id', 'discount_percent', 'description', 'is_popular', 'features'])
            ->map(fn($pkg) => [
                ...$pkg->toArray(),
                'billing_term' => $pkg->billingTerm ? [
                    'id'     => $pkg->billingTerm->id,
                    'name'   => $pkg->billingTerm->name,
                    'months' => $pkg->billingTerm->months,
                ] : null,
                // Lets the frontend resolve a real package_id for a custom
                // module bundle in a given billing term (same "cheapest
                // package that covers these modules" logic PublicController::
                // register()'s autoPackage() already uses) — a custom bundle
                // still needs a real package row for future renewal billing.
                'modules'    => $pkg->modules->pluck('module_key')->values(),
                'trial_days' => $trialDays,
            ]);

        return ApiResponse::success($plans);
    }

    // GET /api/admin/payment-gateways — list active gateways for the "choose
    // a payment method" step, mirroring Api\PublicController::activeGateways()
    // for the pre-registration flow (that one is unauthenticated; this one is
    // for already-logged-in Company Admins, e.g. the module-purchase checkout).
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

    // =========================================================================
    // GET /api/admin/subscription/payment-init?gateway=stripe
    // Returns safe public credentials (never exposes secret keys)
    // =========================================================================
    public function init(Request $request): JsonResponse
    {
        $data    = $request->validate(['gateway' => 'required|in:stripe,paypal,authorize_net']);
        $gateway = $data['gateway'];

        try {
            $gw = $this->platformGateway($gateway);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $config = $gw->config ?? [];
        $mode   = $config['mode'] ?? 'sandbox';

        $public = match ($gateway) {
            'stripe' => [
                'publishable_key' => $config['publishable_key'] ?? '',
                'mode'            => $mode,
            ],
            'paypal' => [
                'client_id' => $config['client_id'] ?? '',
                'mode'      => $mode,
            ],
            'authorize_net' => [
                'api_login_id' => $config['api_login_id'] ?? '',
                'client_key'   => $config['client_key']   ?? '',
                'mode'         => $mode,
            ],
        };

        return ApiResponse::success($public);
    }

    // =========================================================================
    // POST /api/admin/subscription/paypal/create-order
    // Creates a PayPal order and returns the order ID to frontend
    // =========================================================================
    public function createPaypalOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'   => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
        ]);

        try {
            $gw = $this->platformGateway('paypal');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $config  = $gw->config ?? [];
        $mode    = $config['mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $tokenRes = Http::withBasicAuth($config['client_id'] ?? '', $config['client_secret'] ?? '')
            ->asForm()
            ->post("{$baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if ($tokenRes->failed()) {
            $msg = $tokenRes->json('error_description') ?? 'PayPal authentication failed. Check credentials in Super Admin → Payment Gateways.';
            return ApiResponse::error($msg, 422);
        }

        $orderRes = Http::withToken($tokenRes->json('access_token'))
            ->post("{$baseUrl}/v2/checkout/orders", [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => strtoupper($data['currency']),
                        'value'         => number_format((float) $data['amount'], 2, '.', ''),
                    ],
                ]],
            ]);

        if ($orderRes->failed()) {
            \Illuminate\Support\Facades\Log::error('PayPal createOrder failed', [
                'status'  => $orderRes->status(),
                'body'    => $orderRes->json(),
                'amount'  => $data['amount'],
                'currency'=> $data['currency'],
            ]);
            $msg = $orderRes->json('details.0.description')
                ?? $orderRes->json('message')
                ?? 'Failed to create PayPal order';
            return ApiResponse::error($msg, 422);
        }

        return ApiResponse::success(['order_id' => $orderRes->json('id')]);
    }

    // =========================================================================
    // POST /api/admin/subscription/process
    // Processes payment for Stripe or Authorize.Net; captures PayPal orders
    // =========================================================================
    public function process(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway'                => 'required|in:stripe,paypal,authorize_net',
            'amount'                 => 'required|numeric|min:0.01',
            'currency'               => 'required|string|size:3',
            // Optional Monthly/Yearly plan switch — e.g. reactivating after
            // trial expiry/suspension, choosing a different plan/cycle than
            // whatever they were previously on.
            'package_id'             => 'nullable|integer|exists:packages,id',
            // "Build Your Own Plan" at payment time — same custom-module concept
            // as PublicController::register()'s is_custom_selection, just chosen
            // later (converting/renewing) instead of at sign-up.
            'selected_modules'       => 'nullable|array|min:1',
            'selected_modules.*'     => 'string',
            'is_custom_selection'    => 'nullable|boolean',
            // Stripe
            'payment_method_id'      => 'required_if:gateway,stripe|nullable|string',
            // PayPal
            'paypal_order_id'        => 'required_if:gateway,paypal|nullable|string',
            // Authorize.Net
            'opaque_data_descriptor' => 'required_if:gateway,authorize_net|nullable|string',
            'opaque_data_value'      => 'required_if:gateway,authorize_net|nullable|string',
        ]);

        $isCustomSelection = (bool) ($data['is_custom_selection'] ?? false);
        $coveredModules = collect();

        // Validate the custom module set (and strip anything Super Admin has
        // since deactivated) BEFORE charging the card — a bad selection should
        // never result in a charge with nothing usable to show for it. Shared
        // with selectPlan() so the registration flow can't save a selection
        // this would later reject at the till.
        if ($isCustomSelection) {
            $submitted = $data['selected_modules'] ?? [];
            [$error, $coveredModules] = $this->vetCustomModules($submitted);
            if ($error) {
                return $error;
            }
            $data['selected_modules'] = $submitted;
        }

        $admin = $this->admin()->load('package.billingTerm');

        // The package this charge is actually for — an explicit switch
        // (package_id) if the request carries one, otherwise whatever the
        // admin is already on (a plain renewal, or just changing payment
        // method). Required to be active so a deactivated/tampered id can't
        // be priced at all.
        $targetPackage = !empty($data['package_id'])
            ? \App\Models\Package::with('billingTerm')->where('is_active', true)->find($data['package_id'])
            : $admin->package;

        if (!$targetPackage) {
            return ApiResponse::error('That plan is not available.', 422);
        }

        // Server-side price verification — the client-supplied `amount` is
        // never trusted for the actual charge, same principle
        // ModulePurchaseController::purchase() already applies to add-on
        // purchases. These two branches mirror the exact formulas the
        // /payment page itself computes (planPrice() / customTotalUsd), so a
        // legitimate submission always matches and a tampered one (a lower
        // amount, or modules the account isn't entitled to) is rejected
        // before any card is charged.
        // From the admin's own saved allotment, never the request — /payment
        // has no seat/company picker of its own (it only ever displays what
        // registration or a prior selectPlan() already chose), so there's
        // nothing legitimate for a request to override here anyway.
        $addonUsd = $this->addonUsd($admin->max_users_per_company, $admin->max_companies);

        if ($isCustomSelection) {
            // $coveredModules was resolved by vetCustomModules() above, which
            // already rejected unknown keys and partial categories.
            $customMonths = $targetPackage->billingTerm?->months ?? 1;
            $expectedAmount = round(
                (float) $coveredModules->sum('price_usd') * $customMonths * (1 - (float) $targetPackage->discount_percent / 100) + $addonUsd,
                2
            );
        } else {
            $expectedAmount = round(
                (float) $targetPackage->price_usd * (1 - (float) $targetPackage->discount_percent / 100) + $addonUsd,
                2
            );
        }

        // 1 cent tolerance for float rounding, not for genuine drift.
        if (abs($expectedAmount - (float) $data['amount']) > 0.01) {
            return ApiResponse::error('The amount to charge does not match the selected plan/modules. Please refresh and try again.', 422);
        }

        try {
            $gw = $this->platformGateway($data['gateway']);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $config = $gw->config ?? [];

        try {
            $gatewayRef = $this->charger->charge($data['gateway'], $config, $data);
        } catch (\RuntimeException $e) {
            // A declined card is a platform event: the tenant is now at risk
            // of suspension and the operator is the one who follows it up.
            SuperAdminNotificationService::paymentFailed(
                $this->admin(),
                strtoupper($data['currency']) . ' ' . number_format((float) $data['amount'], 2),
                'subscription',
                $e->getMessage()
            );

            return ApiResponse::error($e->getMessage(), 422);
        }

        // $admin was already loaded (with package.billingTerm) above, for the
        // price-verification step — reused here rather than re-fetched.
        // Trial/Suspension/Subscription policy: whatever status they were in
        // before this payment (trial, trial_expired, grace_period, suspended,
        // or a plain renewal from active) — a successful charge always lands
        // them on 'active' and clears every suspension-tracking field. This
        // endpoint is deliberately reachable even while blocked (see
        // routes/api.php's ->withoutMiddleware(EnsureSubscriptionActive::class)
        // on this route) — that's the whole point of the reactivation flow.
        $previousStatus = $admin->subscription_status;
        $wasBlocked = $admin->isBlockedFromAccess() || $previousStatus === 'grace_period';
        // Captured before the package-switch mutation below so the module-sync
        // branch can tell whether this request actually changed anything, as
        // opposed to just re-submitting whatever the admin already had.
        $originalPackageId = $admin->package_id;
        $originalIsCustom  = $admin->is_custom_selection;

        // Optional Monthly/Yearly plan switch, chosen on the reactivation/
        // plan-selection screen — swap the package BEFORE computing the new
        // period so a switch to yearly renews a year out, not a month.
        if (!empty($data['package_id']) && (int) $data['package_id'] !== (int) $admin->package_id) {
            $admin->package_id = (int) $data['package_id'];
            $admin->setRelation('package', \App\Models\Package::find($admin->package_id));
        }
        $packageChanged = !empty($data['package_id']) && (int) $data['package_id'] !== (int) $originalPackageId;

        // Respects the Admin's own package's Billing Term (see BillingTerm/
        // YearlyPackageSync — Super Admin-defined, not a fixed set) — a
        // multi-year plan must renew that many months out, not 1 (the
        // previous hardcoded addMonth() here silently mis-renewed every
        // non-monthly subscriber).
        $months = $admin->package?->billingTerm?->months ?? 1;
        $periodEnd = now()->addMonths($months);

        SubscriptionPayment::create([
            'admin_id'     => $admin->id,
            'package_id'   => $admin->package_id,
            'amount'       => $data['amount'],
            'currency'     => strtoupper($data['currency']),
            'gateway'      => $data['gateway'],
            'gateway_ref'  => $gatewayRef,
            'status'       => 'paid',
            'period_start' => now(),
            'period_end'   => $periodEnd,
        ]);

        $admin->update([
            'subscription_status'  => 'active',
            'subscription_ends_at' => $periodEnd,
            'grace_period_ends_at' => null,
            'suspended_at'         => null,
            'suspended_reason'     => null,
        ]);
        $admin->refresh();

        if ($isCustomSelection) {
            // Explicit "Build Your Own Plan" pick made right here at payment —
            // always honored, same as the registration flow. Persisted so a
            // FUTURE renewal/reactivation payment (this same endpoint) knows
            // not to silently narrow them back down to the package's modules
            // below.
            $admin->is_custom_selection = true;
            $admin->save();
            $this->applyModuleSet($admin, $data['selected_modules']);
        } elseif ($admin->package && (in_array($previousStatus, ['trial', 'trial_expired'], true) || $packageChanged || $originalIsCustom)) {
            // Package-mode selection that must reflect exactly the chosen
            // package's modules rather than stale ones — either pruning the
            // trial's full-catalog grant (PublicController::register()'s
            // "every module during trial" grant) on first payment, an
            // explicit package switch made on the reactivation/plan-selection
            // screen (grace_period/suspended/cancelled picking a different
            // package than before), or moving back from a prior custom pick
            // to a standard package. Never applies when neither the package
            // nor the custom flag actually changed — that falls through to
            // enableExistingModules() below, unchanged.
            if ($originalIsCustom) {
                $admin->is_custom_selection = false;
                $admin->save();
            }
            $this->applyModuleSet($admin, $admin->package->modules->pluck('module_key')->all());
        } elseif ($wasBlocked) {
            // Reactivating from suspended/grace_period/cancelled/pending_payment
            // with neither the package nor the custom-selection flag changed —
            // CheckSubscriptionLifecycle only ever DISABLED their modules on
            // the way in, never deleted them, so paying again just flips them
            // back on rather than re-deriving any module set.
            $admin->enableExistingModules();
        }

        SubscriptionNotificationService::paymentSuccessful($admin, strtoupper($data['currency']) . ' ' . number_format((float) $data['amount'], 2));
        if ($previousStatus !== 'active') {
            SubscriptionNotificationService::subscriptionActivated($admin);
        }

        // Super Admin's feed separates a NEW subscriber from an existing one
        // paying again — growth and retention read differently. Decided on
        // whether this admin has paid before rather than on the status they
        // came from: a suspended customer paying again is a renewal, not a
        // first purchase.
        SuperAdminNotificationService::subscriptionPaid(
            $admin,
            SubscriptionPayment::where('admin_id', $admin->id)->where('status', 'paid')->count() > 1,
            $admin->package?->name,
            $periodEnd->toFormattedDateString()
        );
        if ($wasBlocked) {
            SubscriptionNotificationService::accountReactivated($admin);
        }

        return ApiResponse::success([
            'status'      => 'success',
            'gateway'     => $data['gateway'],
            'gateway_ref' => $gatewayRef,
        ], 'Payment successful. Subscription activated.');
    }
}
