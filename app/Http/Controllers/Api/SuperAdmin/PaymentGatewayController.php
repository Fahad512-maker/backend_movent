<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentGatewayController extends Controller
{
    private const SECRET_FIELDS = [
        'stripe'        => ['secret_key', 'webhook_secret'],
        'paypal'        => ['client_secret'],
        'authorize_net' => ['transaction_key'],
    ];

    private function maskConfig(string $name, array $config): array
    {
        $secrets = self::SECRET_FIELDS[$name] ?? [];
        foreach ($secrets as $field) {
            if (!empty($config[$field])) {
                $config[$field] = '••••••••';
            }
        }
        return $config;
    }

    private function preserveSecrets(string $name, array $newConfig, array $oldConfig): array
    {
        $secrets = self::SECRET_FIELDS[$name] ?? [];
        foreach ($secrets as $field) {
            if (($newConfig[$field] ?? '') === '••••••••') {
                $newConfig[$field] = $oldConfig[$field] ?? '';
            }
        }
        return $newConfig;
    }

    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::orderBy('id')->get()->map(function (PaymentGateway $g) {
            return [
                'id'           => $g->id,
                'name'         => $g->name,
                'display_name' => $g->display_name,
                'description'  => $g->description,
                'is_active'    => $g->is_active,
                'config'       => $this->maskConfig($g->name, $g->config ?? []),
                'updated_at'   => $g->updated_at,
            ];
        });

        return response()->json(['data' => $gateways]);
    }

    public function toggle(PaymentGateway $gateway): JsonResponse
    {
        $gateway->update(['is_active' => !$gateway->is_active]);

        return response()->json([
            'message' => $gateway->display_name . ' ' . ($gateway->is_active ? 'enabled' : 'disabled'),
            'data'    => array_merge($gateway->toArray(), [
                'config' => $this->maskConfig($gateway->name, $gateway->config ?? []),
            ]),
        ]);
    }

    public function updateConfig(Request $request, PaymentGateway $gateway): JsonResponse
    {
        //print_r($request);
        $request->validate([
            'config'      => 'required|array',
            'config.mode' => 'required|in:sandbox,live',
        ]);

        $oldConfig = $gateway->config ?? [];
        $newConfig = $this->preserveSecrets($gateway->name, $request->input('config', []), $oldConfig);

        $gateway->update(['config' => array_merge($oldConfig, $newConfig)]);

        return response()->json([
            'message' => 'Configuration updated',
            'data'    => array_merge($gateway->fresh()->toArray(), [
                'config' => $this->maskConfig($gateway->name, $gateway->fresh()->config ?? []),
            ]),
        ]);
    }

    public function testConnection(PaymentGateway $gateway): JsonResponse
    {
        $config = $gateway->config ?? [];

        if (empty($config)) {
            return response()->json(['success' => false, 'message' => 'No credentials configured for this gateway.'], 422);
        }

        $required = [
            'stripe'        => ['publishable_key', 'secret_key'],
            'paypal'        => ['client_id', 'client_secret'],
            'authorize_net' => ['api_login_id', 'transaction_key'],
        ];

        foreach (($required[$gateway->name] ?? []) as $field) {
            if (empty($config[$field])) {
                return response()->json(['success' => false, 'message' => "Missing required field: {$field}"], 422);
            }
        }

        $result = $this->validateCredentialFormat($gateway->name, $config);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function validateCredentialFormat(string $name, array $config): array
    {
        $mode = $config['mode'] ?? 'sandbox';

        switch ($name) {
            case 'stripe':
                $pk = $config['publishable_key'] ?? '';
                $sk = $config['secret_key'] ?? '';
                if (!str_starts_with($pk, 'pk_')) {
                    return ['success' => false, 'message' => 'Invalid Publishable Key — must start with pk_test_ or pk_live_'];
                }
                if (!str_starts_with($sk, 'sk_')) {
                    return ['success' => false, 'message' => 'Invalid Secret Key — must start with sk_test_ or sk_live_'];
                }
                if ($mode === 'sandbox' && !str_starts_with($pk, 'pk_test_')) {
                    return ['success' => false, 'message' => 'Sandbox mode selected but key is not a test key (pk_test_...)'];
                }
                return ['success' => true, 'message' => 'Stripe credentials validated (' . $mode . ' mode)'];

            case 'paypal':
                if (strlen($config['client_id'] ?? '') < 10) {
                    return ['success' => false, 'message' => 'PayPal Client ID appears too short'];
                }
                return ['success' => true, 'message' => 'PayPal credentials validated (' . $mode . ' mode)'];

            case 'authorize_net':
                if (strlen($config['api_login_id'] ?? '') < 3) {
                    return ['success' => false, 'message' => 'Authorize.Net API Login ID appears invalid'];
                }
                if (strlen($config['transaction_key'] ?? '') < 8) {
                    return ['success' => false, 'message' => 'Authorize.Net Transaction Key appears invalid (expected 16 chars)'];
                }
                return $this->testAuthorizeNetCredentials($config, $mode);
        }

        return ['success' => true, 'message' => 'Credentials look valid'];
    }

    // Authorize.Net's own "authenticateTestRequest" call validates the
    // merchant credentials against the real sandbox/live endpoint without
    // charging anything — a genuine live check, unlike the format-only
    // validation used for the other two gateways above.
    private function testAuthorizeNetCredentials(array $config, string $mode): array
    {
        $endpoint = $mode === 'live'
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';

        try {
            $res = Http::timeout(15)->acceptJson()->post($endpoint, [
                'authenticateTestRequest' => [
                    'merchantAuthentication' => [
                        'name'           => $config['api_login_id'],
                        'transactionKey' => $config['transaction_key'],
                    ],
                ],
            ]);
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Could not reach Authorize.Net. Please try again.'];
        }

        if ($res->failed()) {
            return ['success' => false, 'message' => 'Could not reach Authorize.Net. Please try again.'];
        }

        // Same BOM quirk as the live charge path — Authorize.Net's JSON
        // responses are prefixed with a UTF-8 BOM, which breaks json_decode()
        // unless stripped first.
        $json = json_decode(ltrim($res->body(), "\xEF\xBB\xBF"), true) ?? [];
        $resultCode = data_get($json, 'messages.resultCode');

        if ($resultCode === 'Ok') {
            return ['success' => true, 'message' => 'Authorize.Net credentials validated (' . $mode . ' mode)'];
        }

        $text = data_get($json, 'messages.message.0.text') ?? 'Authorize.Net rejected these credentials.';
        return ['success' => false, 'message' => $text];
    }
}
