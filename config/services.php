<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id'      => env('GOOGLE_CLIENT_ID'),
        'client_secret'  => env('GOOGLE_CLIENT_SECRET'),
        'redirect'       => env('GOOGLE_REDIRECT_URI'),
        // Same OAuth client (Client ID/Secret) as the staff login, but a
        // distinct callback route so the two guards never mix up tokens —
        // must be registered as a SECOND Authorized redirect URI in Google
        // Cloud Console on the same OAuth client.
        'admin_redirect' => env('GOOGLE_ADMIN_REDIRECT_URI'),
    ],

    // A SEPARATE OAuth client from 'google' above — deliberately, not
    // reused. 'google' identifies who is signing in (openid/email/profile,
    // a "Sign in with Google" scope) and its consent screen never mentions
    // Drive. This one requests drive.file — permission to create files and
    // manage only the ones this app itself created, the narrowest Drive
    // scope that still does the job — which is a materially different,
    // reviewable grant a company's IT admin may want to reason about (or
    // revoke) independently of whether staff can log in with Google.
    //
    // See App\Services\GoogleDriveService for the OAuth dance and every API
    // call this makes. The connected Drive account is NEVER required to
    // match any CRM user's email — see the class-level note there.
    'google_drive' => [
        'client_id'     => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        // Must be registered in Google Cloud Console → Credentials → this
        // OAuth client → Authorized redirect URIs, exactly as set here.
        // Unauthenticated by necessity (Google redirects the bare browser
        // here, no Bearer token survives that hop) — see
        // Api\Auth\GoogleDriveConnectionController::callback() for how the
        // initiating company is recovered safely via a short-lived,
        // single-use state token instead.
        'redirect'      => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    // Company Storage — Section 18 of the Finance/Drive spec: "The 10 MB
    // limit should be configurable in the future." It already is, here.
    // App\Services\CompanyStorageService::limitBytes() is the only reader.
    //
    // warning_*_percent formalize the single 80% color-break that already
    // existed only as a hardcoded frontend constant — CompanyStorageService::
    // warningLevel() is now the one place both the internal quota AND the
    // real Google Drive quota card compute Normal/High/Critical/Full from.
    //
    // drive_quota_cache_minutes bounds how often the real Drive quota
    // (Google's own `about.storageQuota` — a live API call) is refetched on
    // an ordinary Settings > Storage page view; CompanyStorageService::
    // refreshDriveQuota() is the only reader, and any Drive upload/delete/
    // reconnect invalidates it immediately regardless of this TTL.
    'company_storage' => [
        'limit_mb' => env('COMPANY_ATTACHMENT_STORAGE_LIMIT_MB', 10),
        'warning_high_percent'      => env('COMPANY_STORAGE_WARNING_HIGH_PERCENT', 80),
        'warning_critical_percent'  => env('COMPANY_STORAGE_WARNING_CRITICAL_PERCENT', 95),
        'drive_quota_cache_minutes' => env('COMPANY_STORAGE_DRIVE_QUOTA_CACHE_MINUTES', 10),
    ],

    // Used by App\Services\CurrencyConversionService to convert an
    // invoice's amount into a currency its payment gateway actually
    // supports. Free-tier key from https://www.exchangerate-api.com/.
    'exchange_rate' => [
        'key' => env('EXCHANGE_RATE_API_KEY'),
    ],

    // App\Services\AiRouter is the single entry point every AI feature in
    // this app calls (App\Services\AiServiceCatalogService, App\Services\
    // LeadProjectTitleService) — it tries three FREE providers in order,
    // moving to the next on any quota/rate-limit/429/timeout/server error,
    // and returns null (never a paid model, never an exception) once all
    // three are exhausted so callers fall through to their own non-AI
    // default:
    //
    //   1. Groq       (App\Services\GroqClient)
    //   2. Gemini      (App\Services\GeminiClient)
    //   3. OpenRouter  (App\Services\OpenRouterClient) — itself tries every
    //      model in 'free_models' below before giving up.
    //
    // Every model configured across all three MUST be free — this app never
    // falls through to a paid model under any circumstance. 'free_only'
    // below documents that constraint; there is deliberately no paid-model
    // code path for it to disable.
    'ai' => [
        'free_only' => env('AI_FREE_ONLY', true),
    ],

    // Provider #1 in App\Services\AiRouter's fallback order. Groq's API is
    // OpenAI-compatible (same /chat/completions shape as OpenRouter below),
    // and its hosted open-weight models are free to call. No key configured
    // -> GroqClient::call() returns null immediately and AiRouter moves on
    // to Gemini, so this provider is entirely optional.
    //
    // Default model 'openai/gpt-oss-20b' is a REASONING model — it spends
    // part of max_tokens thinking (visible as message.reasoning, separate
    // from message.content) before writing the visible answer, same
    // trade-off already documented on LeadProjectTitleService::MAX_TOKENS
    // for OpenRouter's free models. Too small a max_tokens budget produces
    // finish_reason='length' with empty content, not a short answer —
    // callers in this app already size their budgets generously enough
    // (verified against both LeadProjectTitleService's 4000 and
    // AiServiceCatalogService's 1400) to avoid that.
    'groq' => [
        'key'   => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    ],

    // Provider #2 in App\Services\AiRouter's fallback order, tried when Groq
    // has no key or fails. Talks to Google's Generative Language API
    // directly (not via OpenRouter) using its own request/response shape —
    // see App\Services\GeminiClient. The API key is sent as a header, never
    // a URL query param, so it can't leak into request logs. No key
    // configured -> GeminiClient::call() returns null immediately and
    // AiRouter moves on to OpenRouter.
    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
    ],

    // Provider #3 (last AI attempt) in App\Services\AiRouter's fallback
    // order — an OpenAI-compatible gateway that fronts many model vendors
    // behind one base URL and one key. Powers the Client Portal's AI service
    // catalog (App\Services\AiServiceCatalogService) and project name/title
    // suggestions (App\Services\LeadProjectTitleService), same as Groq and
    // Gemini above — it is simply tried last.
    //
    // The service calls its OpenAI-native /chat/completions route, which is
    // what keeps the code working with ANY model the gateway offers rather
    // than one vendor's dialect.
    //
    // Model ids are 'vendor/model' — e.g. 'openai/gpt-4o',
    // 'anthropic/claude-sonnet-4.5', 'google/gemini-2.5-flash'. Ids ending in
    // ':free', and the special 'openrouter/free' auto-router (picks whichever
    // free model is currently available), cost nothing but run on a shared
    // pool and are frequently rate-limited upstream, so they are not a
    // dependable choice under heavy/production load.
    'openrouter' => [
        'key'      => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model'    => env('OPENROUTER_MODEL', 'openrouter/free'),

        // Free-model fallback chain for App\Services\LeadProjectTitleService
        // (project name / title suggestions) — comma-separated model ids,
        // tried in order; a quota/rate-limit/429/timeout/error on one moves
        // to the next automatically. Every id here MUST be free (an
        // 'openrouter/free' alias, or a 'vendor/model:free' id) — this list
        // is never allowed to fall through to a paid model. Leave unset to
        // use just the single OPENROUTER_MODEL above (no app-level fallback
        // beyond OpenRouter's own routing).
        'free_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPENROUTER_FREE_MODELS', ''))
        ))),
    ],

];
