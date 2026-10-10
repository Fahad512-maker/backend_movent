<?php

namespace App\Services\ClientAssistant;

use App\Services\AiRouter;

/**
 * Says the assistant's answer back in the language the client is writing in.
 *
 * The flows compose their replies in English because that is where the FACTS
 * are assembled — an amount, an invoice number, a count. This class only ever
 * re-words an already-composed sentence; it is never given the database, never
 * asked what to say, and cannot introduce a fact that was not in the sentence
 * handed to it. If it fails, or is skipped, the English sentence is what the
 * client sees, which is correct, just not in their language.
 *
 * It is skipped entirely when the client is writing English (the common case),
 * so the ordinary reply costs no extra model call and no extra latency.
 */
class AssistantVoice
{
    public const EN       = 'en';
    public const URDU     = 'ur';
    public const ROMAN_UR = 'roman_ur';

    private const TIMEOUT_SECONDS = 8;
    private const MAX_TOKENS      = 400;

    /**
     * Which language the client is writing in.
     *
     * Deliberately crude: Urdu script is unambiguous, and Roman Urdu is
     * recognised from the function words that actually carry it. Anything else
     * is treated as English, because English is also the safe default — the
     * cost of getting this wrong is one reply in the wrong language, so it errs
     * towards leaving the text alone.
     */
    public static function detect(string $text): string
    {
        $norm = mb_strtolower(trim($text));

        if ($norm === '') {
            return self::EN;
        }

        // Arabic/Urdu script block.
        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $norm)) {
            return self::URDU;
        }

        $markers = [
            'hai', 'hain', 'karna', 'karni', 'karo', 'kar do', 'mujhe', 'mera', 'meri',
            'kitne', 'kitna', 'kitni', 'baat', 'chahiye', 'nahi', 'nahin', 'kya',
            'kaise', 'kaisa', 'batao', 'dekhna', 'wala', 'wali', 'baqi', 'baaki',
            'shukriya', 'theek', 'acha', 'abhi', 'please karo',
            // Pronouns and the "name" question, which routinely arrive without
            // any of the verb markers above — "tum kaun ho" has none of them
            // and is unmistakably Roman Urdu.
            'aap', 'aapka', 'apka', 'tum', 'tumhara', 'naam', 'kaun', 'kon',
        ];

        foreach ($markers as $marker) {
            // Word-boundary matched so "hai" does not fire on "Thailand".
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $norm)) {
                return self::ROMAN_UR;
            }
        }

        return self::EN;
    }

    /**
     * Re-word $message in $language, or return it unchanged.
     *
     * Returns the original on every failure path — no key, provider down,
     * timeout, empty reply, or a reply long enough to suggest the model started
     * writing its own answer instead of translating ours.
     */
    public static function phrase(string $message, string $language): string
    {
        if ($language === self::EN || trim($message) === '') {
            return $message;
        }

        $target = $language === self::URDU
            ? 'Urdu (Urdu script)'
            : 'Roman Urdu (Urdu written in Latin letters, the way Pakistani clients type in chat)';

        $response = AiRouter::complete(
            [
                ['role' => 'system', 'content' => <<<PROMPT
                You are a translator inside a billing portal. Rewrite the user's
                message in {$target}, keeping the same meaning, the same warm and
                professional tone, and the same length.

                Absolute rules:
                - Do NOT add, remove or change any number, amount, currency,
                  date, invoice reference, project name or person's name. Copy
                  them across exactly as written, in Latin characters.
                - Do NOT answer, explain, comment or add anything of your own.
                - Output only the rewritten message. No quotes, no preamble.
                PROMPT],
                ['role' => 'user', 'content' => $message],
            ],
            self::TIMEOUT_SECONDS,
            self::MAX_TOKENS,
            '[client-assistant-voice]',
            [],
            null,
            0.2
        );

        $text = trim((string) ($response['content'] ?? ''));

        if ($text === '') {
            return $message;
        }

        // A translation of a two-line reply is not five times its length. When
        // it is, the model has written something of its own — which is exactly
        // what must never reach the client, so the original stands.
        if (mb_strlen($text) > max(320, mb_strlen($message) * 3)) {
            return $message;
        }

        return $text;
    }
}
