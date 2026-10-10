<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider #3 (last AI attempt) in App\Services\AiRouter's fallback order —
 * the single place ANY AI feature in this app talks to OpenRouter
 * (config/services.php -> openrouter).
 *
 * Tries each model in freeModels() (configurable via OPENROUTER_FREE_MODELS,
 * comma-separated) in order — a quota/rate-limit/429/timeout/provider error
 * on one moves straight to the next, silently, as long as another remains
 * untried. Never falls through to a paid model. Returns a normalized result
 * (same shape as App\Services\GroqClient/GeminiClient) from the first model
 * that answers, or null once every configured free model (or, if $maxModels
 * is given, the first $maxModels of them) has failed — AiRouter treats null
 * as "no provider left, fall through to the caller's own non-AI default".
 */
class OpenRouterClient
{
    /**
     * The free-model fallback order — configurable via OPENROUTER_FREE_MODELS
     * so more can be added without a code change. Falls back to the single
     * OPENROUTER_MODEL when that list is left unset, so a deployment that
     * never configured it keeps working exactly as before. Every entry MUST
     * be free (an 'openrouter/free' alias, or a 'vendor/model:free' id) —
     * this app never falls through to a paid model under any circumstance.
     *
     * @return array<int, string>
     */
    public static function freeModels(): array
    {
        $configured = config('services.openrouter.free_models');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        $single = trim((string) config('services.openrouter.model'));

        return $single !== '' ? [$single] : [];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, finish_reason: ?string, provider: string, model: string}|null
     */
    public static function call(
        array $messages,
        int $timeoutSeconds,
        int $maxTokens,
        string $logPrefix,
        array $logContext = [],
        ?int $maxModels = null,
        float $temperature = 0.7
    ): ?array {
        $apiKey = config('services.openrouter.key');

        if (!$apiKey) {
            Log::info("{$logPrefix} [openrouter] no OPENROUTER_API_KEY configured — skipping.", $logContext);

            return null;
        }

        $models = self::freeModels();
        if ($models === []) {
            Log::warning("{$logPrefix} [openrouter] no free model configured — skipping.", $logContext);

            return null;
        }

        if ($maxModels !== null) {
            $models = array_slice($models, 0, $maxModels);
        }

        $url = rtrim((string) config('services.openrouter.base_url'), '/') . '/chat/completions';

        foreach ($models as $model) {
            try {
                $response = Http::timeout($timeoutSeconds)
                    ->acceptJson()
                    ->withToken($apiKey)
                    // Optional attribution headers OpenRouter reads — they
                    // are what makes this app identifiable on the account's
                    // activity page instead of every call showing up as an
                    // anonymous one.
                    ->withHeaders([
                        'HTTP-Referer' => (string) config('app.url'),
                        'X-Title'      => (string) config('app.name'),
                    ])
                    ->post($url, [
                        'model'       => $model,
                        'max_tokens'  => $maxTokens,
                        'temperature' => $temperature,
                        'messages'    => $messages,
                    ]);
            } catch (\Throwable $e) {
                Log::warning("{$logPrefix} [openrouter] model {$model} threw — trying next free model", $logContext + [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                Log::info("{$logPrefix} [openrouter] responded", $logContext + ['model' => $model]);

                return [
                    'content'       => (string) $response->json('choices.0.message.content', ''),
                    'finish_reason' => $response->json('choices.0.finish_reason'),
                    'provider'      => 'openrouter',
                    'model'         => $model,
                ];
            }

            // Covers quota-exceeded, rate-limit, 429, "model temporarily
            // unavailable", and any other provider-side failure alike —
            // every one of them just means try the next free model. Never a
            // paid model, never surfaced to the user while one remains untried.
            Log::warning("{$logPrefix} [openrouter] model {$model} failed — trying next free model", $logContext + [
                'model'   => $model,
                'status'  => $response->status(),
                'code'    => $response->json('error.code'),
                'message' => $response->json('error.message'),
            ]);
        }

        Log::info("{$logPrefix} [openrouter] all configured free models failed — skipping.", $logContext);

        return null;
    }
}
