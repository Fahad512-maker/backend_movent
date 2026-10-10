<?php

namespace Tests\Feature;

use App\Models\CompanyAdmin;
use App\Models\Package;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Switching between PayPal, Stripe and Authorize.Net on /payment.
 *
 * The reported bug ("PayPal is not fully configured yet", "Failed to load
 * Stripe" after switching back to a gateway that worked a moment ago) was
 * frontend state, not configuration: the /payment page kept ONE `initData`
 * slot shared by all three gateways, so during the render between selecting a
 * gateway and its own config arriving, each gateway's effect read the
 * PREVIOUS gateway's config and declared itself misconfigured. Fixed by
 * stamping the config with the gateway it was fetched for and deriving the
 * selected gateway's config from that.
 *
 * What this file pins down is the half the server owns, and the assumption
 * that fix rests on: payment-init is stateless, so the SAME gateway asked for
 * a second or third time — in any order, after any other gateway — always
 * answers with its own complete, current credentials and never another
 * gateway's. If that were not true, no amount of frontend work would make
 * switching reliable.
 */
class GatewaySwitchingTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;

    /** The public field each gateway's init payload is keyed on by the frontend. */
    private const PUBLIC_FIELDS = [
        'stripe'        => ['publishable_key'],
        'paypal'        => ['client_id'],
        'authorize_net' => ['api_login_id', 'client_key'],
    ];

    /** Fields that must NEVER reach the browser. */
    private const SECRET_FIELDS = ['secret_key', 'client_secret', 'transaction_key', 'signature_key'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway('stripe', 'Stripe', [
            'mode' => 'sandbox', 'publishable_key' => 'pk_test_switching', 'secret_key' => 'sk_test_switching',
        ]);
        $this->configureGateway('paypal', 'PayPal', [
            'mode' => 'sandbox', 'client_id' => 'paypal_client_switching', 'client_secret' => 'paypal_secret_switching',
        ]);
        $this->configureGateway('authorize_net', 'Authorize.Net', [
            'mode' => 'sandbox', 'api_login_id' => 'authnet_login', 'client_key' => 'authnet_client_key',
            'transaction_key' => 'authnet_transaction_key',
        ]);

        $package = Package::create([
            'name' => 'Gateway Switch Plan ' . uniqid(), 'tier' => 'basic', 'billing_cycle' => 'monthly',
            'price' => 25, 'price_usd' => 25, 'discount_percent' => 0, 'is_active' => true, 'is_visible' => true,
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $package->id, 'name' => 'Switching Admin',
            'email' => 'switching+' . uniqid() . '@example.test', 'password' => bcrypt('password'),
            // The exact state a just-registered admin sits in on /payment.
            'subscription_status' => 'pending_payment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);
    }

    private function configureGateway(string $name, string $displayName, array $config): void
    {
        PaymentGateway::updateOrCreate(
            ['name' => $name],
            ['display_name' => $displayName, 'is_active' => true, 'config' => $config]
        );
    }

    private function asAdmin(): self
    {
        Sanctum::actingAs($this->admin, ['role:admin'], 'admin');

        return $this;
    }

    /** One "the user selected this gateway" step, asserted the way the page reads it. */
    private function selectGateway(string $gateway): array
    {
        $data = $this->asAdmin()
            ->getJson("/api/admin/subscription/payment-init?gateway={$gateway}")
            ->assertOk()
            ->json('data');

        foreach (self::PUBLIC_FIELDS[$gateway] as $field) {
            $this->assertArrayHasKey($field, $data, "{$gateway} must return {$field}.");
            $this->assertNotEmpty($data[$field], "{$gateway}'s {$field} must not be empty — an empty one is exactly what the page reports as \"not fully configured\".");
        }

        // Isolation: the payload must not carry any OTHER gateway's public
        // field. A page that shares one config slot between gateways can only
        // be as isolated as what the server hands it.
        foreach (self::PUBLIC_FIELDS as $other => $fields) {
            if ($other === $gateway) {
                continue;
            }
            foreach ($fields as $field) {
                $this->assertArrayNotHasKey($field, $data, "{$gateway}'s payload leaked {$other}'s {$field}.");
            }
        }

        foreach (self::SECRET_FIELDS as $secret) {
            $this->assertArrayNotHasKey($secret, $data, "{$gateway} leaked {$secret} to the browser.");
        }

        return $data;
    }

    /** Walks a switch sequence and asserts every step answers identically to the first time. */
    private function assertSequence(array $sequence): void
    {
        $seen = [];

        foreach ($sequence as $step => $gateway) {
            $data = $this->selectGateway($gateway);

            if (isset($seen[$gateway])) {
                $this->assertSame(
                    $seen[$gateway],
                    $data,
                    "Returning to {$gateway} at step {$step} gave a different config than the first visit."
                );
            }
            $seen[$gateway] = $data;
        }
    }

    // ── The sequences from the bug report ───────────────────────────────────

    public function test_paypal_then_stripe_then_paypal(): void
    {
        $this->assertSequence(['paypal', 'stripe', 'paypal']);
    }

    public function test_stripe_then_paypal_then_stripe(): void
    {
        $this->assertSequence(['stripe', 'paypal', 'stripe']);
    }

    public function test_paypal_then_authorize_net_then_stripe_then_paypal(): void
    {
        $this->assertSequence(['paypal', 'authorize_net', 'stripe', 'paypal']);
    }

    /** Every gateway reached first, straight after load, with nothing selected before it. */
    public function test_each_gateway_works_as_the_very_first_selection(): void
    {
        foreach (array_keys(self::PUBLIC_FIELDS) as $gateway) {
            $this->selectGateway($gateway);
        }
    }

    /** Long round-trip — no accumulated state anywhere in the chain. */
    public function test_a_long_switch_chain_stays_correct_throughout(): void
    {
        $this->assertSequence([
            'stripe', 'paypal', 'authorize_net', 'stripe', 'authorize_net', 'paypal', 'stripe', 'paypal',
        ]);
    }

    // ── Source of truth ─────────────────────────────────────────────────────

    /** Nothing is cached: a Super Admin edit is live on the very next selection. */
    public function test_a_config_change_is_picked_up_on_the_next_selection(): void
    {
        $before = $this->selectGateway('stripe');
        $this->assertSame('pk_test_switching', $before['publishable_key']);

        $this->selectGateway('paypal');

        $this->configureGateway('stripe', 'Stripe', [
            'mode' => 'live', 'publishable_key' => 'pk_live_rotated', 'secret_key' => 'sk_live_rotated',
        ]);

        $after = $this->selectGateway('stripe');
        $this->assertSame('pk_live_rotated', $after['publishable_key']);
        $this->assertSame('live', $after['mode']);
    }

    /**
     * A genuinely unconfigured gateway must still say so — the fix must not
     * paper over the real version of the error the user was seeing falsely.
     */
    public function test_a_gateway_with_a_blank_key_reports_an_empty_value_rather_than_anothers(): void
    {
        $this->configureGateway('paypal', 'PayPal', ['mode' => 'sandbox', 'client_secret' => 'only_secret']);

        $this->selectGateway('stripe');

        $data = $this->asAdmin()
            ->getJson('/api/admin/subscription/payment-init?gateway=paypal')
            ->assertOk()
            ->json('data');

        $this->assertSame('', $data['client_id'], 'A missing client_id must come back empty, never filled in from Stripe.');
        $this->assertArrayNotHasKey('publishable_key', $data);
    }

    public function test_a_deactivated_gateway_is_refused_without_affecting_the_others(): void
    {
        PaymentGateway::where('name', 'paypal')->update(['is_active' => false]);

        $this->asAdmin()
            ->getJson('/api/admin/subscription/payment-init?gateway=paypal')
            ->assertStatus(422);

        // The other two are untouched by that failure.
        $this->selectGateway('stripe');
        $this->selectGateway('authorize_net');
    }

    public function test_an_unknown_gateway_is_rejected(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/subscription/payment-init?gateway=bitcoin')
            ->assertStatus(422)
            ->assertJsonValidationErrors('gateway');
    }

    /** payment-init is reachable while pending_payment — that's the whole registration flow. */
    public function test_payment_init_is_reachable_for_a_pending_payment_admin(): void
    {
        $this->assertSame('pending_payment', $this->admin->subscription_status);
        $this->selectGateway('stripe');
    }

    /**
     * Its own test, with no actingAs() before it: once Sanctum::actingAs() has
     * run, the resolved user stays bound for the rest of the test, so an
     * "anonymous" request made after an authenticated one would still pass as
     * that admin (the same quirk CompanySuspensionTest documents).
     */
    public function test_payment_init_is_refused_anonymously(): void
    {
        $this->getJson('/api/admin/subscription/payment-init?gateway=stripe')->assertStatus(401);
    }

    /** The gateway list the page builds its buttons from stays stable across selections. */
    public function test_the_active_gateway_list_is_stable_across_switches(): void
    {
        $first = $this->asAdmin()->getJson('/api/admin/payment-gateways')->assertOk()->json('data');

        $this->selectGateway('paypal');
        $this->selectGateway('stripe');

        $second = $this->asAdmin()->getJson('/api/admin/payment-gateways')->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertEqualsCanonicalizing(
            ['stripe', 'paypal', 'authorize_net'],
            collect($second)->pluck('name')->all()
        );
    }
}
