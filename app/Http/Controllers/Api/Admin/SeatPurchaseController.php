<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\SubscriptionPayment;
use App\Models\SystemAuditLog;
use App\Services\PaymentGatewayCharger;
use App\Services\SuperAdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatPurchaseController extends Controller
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

    // Fixed, inline catalogs — only 2 lists of 4-5 rows, no super-admin
    // editing requirement, single consumer (this controller). Keep in sync
    // with frontend/app/register/page.tsx's SEAT_OPTIONS/COMPANY_OPTIONS if
    // pricing ever changes — that page's copy only feeds signup-time pricing
    // display and isn't read by this upgrade flow.
    private const SEAT_TIERS = [
        ['value' => 10,   'label' => '10 Users',  'price_usd' => 0,  'price_pkr' => 0],
        ['value' => 25,   'label' => '25 Users',  'price_usd' => 3,  'price_pkr' => 800],
        ['value' => 50,   'label' => '50 Users',  'price_usd' => 6,  'price_pkr' => 1500],
        ['value' => 100,  'label' => '100 Users', 'price_usd' => 12, 'price_pkr' => 3000],
        ['value' => null, 'label' => 'Unlimited', 'price_usd' => 25, 'price_pkr' => 6000],
    ];

    private const COMPANY_TIERS = [
        ['value' => 1,    'label' => '1 Company',   'price_usd' => 0,  'price_pkr' => 0],
        ['value' => 3,    'label' => '3 Companies', 'price_usd' => 2,  'price_pkr' => 500],
        ['value' => 5,    'label' => '5 Companies', 'price_usd' => 4,  'price_pkr' => 1000],
        ['value' => null, 'label' => 'Unlimited',   'price_usd' => 10, 'price_pkr' => 2500],
    ];

    private function tiersFor(string $type): array
    {
        return $type === 'seats' ? self::SEAT_TIERS : self::COMPANY_TIERS;
    }

    // Effective current value — same precedence as ClientController::seatInfo()/
    // companyInfo(). null = unlimited.
    private function currentEffective(string $type): ?int
    {
        $admin = $this->admin()->load('package');
        return $type === 'seats'
            ? ($admin->max_users_per_company ?? $admin->package?->max_users_per_company)
            : ($admin->max_companies ?? $admin->package?->max_companies);
    }

    // GET /admin/seats/catalog
    public function catalog(): JsonResponse
    {
        $admin = $this->admin()->load('package.billingTerm');

        return ApiResponse::success([
            'seat_tiers'    => self::SEAT_TIERS,
            'company_tiers' => self::COMPANY_TIERS,
            'current' => [
                'max_users_per_company' => $this->currentEffective('seats'),
                'max_companies'         => $this->currentEffective('companies'),
            ],
            // Admin's current billing-term discount — same one purchase()
            // actually charges, so the frontend can show/compute the real total.
            'discount_percent'   => $admin->addOnDiscountPercent(),
            'billing_term_name'  => $admin->package?->billingTerm?->name,
        ]);
    }

    // POST /admin/seats/purchase — charge for and activate a higher
    // seat/company limit. Never activates anything before the gateway
    // charge succeeds; never allows moving the limit down.
    public function purchase(Request $request): JsonResponse
    {
        $admin = $this->admin()->load('package');

        $data = $request->validate([
            'type'                   => ['required', 'in:seats,companies'],
            'tier_value'             => ['present', 'nullable', 'integer', 'min:1'],
            'gateway'                => ['required', 'in:stripe,paypal,authorize_net'],
            'currency'               => ['required', 'string', 'size:3'],
            'payment_method_id'      => ['required_if:gateway,stripe', 'nullable', 'string'],
            'paypal_order_id'        => ['required_if:gateway,paypal', 'nullable', 'string'],
            'opaque_data_descriptor' => ['required_if:gateway,authorize_net', 'nullable', 'string'],
            'opaque_data_value'      => ['required_if:gateway,authorize_net', 'nullable', 'string'],
        ]);

        $tiers = $this->tiersFor($data['type']);
        $tier  = collect($tiers)->first(fn ($t) => $t['value'] === $data['tier_value']);
        if (!$tier) {
            return ApiResponse::error('Invalid tier selected.', 422);
        }

        // Upgrade-only: new tier must exceed the current effective value.
        // null (Unlimited) is always the top; can't "upgrade" past it.
        $current = $this->currentEffective($data['type']);
        if ($current === null) {
            return ApiResponse::error('You are already on the Unlimited tier.', 422);
        }
        if ($tier['value'] !== null && $tier['value'] <= $current) {
            return ApiResponse::error("Selected tier ({$tier['label']}) is not higher than your current limit ({$current}).", 422);
        }

        // Amount computed server-side from the fixed catalog — the client
        // never supplies (or gets trusted for) the charge amount. Discounted
        // by the admin's CURRENT billing term (0% on monthly), same as
        // ModulePurchaseController::purchase().
        $rawAmount = (float) $tier['price_usd'];
        if ($rawAmount <= 0) {
            return ApiResponse::error('Selected tier has no purchasable price.', 422);
        }
        $discountPercent = $admin->addOnDiscountPercent();
        $amount = round($rawAmount * (1 - $discountPercent / 100), 2);

        $gatewayRef = null;
        // A 100%-discounted tier has nothing left to actually charge —
        // gateways don't support $0 charges, so skip straight to activation.
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
                // Payment failed — nothing below this point runs, no limit is raised.
                SuperAdminNotificationService::paymentFailed(
                    $admin,
                    strtoupper($data['currency']) . ' ' . number_format((float) $amount, 2),
                    $data['type'] === 'seats' ? 'extra seats' : 'extra company slots',
                    $e->getMessage()
                );

                return ApiResponse::error($e->getMessage(), 422);
            }
        }

        $company = $admin->companies()->first();
        $column  = $data['type'] === 'seats' ? 'max_users_per_company' : 'max_companies';

        DB::transaction(function () use ($admin, $company, $data, $tier, $current, $amount, $rawAmount, $discountPercent, $gatewayRef, $column) {
            $admin->update([$column => $tier['value']]);

            SubscriptionPayment::create([
                'admin_id'    => $admin->id,
                'package_id'  => $admin->package_id,
                'amount'      => $amount,
                'currency'    => strtoupper($data['currency']),
                'gateway'     => $data['gateway'],
                'gateway_ref' => $gatewayRef,
                'status'      => 'paid',
                'meta'        => [
                    'type'           => $data['type'] === 'seats' ? 'seat_upgrade' : 'company_slot_upgrade',
                    'tier_value'     => $tier['value'],
                    'previous_value' => $current,
                    'list_price'     => $rawAmount,
                    'discount_percent' => $discountPercent,
                ],
            ]);

            SystemAuditLog::create([
                'company_id'  => $company?->id,
                'user_id'     => null, // Company Admin actor isn't a User row
                'action'      => $data['type'] === 'seats' ? 'seat_limit_upgraded' : 'company_limit_upgraded',
                'entity_type' => 'CompanyAdmin',
                'entity_id'   => $admin->id,
                'old_values'  => [$column => $current],
                'new_values'  => [$column => $tier['value'], 'amount' => $amount],
            ]);
        });

        $admin->load('companies.modules', 'package');

        SuperAdminNotificationService::paymentSucceeded(
            $admin,
            strtoupper($data['currency']) . ' ' . number_format((float) $amount, 2),
            $data['type'] === 'seats'
                ? "extra seats (now {$tier['value']})"
                : "extra company slots (now {$tier['value']})",
            $data['gateway'] ?? null
        );

        return ApiResponse::success(new AdminResource($admin), 'Limit upgraded and activated', 201);
    }
}
