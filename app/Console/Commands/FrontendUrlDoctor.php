<?php

namespace App\Console\Commands;

use App\Support\FrontendUrl;
use Illuminate\Console\Command;

/**
 * Reports the base URL every share link and email button will be built from,
 * and which resolution step produced it. Run on live when a link comes out
 * pointing at localhost.
 *
 * Note the console caveat this command demonstrates by existing: an artisan
 * process has no HTTP request, so FrontendUrl's request fallback is not
 * available here. If this prints a localhost base, then queued and scheduled
 * mail WILL emit localhost links even if web requests happen to self-correct
 * — FRONTEND_URL has to be set.
 */
class FrontendUrlDoctor extends Command
{
    protected $signature = 'frontend:url';

    protected $description = 'Show the frontend base URL used for share links and email buttons';

    public function handle(): int
    {
        $info = FrontendUrl::describe();

        $this->components->info('Frontend base URL resolution');

        $this->table(['Setting', 'Value'], [
            ['Resolved base',            $info['base']],
            ['Source',                   $info['source']],
            ['FRONTEND_URL (config)',    $info['configured']],
            ['Current request host',     $info['request'] ?? '(none — console has no request)'],
            ['APP_URL',                  config('app.url')],
        ]);

        $this->newLine();
        $this->line('Sample links as they would be generated right now:');
        foreach ([
            'Invoice payment' => 'pay/invoice/EXAMPLE_TOKEN',
            'Password reset'  => 'reset-password?token=EXAMPLE',
            'User invite'     => 'invite/EXAMPLE_TOKEN',
            'Task button'     => 'task/123',
            'Project button'  => 'projects/123',
        ] as $label => $path) {
            $this->line(sprintf('  %-16s %s', $label, FrontendUrl::to($path)));
        }

        if (str_contains($info['source'], 'loopback fallback')) {
            $this->newLine();
            $this->components->error('FRONTEND_URL is not set (or still points at localhost).');
            $this->line('  Every emailed payment link, reset link and invite will point at localhost.');
            $this->line('  Fix: set FRONTEND_URL in .env to the SPA\'s real URL, e.g.');
            $this->line('    FRONTEND_URL=https://app.example.com');
            $this->line('  then: php artisan config:clear   (and config:cache if you cache config)');

            return self::FAILURE;
        }

        if (str_contains($info['source'], 'current request host')) {
            $this->newLine();
            $this->components->warn('Falling back to the request host because FRONTEND_URL is loopback/unset.');
            $this->line('  Web requests will produce correct links only if the SPA is on the SAME host as');
            $this->line('  this API. Queued and scheduled mail has no request and will still use localhost.');
            $this->line('  Set FRONTEND_URL explicitly.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('FRONTEND_URL is set explicitly — links are stable across web, queue and cron.');

        return self::SUCCESS;
    }
}
