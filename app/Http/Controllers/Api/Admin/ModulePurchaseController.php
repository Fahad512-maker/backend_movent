<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\SubscriptionPayment;
use App\Models\SystemAuditLog;
use App\Services\ModuleDependency;
use App\Services\PaymentGatewayCharger;
use App\Services\SuperAdminNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModulePurchaseController extends Controller
{
    private PaymentGatewayCharger $charger;

    public function __construct(PaymentGatewayCharger $charger)
    {
        $this->charger = $charger;
    }

    private function admin()
    {
        return auth('admin')->user();
    }

    // company_modules stores GRANULAR keys (e.g. "leads", "employees"), never
    // the top-level catalog key (e.g. "sales", "hr") — same distinction this
    // whole app already relies on elsewhere (CheckCompanyModule, register()).
    // A top-level Module is "owned" once every one of its sub_modules is active.
    private function ownedGranularKeys(int $companyId): array
    {
        return CompanyModule::where('company_id', $companyId)->where('is_enabled', true)->pluck('module_key')->toArray();
    }

    // GET /admin/modules/catalog — the full module catalog plus which
    // TOP-LEVEL keys the admin's primary company already fully owns, so the
    // frontend can render "Purchased" (disabled) vs. selectable cards. Also
    // returns the admin's current billing-term discount so the frontend can
    // show/compute the same discounted total purchase() will actually charge.
    public function catalog(): JsonResponse
    {
        $admin = $this->admin()->load('package.billingTerm');
        $company = $admin->companies()->first();
        $ownedGranular = $company ? $this->ownedGranularKeys($company->id) : [];

        $modules = Module::where('is_active', true)
            ->orderBy('label')
            ->get(['key', 'label', 'description', 'sub_modules', 'price_pkr', 'price_usd']);

        $ownedCategories = $modules
            ->filter(fn ($m) => !empty($m->sub_modules) && empty(array_diff($m->sub_modules, $ownedGranular)))
            ->pluck('key')
            ->values();

        return ApiResponse::success([
            'modules'          => $modules,
            'owned_modules'    => $ownedCategories,
            'discount_percent' => $admin->addOnDiscountPercent(),
            'billing_term_name' => $admin->package?->billingTerm?->name,
        ]);
    }

    // POST /admin/modules/purchase — charge for and activate new module(s)
    // on the admin's primary company. Never re-charges or duplicates a
    // module the company already has; never activates anything before the
    // gateway charge succeeds.
    public function purchase(Request $request): JsonResponse
    {
        $admin = $this->admin()->load('package');
        $company = $admin->companies()->first();
        if (!$company) {
            return ApiResponse::error('No company found for this account.', 422);
        }

        $data = $request->validate([
            'module_keys'            => ['required', 'array', 'min:1'],
            'module_keys.*'          => ['string'],
            'gateway'                => ['required', 'in:stripe,paypal,authorize_net'],
            'currency'               => ['required', 'string', 'size:3'],
            'payment_method_id'      => ['required_if:gateway,stripe', 'nullable', 'string'],
            'paypal_order_id'        => ['required_if:gateway,paypal', 'nullable', 'string'],
            'opaque_data_descriptor' => ['required_if:gateway,authorize_net', 'nullable', 'string'],
            'opaque_data_value'      => ['required_if:gateway,authorize_net', 'nullable', 'string'],
        ]);

        $requestedKeys = array_unique($data['module_keys']);
        $requestedModules = Module::whereIn('key', $requestedKeys)->get();

        $invalidKeys = array_diff($requestedKeys, $requestedModules->pluck('key')->all());
        if (!empty($invalidKeys)) {
            return ApiResponse::error('Unknown module key(s): ' . implode(', ', $invalidKeys), 422);
        }

        $ownedGranular = $this->ownedGranularKeys($company->id);

        // A requested category only needs (re-)purchasing if at least one of
        // its granular sub-modules isn't active yet — never re-charge or
        // duplicate a category that's already fully owned.
        /** @var Collection $newModules */
        $newModules = $requestedModules->filter(
            fn ($m) => empty($m->sub_modules) || !empty(array_diff($m->sub_modules, $ownedGranular))
        );
        if ($newModules->isEmpty()) {
            return ApiResponse::error('These modules are already active on your account.', 422);
        }

        $newCategoryKeys = $newModules->pluck('key')->values()->all();

        // Re-validate dependencies server-side against owned + newly requested,
        // so a tampered request can't bypass the register-page's client check.
        // $newCategoryKeys is passed separately so the Invoice-requires-Client
        // rule only blocks when Invoice is part of THIS purchase — companies
        // that already owned Invoice without Client before that rule existed
        // aren't retroactively locked out of buying unrelated modules.
        $dependencyErrors = ModuleDependency::errors(array_merge($ownedGranular, $newCategoryKeys), $newCategoryKeys);
        if (!empty($dependencyErrors)) {
            return ApiResponse::error('Module dependencies are not valid.', 422, [
                'module_keys' => $dependencyErrors,
            ]);
        }

        // Amount is computed server-side from the catalog — the client never
        // supplies (or gets trusted for) the charge amount on this endpoint.
        // Discounted by the admin's CURRENT billing term (0% on monthly) —
        // an admin already paying for a discounted Yearly/2-Year/etc. term
        // gets that same discount on add-on modules, not the full catalog price.
        $rawAmount = (float) $newModules->sum('price_usd');
        if ($rawAmount <= 0) {
            return ApiResponse::error('Selected modules have no purchasable price.', 422);
        }
        $discountPercent = $admin->addOnDiscountPercent();
        $amount = round($rawAmount * (1 - $discountPercent / 100), 2);

        $gatewayRef = null;
        // A 100%-discounted add-on has nothing left to actually charge —
        // gateways don't support $0 charges, so skip straight to activation
        // instead of erroring out on a legitimately free purchase.
        if ($amount > 0) {
            $chargeData = array_merge($data, ['amount' => $amount]);

            try {
                $gw = $this->charger->platformGateway($data['gateway']);
            } catch (\RuntimeException $e) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            try {
                $gatewayRef = $this->charger->charge($data['gateway'], $gw->config ?? [], $chargeData);
            } catch (\RuntimeException $e) {
                // Payment failed — nothing below this point runs, no module is activated.
                SuperAdminNotificationService::paymentFailed(
                    $admin,
                    strtoupper($data['currency']) . ' ' . number_format((float) $amount, 2),
                    'module upgrade',
                    $e->getMessage()
                );

                return ApiResponse::error($e->getMessage(), 422);
            }
        }

        $newGranularKeys = $newModules->flatMap(fn ($m) => $m->sub_modules ?? [])->unique()->values()->all();

        DB::transaction(function () use ($company, $admin, $newGranularKeys, $newCategoryKeys, $amount, $rawAmount, $discountPercent, $data, $gatewayRef) {
            // Activated on EVERY company the admin owns, not just the primary
            // one priced above: module access is checked per active company
            // (CheckCompanyModule), and an add-on is bought for the account —
            // the same way SubscriptionPaymentController applies a package's
            // modules to all of $admin->companies.
            foreach ($admin->companies as $companyRow) {
                foreach ($newGranularKeys as $key) {
                    CompanyModule::updateOrCreate(
                        ['company_id' => $companyRow->id, 'module_key' => $key],
                        ['is_enabled' => true]
                    );
                }

                // Existing staff never get a new module's permissions just by
                // it becoming available — only new hires do, via
                // UserController's own default-grant path. Without this, a
                // company buying HR today sees it appear with every
                // permission unchecked for every employee already on the
                // roster. Additive only — see PermissionBackfillService.
                \App\Services\PermissionBackfillService::grantDefaultsForNewModules($companyRow, $newCategoryKeys);
            }

            SubscriptionPayment::create([
                'admin_id'    => $admin->id,
                'package_id'  => $admin->package_id,
                'amount'      => $amount,
                'currency'    => strtoupper($data['currency']),
                'gateway'     => $data['gateway'],
                'gateway_ref' => $gatewayRef,
                'status'      => 'paid',
                'meta'        => [
                    'type' => 'module_purchase', 'module_keys' => $newCategoryKeys, 'granular_keys' => $newGranularKeys,
                    'list_price' => $rawAmount, 'discount_percent' => $discountPercent,
                ],
            ]);

            SystemAuditLog::create([
                'company_id'  => $company->id,
                'user_id'     => null, // Company Admin actor isn't a User row
                'action'      => 'module_purchased',
                'module_key'  => implode(',', $newCategoryKeys),
                'entity_type' => 'Company',
                'entity_id'   => $company->id,
                'new_values'  => ['module_keys' => $newCategoryKeys, 'granular_keys' => $newGranularKeys, 'amount' => $amount],
            ]);
        });

        $admin->load('companies.modules', 'package');

        // Platform revenue, so it belongs in the Super Admin's feed too — the
        // tenant-side notification for this purchase is the AdminResource
        // response itself plus the audit log above.
        SuperAdminNotificationService::paymentSucceeded(
            $admin,
            strtoupper($data['currency']) . ' ' . number_format((float) $amount, 2),
            'module upgrade (' . implode(', ', $newCategoryKeys) . ')',
            $data['gateway'] ?? null
        );

        return ApiResponse::success(new AdminResource($admin), 'Modules purchased and activated', 201);
    }
}
