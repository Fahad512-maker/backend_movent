<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Thin wrapper around exchangerate-api.com's `pair` endpoint. Rates are
// cached per currency pair for 30 minutes so the amount shown in a
// pre-checkout preview (PublicInvoiceController::show()) stays consistent
// with what InvoiceGatewayChargeService actually charges moments later for
// any normal-speed checkout.
class CurrencyConversionService
{
    private const CACHE_TTL_SECONDS = 1800;

    /**
     * @return array{amount: float, rate: float}
     */
    public static function convert(float $amount, string $from, string $to): array
    {
        $rate = self::rate($from, $to);

        return [
            'amount' => round($amount * $rate, 2),
            'rate'   => $rate,
        ];
    }

    public static function rate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        return Cache::remember("exchange_rate:{$from}:{$to}", self::CACHE_TTL_SECONDS, function () use ($from, $to) {
            $apiKey = config('services.exchange_rate.key');
            if (!$apiKey) {
                Log::error('Currency conversion failed: EXCHANGE_RATE_API_KEY is not configured.');
                throw new \RuntimeException('Currency conversion is not available right now. Please try again later or contact support.');
            }

            $response = Http::timeout(10)->get("https://v6.exchangerate-api.com/v6/{$apiKey}/pair/{$from}/{$to}");

            if (!$response->successful() || ($response->json('result') !== 'success')) {
                Log::error('Currency conversion API call failed', [
                    'from' => $from, 'to' => $to, 'status' => $response->status(), 'body' => $response->json(),
                ]);
                throw new \RuntimeException('Currency conversion is not available right now. Please try again later or contact support.');
            }

            $rate = (float) $response->json('conversion_rate');
            if ($rate <= 0) {
                throw new \RuntimeException('Currency conversion returned an invalid rate.');
            }

            return $rate;
        });
    }
}
