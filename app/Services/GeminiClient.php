<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider #2 in App\Services\AiRouter's fallback order (config/services.php
 * -> gemini), tried when Groq has no key or fails. Talks to Google's
 * Generative Language API directly — a different request/response shape
 * than the OpenAI-compatible one Groq/OpenRouter share, so the translation
 * lives entirely here and every AiRouter caller stays provider-agnostic.
 *
 * No GEMINI_API_KEY configured -> returns null immediately (this provider is
 * entirely optional; AiRouter just moves on to OpenRouter). Any non-2xx
 * response or thrown exception also returns null — never throws.
 */
class GeminiClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages  OpenAI-style
     *         (role: system|user|assistant) — converted below into Gemini's
     *         systemInstruction + contents[{role: user|model}] shape.
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
        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            Log::info("{$logPrefix} [gemini] no GEMINI_API_KEY configured — skipping to next provider.", $logContext);

            return null;
        }

        $model = trim((string) config('services.gemini.model')) ?: 'gemini-2.0-flash';

        [$systemInstruction, $contents] = self::toGeminiFormat($messages);

        if ($contents === []) {
            Log::warning("{$logPrefix} [gemini] no user/assistant content to send — skipping to next provider.", $logContext);

            return null;
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature'     => $temperature,
                // The default Gemini model thinks before answering, billing
                // that reasoning against maxOutputTokens — the same trade-off
                // as Groq's default model (see config/services.php -> groq).
                // Every caller in this app wants a short, direct answer
                // (a title, a JSON list), never a reasoned essay, so thinking
                // is switched off entirely rather than sized for it: without
                // this, a short maxTokens budget comes back with
                // finishReason='MAX_TOKENS' and empty content, not a short
                // answer.
                'thinkingConfig'  => ['thinkingBudget' => 0],
            ],
        ];

        if ($systemInstruction !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                // Header, not a ?key= query param — keeps the key out of any
                // URL that ends up in a request log or error trace.
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning("{$logPrefix} [gemini] request threw — trying next provider", $logContext + [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            // Covers quota-exceeded, rate-limit, 429, "model temporarily
            // unavailable", and any other provider-side failure alike —
            // every one of them just means try the next provider (OpenRouter).
            Log::warning("{$logPrefix} [gemini] failed — trying next provider", $logContext + [
                'model'   => $model,
                'status'  => $response->status(),
                'code'    => $response->json('error.status'),
                'message' => $response->json('error.message'),
            ]);

            return null;
        }

        Log::info("{$logPrefix} [gemini] responded", $logContext + ['model' => $model]);

        return [
            'content'       => (string) $response->json('candidates.0.content.parts.0.text', ''),
            // Gemini's equivalent of finish_reason: 'STOP', 'MAX_TOKENS',
            // 'SAFETY', etc.
            'finish_reason' => $response->json('candidates.0.finishReason'),
            'provider'      => 'gemini',
            'model'         => $model,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: string, 1: array<int, array{role: string, parts: array<int, array{text: string}>}>}
     */
    private static function toGeminiFormat(array $messages): array
    {
        $systemParts = [];
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $text = trim((string) ($message['content'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($role === 'system') {
                $systemParts[] = $text;

                continue;
            }

            // Gemini calls the assistant turn 'model', not 'assistant'.
            $contents[] = [
                'role'  => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        return [implode("\n\n", $systemParts), $contents];
    }
}
