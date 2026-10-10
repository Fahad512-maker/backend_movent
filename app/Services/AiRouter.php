<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * The single entry point every AI feature in this app calls — currently
 * App\Services\LeadProjectTitleService (project name/title suggestions) and
 * App\Services\AiServiceCatalogService (Client Portal's "AI Suggested IT
 * Services"). Replaces calling App\Services\OpenRouterClient directly.
 *
 * Tries three FREE providers in a fixed order, stopping at the first one
 * that answers:
 *
 *   1. Groq       (App\Services\GroqClient)
 *   2. Gemini     (App\Services\GeminiClient)
 *   3. OpenRouter (App\Services\OpenRouterClient) — itself tries every model
 *      in config('services.openrouter.free_models') before giving up.
 *
 * Each provider client already treats "no API key configured", a thrown
 * exception (timeout, DNS, ...), and any non-2xx response (429, quota
 * exceeded, rate limited, daily limit reached, model unavailable, temporary
 * server error, ...) identically: log a warning and return null. AiRouter
 * only has to know "null means try the next provider" — it never inspects
 * status codes itself. Once all three are exhausted this returns null too,
 * and every caller already falls through to its own non-AI default; a
 * total AI outage must never be fatal to the feature calling it.
 *
 * Never logs $messages content or any API key — only the technical failure
 * (status/code/message) and the caller-supplied $logContext (e.g. lead_id,
 * invoice_id), same as before this router existed.
 */
class AiRouter
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  int|null  $maxModels  Caps how many OpenRouter free models
     *         (provider #3) are tried — passed straight through to
     *         OpenRouterClient::call(). Does not affect Groq/Gemini, which
     *         are always exactly one attempt each.
     * @return array{content: string, finish_reason: ?string, provider: string, model: string}|null
     */
    public static function complete(
        array $messages,
        int $timeoutSeconds,
        int $maxTokens,
        string $logPrefix,
        array $logContext = [],
        ?int $maxModels = null,
        float $temperature = 0.7
    ): ?array {
        $providers = [
            fn () => GroqClient::call($messages, $timeoutSeconds, $maxTokens, $logPrefix, $logContext, $temperature),
            fn () => GeminiClient::call($messages, $timeoutSeconds, $maxTokens, $logPrefix, $logContext, $temperature),
            fn () => OpenRouterClient::call($messages, $timeoutSeconds, $maxTokens, $logPrefix, $logContext, $maxModels, $temperature),
        ];

        foreach ($providers as $provider) {
            $result = $provider();

            if ($result !== null) {
                return $result;
            }
        }

        Log::info("{$logPrefix} all AI providers (groq, gemini, openrouter) exhausted — falling back to non-AI default.", $logContext);

        return null;
    }
}
