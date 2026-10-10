<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            [
                'name'         => 'stripe',
                'display_name' => 'Stripe',
                'description'  => 'Accept credit and debit cards worldwide. Supports recurring billing and instant payouts.',
                'is_active'    => true,
                'config'       => [
                    'publishable_key' => '',
                    'secret_key'      => '',
                    'webhook_secret'  => '',
                    'mode'            => 'sandbox',
                ],
            ],
            [
                'name'         => 'paypal',
                'display_name' => 'PayPal',
                'description'  => 'Let customers pay with PayPal balance, credit cards, or bank accounts.',
                'is_active'    => true,
                'config'       => [
                    'client_id'     => '',
                    'client_secret' => '',
                    'mode'          => 'sandbox',
                ],
            ],
            [
                'name'         => 'authorize_net',
                'display_name' => 'Authorize.net',
                'description'  => 'Reliable payment processing for businesses of all sizes with advanced fraud detection.',
                'is_active'    => true,
                'config'       => [
                    'api_login_id'    => '',
                    'transaction_key' => '',
                    'client_key'      => '',
                    'mode'            => 'sandbox',
                ],
            ],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(
                ['name' => $gateway['name']],
                $gateway
            );
        }
    }
}
