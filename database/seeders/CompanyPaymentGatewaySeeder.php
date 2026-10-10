<?php

namespace Database\Seeders;

use App\Models\CompanyAdmin;
use App\Models\CompanyPaymentGateway;
use Illuminate\Database\Seeder;

class CompanyPaymentGatewaySeeder extends Seeder
{
    // The local demo tenant (see DatabaseSeeder) — the ONLY account this
    // seeder is allowed to touch.
    private const DEMO_ADMIN_EMAIL = 'admin@crm.test';

    // Placeholder credential fields per gateway, left empty for the demo
    // tenant to fill in from its settings page.
    private const CONFIG_TEMPLATES = [
        'stripe' => [
            'publishable_key' => '',
            'secret_key'      => '',
            'webhook_secret'  => '',
            'mode'            => 'sandbox',
        ],
        'paypal' => [
            'client_id'     => '',
            'client_secret' => '',
            'mode'          => 'sandbox',
        ],
        'authorize_net' => [
            'api_login_id'    => '',
            'transaction_key' => '',
            'client_key'      => '',
            'mode'            => 'sandbox',
        ],
    ];

    /**
     * Seeds placeholder gateway rows for the DEMO tenant only.
     *
     * This used to run `CompanyAdmin::each()` with an updateOrCreate whose
     * update payload included `config` and `is_active` — so every re-run of
     * `db:seed` reached into EVERY real tenant and (a) overwrote their live
     * gateway credentials with empty strings and (b) flipped is_active off,
     * silently disconnecting working payment gateways. DatabaseSeeder calls
     * this seeder three times, which made that a live hazard rather than a
     * theoretical one.
     *
     * Two changes make it safe:
     *  - scoped to the demo admin, so a real tenant is never touched; and
     *  - firstOrCreate, so an existing row's credentials are never rewritten.
     *
     * Real tenants deliberately start with NO gateway rows at all: a freshly
     * registered account (Api\PublicController::register()) creates none, so
     * Settings › Payment Gateways opens empty and stays that way until the
     * owner adds an account themselves. Nothing depends on placeholder rows
     * existing — CompanyPaymentGateway::resolveActiveGateways() only ever
     * returns is_active rows, and the settings UI adds accounts on demand.
     */
    public function run(): void
    {
        $demoAdmin = CompanyAdmin::where('email', self::DEMO_ADMIN_EMAIL)->first();

        if (!$demoAdmin) {
            return;
        }

        foreach (CompanyPaymentGateway::GATEWAYS as $gateway => $label) {
            CompanyPaymentGateway::firstOrCreate(
                ['company_admin_id' => $demoAdmin->id, 'gateway' => $gateway],
                [
                    'label'      => $label,
                    'is_active'  => false,
                    'is_default' => true,
                    'config'     => self::CONFIG_TEMPLATES[$gateway],
                ]
            );
        }
    }
}
