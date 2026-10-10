<?php

namespace App\Console\Commands;

use App\Support\FrontendUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnoses "email is not going out on live".
 *
 * Exists because every outbound mail in this app is wrapped in a
 * swallow-and-log try/catch — by design, so a dead SMTP host can never fail
 * the invoice/task/project action that triggered it. The cost of that is the
 * UI only ever shows a generic "Invoice created, but email could not be
 * sent", and the real exception lands in storage/logs/laravel.log where
 * nobody looks. This command re-runs the same send with the exception
 * surfaced, plus the environment checks that account for almost every "works
 * locally, fails on live" case: a stale config cache, missing queue tables,
 * no worker, and a frontend URL still pointing at localhost.
 */
class MailDoctor extends Command
{
    protected $signature = 'mail:doctor {--to= : Address to send a real test email to}';

    protected $description = 'Report the effective mail/queue setup and optionally send a real test email';

    public function handle(): int
    {
        $problems = [];

        $this->components->info('Mail configuration (as the application actually sees it)');

        $mailer = config('mail.default');
        $smtp   = config('mail.mailers.' . $mailer, []);

        $this->table(['Setting', 'Value'], [
            ['mail.default',     $mailer],
            ['transport',        $smtp['transport'] ?? '(n/a)'],
            ['host',             $smtp['host'] ?? '(n/a)'],
            ['port',             $smtp['port'] ?? '(n/a)'],
            ['scheme',           $smtp['scheme'] ?? '(null — Symfony auto-enables TLS on port 465)'],
            ['username',         $smtp['username'] ?? '(none)'],
            ['password',         !empty($smtp['password']) ? '(set, ' . strlen((string) $smtp['password']) . ' chars)' : '(EMPTY)'],
            ['from address',     config('mail.from.address') ?: '(EMPTY)'],
            ['from name',        config('mail.from.name') ?: '(EMPTY)'],
        ]);

        // 1. Stale config cache — the single most common cause of "works
        //    locally, fails on live". Once config:cache has run, env() returns
        //    null everywhere, so a MAIL_* value added or changed in .env
        //    afterwards is simply not seen: host silently falls back to the
        //    config default 127.0.0.1:2525 and every connection is refused.
        if (file_exists($cached = base_path('bootstrap/cache/config.php'))) {
            $this->newLine();
            $this->components->warn('Config cache is ACTIVE (bootstrap/cache/config.php, written '
                . date('Y-m-d H:i:s', filemtime($cached)) . ').');
            $this->line('  Values above come from that cache, NOT from .env. If .env changed after that');
            $this->line('  timestamp, run:  php artisan config:clear && php artisan config:cache');
            $problems[] = 'config cache is active — confirm it was rebuilt after the last .env change';
        }

        if (($smtp['host'] ?? null) === '127.0.0.1' && (int) ($smtp['port'] ?? 0) === 2525) {
            $problems[] = 'host/port are the framework defaults (127.0.0.1:2525) — MAIL_HOST is not reaching the app';
        }
        if (empty($smtp['password']) && ($smtp['transport'] ?? null) === 'smtp') {
            $problems[] = 'MAIL_PASSWORD is empty';
        }
        if (!config('mail.from.address')) {
            $problems[] = 'MAIL_FROM_ADDRESS is empty — most providers reject a message with no From';
        }

        // 2. Every link inside those emails comes from here.
        $this->newLine();
        $this->components->info('Link building (php artisan frontend:url for detail)');

        $fe = FrontendUrl::describe();
        $this->table(['Setting', 'Value'], [
            ['Resolved base',         $fe['base']],
            ['Source',                $fe['source']],
            ['FRONTEND_URL (config)', $fe['configured']],
        ]);
        if (str_contains($fe['source'], 'loopback')) {
            $problems[] = 'FRONTEND_URL is unset/localhost — payment, reset and invite links will point at localhost';
        }

        // 3. Queued notification mail needs its tables and a worker. The
        //    invoice/password emails send synchronously and work without
        //    these; every mail added for the notification events
        //    (EventNotificationMail, TaskAssignedMail,
        //    ProjectManagerAssignedMail) is ShouldQueue.
        $this->newLine();
        $this->components->info('Queue (needed for task/project/lead/ticket notification emails)');

        $jobsExists   = Schema::hasTable('jobs');
        $failedExists = Schema::hasTable('failed_jobs');

        $rows = [
            ['queue.default',     config('queue.default')],
            ['jobs table',        $jobsExists ? 'exists' : 'MISSING — run php artisan migrate'],
            ['failed_jobs table', $failedExists ? 'exists' : 'MISSING — run php artisan migrate'],
        ];

        if (!$jobsExists) {
            $problems[] = 'the `jobs` table does not exist — every queued notification email fails to enqueue';
        } else {
            $pending = DB::table('jobs')->count();
            $oldest  = DB::table('jobs')->min('created_at');
            $rows[] = ['jobs pending', (string) $pending];
            $rows[] = ['oldest pending job', $oldest ? date('Y-m-d H:i:s', (int) $oldest) : '—'];

            // A backlog whose oldest entry is old means jobs are being written
            // but nothing is draining them.
            if ($pending > 0 && $oldest && (time() - (int) $oldest) > 600) {
                $problems[] = "{$pending} job(s) pending, oldest " . round((time() - (int) $oldest) / 60)
                    . ' min old — no queue worker is running (php artisan queue:work)';
            }
        }

        if ($failedExists && ($failed = DB::table('failed_jobs')->count()) > 0) {
            $rows[] = ['failed jobs', (string) $failed];
            $problems[] = "{$failed} failed job(s) — inspect with php artisan queue:failed";
        }

        $this->table(['Setting', 'Value'], $rows);

        // 4. The real send. Everything above can look right and the provider
        //    can still refuse the connection (outbound 465/587 blocked by the
        //    host, credentials rejected, IP not authorised) — only an actual
        //    attempt with the exception surfaced settles it.
        if ($to = $this->option('to')) {
            $this->newLine();
            $this->components->info("Sending a real test email to {$to} via '{$mailer}'");

            try {
                Mail::raw(
                    'mail:doctor test from ' . config('app.name') . ' at ' . now()->toDateTimeString()
                        . "\n\nIf you are reading this, SMTP works and the problem is elsewhere.",
                    fn ($m) => $m->to($to)->subject('Movent mail:doctor test')
                );

                $this->components->info('Send returned with no exception.');
                if ($mailer === 'log') {
                    $this->line('  NOTE: mail.default is "log" — nothing was delivered, it was written to');
                    $this->line('  storage/logs/laravel.log. Set MAIL_MAILER=smtp to send for real.');
                } else {
                    $this->line('  Check the inbox (and spam). If it never arrives, the provider accepted the');
                    $this->line('  message and then dropped it — check the sending domain\'s SPF/DKIM records.');
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->components->error('SEND FAILED — this is the exception the app has been swallowing:');
                $this->line('  ' . get_class($e));
                $this->line('  ' . $e->getMessage());
                $problems[] = 'the SMTP send itself fails: ' . $e->getMessage();
            }
        } else {
            $this->newLine();
            $this->line('Re-run with --to=you@example.com to attempt a real send and see the real error.');
        }

        $this->newLine();
        if ($problems) {
            $this->components->error('Found ' . count($problems) . ' likely problem(s):');
            foreach ($problems as $i => $p) {
                $this->line('  ' . ($i + 1) . '. ' . $p);
            }

            return self::FAILURE;
        }

        $this->components->info('No configuration problems detected.');

        return self::SUCCESS;
    }
}
