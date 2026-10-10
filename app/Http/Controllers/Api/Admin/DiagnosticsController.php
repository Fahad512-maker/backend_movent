<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\FrontendUrl;
use App\Support\MailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The `mail:doctor` / `frontend:url` console commands over HTTP.
 *
 * Exists because the production host has no shell: no artisan, no
 * `config:clear`, no `queue:work`, no way to read storage/logs. Every
 * outbound mail in this app is wrapped in a swallow-and-log try/catch (by
 * design — a dead SMTP host must never fail the invoice save it rode in on),
 * so without this endpoint the only symptom available on live is the generic
 * "Invoice created, but email could not be sent" and the real exception is
 * unreachable.
 *
 * Behind `auth:admin`, so only a signed-in Company Admin can reach it. The
 * SMTP password is never returned — only whether one is set and its length.
 */
class DiagnosticsController extends Controller
{
    /** GET /admin/diagnostics/mail */
    public function mail(): JsonResponse
    {
        $mailer = config('mail.default');
        $smtp   = config('mail.mailers.' . $mailer, []);
        $issues = [];

        // A cached config is the classic "works locally, fails on live":
        // once config:cache has run, env() returns null everywhere, so any
        // MAIL_* value added to .env afterwards is simply not seen and the
        // host silently falls back to the framework default 127.0.0.1:2525.
        $configCachedAt = file_exists($p = base_path('bootstrap/cache/config.php'))
            ? date('Y-m-d H:i:s', filemtime($p))
            : null;

        if ($configCachedAt) {
            $issues[] = "Config is cached (built {$configCachedAt}). Values here come from that cache, not .env. "
                . 'If .env changed after that time, delete bootstrap/cache/config.php to pick it up.';
        }
        if (($smtp['host'] ?? null) === '127.0.0.1' && (int) ($smtp['port'] ?? 0) === 2525) {
            $issues[] = 'MAIL_HOST is not reaching the app — host/port are the framework defaults (127.0.0.1:2525).';
        }
        if (empty($smtp['password']) && ($smtp['transport'] ?? null) === 'smtp') {
            $issues[] = 'MAIL_PASSWORD is empty.';
        }
        if (!config('mail.from.address')) {
            $issues[] = 'MAIL_FROM_ADDRESS is empty — most providers reject a message with no From header.';
        }

        $frontend = FrontendUrl::describe();
        if (str_contains($frontend['source'], 'loopback')) {
            $issues[] = 'FRONTEND_URL is unset or still localhost — payment, reset and invite links will point at localhost.';
        }

        $delivery = MailDelivery::describe();
        if ($delivery['jobs_table'] === 'MISSING' && config('mail.queue_notifications')) {
            $issues[] = 'Queued notification mail is switched on but the `jobs` table does not exist.';
        }

        return ApiResponse::success([
            'mail' => [
                'mailer'       => $mailer,
                'transport'    => $smtp['transport'] ?? null,
                'host'         => $smtp['host'] ?? null,
                'port'         => $smtp['port'] ?? null,
                'scheme'       => $smtp['scheme'] ?? '(null — TLS auto-enabled on port 465)',
                'username'     => $smtp['username'] ?? null,
                // Never the password itself — only enough to tell "set" from
                // "empty" and to spot a truncated paste.
                'password_set' => !empty($smtp['password']),
                'password_len' => !empty($smtp['password']) ? strlen((string) $smtp['password']) : 0,
                'from_address' => config('mail.from.address'),
                'from_name'    => config('mail.from.name'),
            ],
            'links'    => $frontend,
            'delivery' => $delivery,
            'config_cached_at' => $configCachedAt,
            'issues'   => $issues,
        ]);
    }

    /**
     * POST /admin/diagnostics/mail/test  { "email": "you@example.com" }
     *
     * Sends a real message and returns the exception verbatim on failure —
     * the whole point, since that exception is otherwise swallowed. Restricted
     * to the signed-in admin's own address so this can't be used to send mail
     * to arbitrary recipients.
     */
    public function sendTestMail(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();

        $validated = $request->validate([
            'email' => ['nullable', 'email'],
        ]);

        $to = $validated['email'] ?? $admin->email;

        if (strcasecmp($to, (string) $admin->email) !== 0) {
            return ApiResponse::error('Test mail can only be sent to your own account email (' . $admin->email . ').', 422);
        }

        try {
            Mail::raw(
                'Test message from ' . config('app.name') . ' at ' . now()->toDateTimeString()
                    . "\n\nIf you are reading this, SMTP is working and the problem is elsewhere.",
                fn ($m) => $m->to($to)->subject('Movent mail test')
            );
        } catch (\Throwable $e) {
            return ApiResponse::success([
                'sent'      => false,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'hint'      => 'This is the error the app normally swallows. A connection timeout or '
                    . '"Connection refused" usually means the host blocks outbound SMTP; an authentication '
                    . 'failure means the credentials or the from-address are wrong.',
            ], 'Send failed');
        }

        return ApiResponse::success([
            'sent'   => true,
            'to'     => $to,
            'mailer' => config('mail.default'),
            'hint'   => config('mail.default') === 'log'
                ? 'MAIL_MAILER is "log" — nothing was delivered, it was written to storage/logs/laravel.log.'
                : 'Accepted by the mail server. If it never arrives, check spam and the sending domain\'s SPF/DKIM records.',
        ], 'Sent');
    }

    /**
     * POST /admin/diagnostics/run-scheduled
     *
     * The three daily commands (overdue invoices, overdue tasks, expiring
     * compliance documents) normally fire from `schedule:run` via cron. A host
     * with no cron never runs them, so this lets an admin trigger them by
     * hand. Every one of them is idempotent — each already refuses to notify
     * the same invoice/task/document twice — so calling this repeatedly is
     * safe and simply does nothing after the first run of the day.
     */
    public function runScheduled(): JsonResponse
    {
        $results = [];

        foreach (['invoices:mark-overdue', 'tasks:notify-overdue', 'compliance:notify-expiring'] as $command) {
            try {
                Artisan::call($command);
                $results[$command] = trim(Artisan::output());
            } catch (\Throwable $e) {
                $results[$command] = 'FAILED: ' . $e->getMessage();
            }
        }

        return ApiResponse::success($results, 'Scheduled jobs run');
    }
}
