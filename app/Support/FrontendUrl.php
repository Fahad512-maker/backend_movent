<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The single place that decides what the SPA's base URL is.
 *
 * Every share/action link this backend hands out — invoice payment links,
 * password resets, user invites, gateway return/cancel URLs, the buttons in
 * every notification email — used to build its own string from
 * config('app.frontend_url'), in 20-odd places, some with rtrim() and some
 * without. Two consequences that this class exists to remove:
 *
 *  1. config('app.frontend_url') falls back to http://localhost:3000. A
 *     deployment that never set FRONTEND_URL (or whose config cache predates
 *     setting it) therefore emails clients a localhost payment link, silently
 *     and with no error anywhere. That is what base() guards against.
 *  2. The call sites that concatenated without rtrim() produced
 *     "https://host//pay/invoice/…" whenever FRONTEND_URL carried a trailing
 *     slash. to() normalises both sides exactly once.
 *
 * Resolution order, highest first:
 *
 *  1. A configured FRONTEND_URL that is NOT a loopback host. This is the
 *     correct production setup and always wins.
 *  2. Only when (1) is loopback — i.e. the placeholder default is still in
 *     force — the current request's own scheme+host, provided that is not
 *     itself loopback. This is what makes a deployment self-correct instead
 *     of emitting localhost links, and it is safe because it uses the host
 *     the web server already routed this request on, never the caller's
 *     Origin/Referer header (an attacker who could choose the host would
 *     otherwise get their own domain into a client's invoice email).
 *  3. The configured value as-is, so local development is untouched.
 *
 * Step 2 cannot cover a split deployment where the SPA and this API live on
 * different hosts (app.example.com + api.example.com): there is no signal for
 * that, so it logs a warning and uses the API host. Setting FRONTEND_URL is
 * still the real fix, and `php artisan frontend:url` reports which step is in
 * effect.
 *
 * Note for queued/scheduled code: a worker or console command has no request,
 * so step 2 is unavailable there and FRONTEND_URL must be set. Both mail
 * services already resolve their links during the web request and pass
 * absolute URLs into the Mailable, so queued notification emails are fine —
 * but tasks:notify-overdue and friends run with no request at all.
 */
class FrontendUrl
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];

    /** Memoised per process — resolution is cheap but this runs per link. */
    private static ?string $resolved = null;

    /** Set once per process, so a misconfigured deployment logs one line, not one per link. */
    private static bool $warned = false;

    public static function base(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $configured = rtrim(trim((string) config('app.frontend_url')), '/');

        if ($configured !== '' && !self::isLoopback($configured)) {
            return self::$resolved = $configured;
        }

        $fromRequest = self::currentRequestBase();

        if ($fromRequest !== null) {
            if (!self::$warned) {
                self::$warned = true;
                Log::warning('[frontend-url] FRONTEND_URL is unset or still points at localhost; '
                    . 'falling back to this request\'s host. Share links and email buttons will use it. '
                    . 'Set FRONTEND_URL in .env (then php artisan config:clear) to the SPA\'s real URL — '
                    . 'required for queued and scheduled mail, which has no request to fall back on.', [
                        'configured' => $configured ?: '(empty)',
                        'using'      => $fromRequest,
                    ]);
            }

            return self::$resolved = $fromRequest;
        }

        return self::$resolved = ($configured !== '' ? $configured : 'http://localhost:3000');
    }

    /** Absolute URL for an SPA path. Leading/trailing slashes are normalised. */
    public static function to(string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? self::base() : self::base() . '/' . $path;
    }

    /**
     * Which resolution step is in force, for `php artisan frontend:url`.
     *
     * @return array{base: string, source: string, configured: string, request: ?string}
     */
    public static function describe(): array
    {
        $configured  = rtrim(trim((string) config('app.frontend_url')), '/');
        $fromRequest = self::currentRequestBase();

        $source = match (true) {
            $configured !== '' && !self::isLoopback($configured) => 'FRONTEND_URL (configured)',
            $fromRequest !== null                                 => 'current request host (FRONTEND_URL is loopback/unset)',
            default                                               => 'loopback fallback — no request, FRONTEND_URL is loopback/unset',
        };

        return [
            'base'       => self::base(),
            'source'     => $source,
            'configured' => $configured ?: '(empty)',
            'request'    => $fromRequest,
        ];
    }

    /** Test seam — clears the per-process memo. */
    public static function flush(): void
    {
        self::$resolved = null;
        self::$warned   = false;
    }

    /**
     * This request's own scheme+host, or null when there is no request (queue
     * worker, artisan command) or the host is itself loopback.
     */
    private static function currentRequestBase(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }

        $request = request();
        // A console/queue context can still have a bound Request object with
        // an empty host, which getSchemeAndHttpHost() renders as "http://".
        if (!$request || $request->getHost() === '') {
            return null;
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        return self::isLoopback($base) ? null : $base;
    }

    private static function isLoopback(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: $url));

        return in_array($host, self::LOOPBACK_HOSTS, true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }
}
