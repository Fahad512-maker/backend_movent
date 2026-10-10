<?php

use App\Support\ScheduleLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every scheduled command is wrapped in ScheduleLogger::wrap() — writes one
// scheduled_task_logs row (success/failed, exit code, output, duration) per
// run, visible to Super Admin (Api\SuperAdmin\ScheduledTaskLogController).
// Before this, a broken cron entry or a command silently throwing looked
// identical to "nothing to do today" — there was no record either way.
//
// Requires the server cron entry `* * * * * php artisan schedule:run` to actually fire.
// All three send email as well as writing in-app notifications, so they are
// staggered rather than all firing at midnight together.
ScheduleLogger::wrap(Schedule::command('invoices:mark-overdue')->dailyAt('01:00'), 'invoices:mark-overdue');
// Finance > Invoice Reminders. Sends only reminders a human already created
// and dated — see App\Console\Commands\SendDueInvoiceReminders. Placed right
// after mark-overdue so an invoice that just became overdue is already
// flagged as such in the reminder email it triggers.
ScheduleLogger::wrap(Schedule::command('invoices:send-due-reminders')->dailyAt('01:10'), 'invoices:send-due-reminders');
ScheduleLogger::wrap(Schedule::command('tasks:notify-overdue')->dailyAt('01:15'), 'tasks:notify-overdue');
ScheduleLogger::wrap(Schedule::command('compliance:notify-expiring')->dailyAt('01:30'), 'compliance:notify-expiring');
ScheduleLogger::wrap(Schedule::command('subscription:check-lifecycle')->dailyAt('01:45'), 'subscription:check-lifecycle');

// Hourly (not daily) — each company's shift-end passes at a different
// company-local time, and the command is idempotent so repeated runs are safe.
ScheduleLogger::wrap(Schedule::command('attendance:mark-absentees')->hourly(), 'attendance:mark-absentees');
