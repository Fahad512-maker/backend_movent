<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider #1 in App\Services\AiRouter's fallback order (config/services.php
 * -> groq). Groq's API is OpenAI-compatible — same /chat/completions request
 * shape as App\Services\OpenRouterClient — so this is effectively that
 * client pointed at a different base URL and a single configured model,
 * normalized to the same return shape every AiRouter provider uses.
 *
 * No GROQ_API_KEY configured -> returns null immediately (this provider is
 * entirely optional; AiRouter just moves on to Gemini). Any non-2xx
 * response or thrown exception (timeout, DNS, etc.) also returns null —
 * never throws, so a provider outage can never propagate up into a caller
 * that isn't expecting it.
 */
class GroqClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

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
        float $temperature = 0.7
    ): ?array {
        $apiKey = config('services.groq.key');

        if (!$apiKey) {
            Log::info("{$logPrefix} [groq] no GROQ_API_KEY configured — skipping to next provider.", $logContext);

            return null;
        }

        $model = trim((string) config('services.groq.model')) ?: 'llama-3.3-70b-versatile';

        try {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->withToken($apiKey)
                ->post(self::ENDPOINT, [
                    'model'       => $model,
                    'max_tokens'  => $maxTokens,
                    'temperature' => $temperature,
                    'messages'    => $messages,
                ]);
        } catch (\Throwable $e) {
            Log::warning("{$logPrefix} [groq] request threw — trying next provider", $logContext + [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            // Covers quota-exceeded, rate-limit, 429, "model temporarily
            // unavailable", and any other provider-side failure alike —
            // every one of them just means try the next provider (Gemini).
            Log::warning("{$logPrefix} [groq] failed — trying next provider", $logContext + [
                'model'   => $model,
                'status'  => $response->status(),
                'code'    => $response->json('error.code'),
                'message' => $response->json('error.message'),
            ]);

            return null;
        }

        Log::info("{$logPrefix} [groq] responded", $logContext + ['model' => $model]);

        return [
            'content'       => (string) $response->json('choices.0.message.content', ''),
            'finish_reason' => $response->json('choices.0.finish_reason'),
            'provider'      => 'groq',
            'model'         => $model,
        ];
    }
}
