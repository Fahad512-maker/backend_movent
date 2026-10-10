<?php

namespace App\Console\Commands;

use App\Models\CompanyAdmin;
use App\Models\SubscriptionPolicy;
use App\Services\SubscriptionNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily driver for the Trial/Suspension/Subscription policy (see
 * App\Models\SubscriptionPolicy). Every transition here is status-driven —
 * each step's own WHERE clause only matches admins still in the "before"
 * status, so re-running this command (or a slow/overlapping cron tick) never
 * double-applies a transition. Only the day-threshold reminders (trial ending
 * soon / renewal due) need an explicit dedup check, since their WHERE clause
 * matches the same admin all day — see remindOnce().
 *
 * Order matters: trial expiry is resolved before grace-period expiry, so an
 * admin who lands in grace_period THIS run is correctly left alone (their
 * fresh grace_period_ends_at is in the future) rather than immediately
 * re-evaluated by the very next step.
 */
class CheckSubscriptionLifecycle extends Command
{
    protected $signature = 'subscription:check-lifecycle';

    protected $description = 'Apply trial expiry, grace-period expiry, and renewal-failure transitions, and send trial/renewal reminders — per the Super Admin Subscription Policy';

    public function handle(): int
    {
        $policy = SubscriptionPolicy::current();

        $trialExpired = $this->expireTrials($policy);
        $graceExpired = $this->expireGracePeriods();
        $renewalFailed = $this->expireRenewals($policy);
        $trialReminders = $this->remindTrialEndingSoon($policy);
        $renewalReminders = $this->remindRenewalDue($policy);

        $this->info("Trial expired: {$trialExpired}, Grace period expired (suspended): {$graceExpired}, Renewal failed: {$renewalFailed}, Trial reminders sent: {$trialReminders}, Renewal reminders sent: {$renewalReminders}.");

        return self::SUCCESS;
    }

    private function expireTrials(SubscriptionPolicy $policy): int
    {
        $count = 0;

        CompanyAdmin::where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->each(function (CompanyAdmin $admin) use ($policy, &$count) {
                try {
                    if ($policy->trial_expiry_behavior === 'grace_period' && $policy->grace_period_days > 0) {
                        $admin->update([
                            'subscription_status'  => 'grace_period',
                            'grace_period_ends_at' => now()->addDays($policy->grace_period_days),
                        ]);
                        SubscriptionNotificationService::trialExpired($admin);
                        SubscriptionNotificationService::gracePeriodStarted($admin);
                    } else {
                        $admin->update(['subscription_status' => 'trial_expired']);
                        // trial_expired is itself a blocked status (see
                        // CompanyAdmin::isBlockedFromAccess()) even before any
                        // auto-suspend below — disable now so the sidebar/module
                        // list stops showing the full trial catalog the instant
                        // access is actually cut off.
                        $admin->disableAllModules();
                        SubscriptionNotificationService::trialExpired($admin);

                        if ($policy->auto_suspend_on_trial_expiry) {
                            $this->suspend($admin, 'Trial expired');
                        }
                    }
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('[subscription:check-lifecycle] trial expiry failed for admin ' . $admin->id . ': ' . $e->getMessage());
                }
            });

        return $count;
    }

    private function expireGracePeriods(): int
    {
        $count = 0;

        CompanyAdmin::where('subscription_status', 'grace_period')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', now())
            ->each(function (CompanyAdmin $admin) use (&$count) {
                try {
                    $this->suspend($admin, 'Grace period ended without payment');
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('[subscription:check-lifecycle] grace period expiry failed for admin ' . $admin->id . ': ' . $e->getMessage());
                }
            });

        return $count;
    }

    // A once-active subscription whose subscription_ends_at has passed —
    // the renewal payment either failed or was never attempted.
    private function expireRenewals(SubscriptionPolicy $policy): int
    {
        $count = 0;

        CompanyAdmin::where('subscription_status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<=', now())
            ->each(function (CompanyAdmin $admin) use ($policy, &$count) {
                try {
                    if ($policy->renewal_failure_behavior === 'grace_period' && $policy->grace_period_days > 0) {
                        $admin->update([
                            'subscription_status'  => 'grace_period',
                            'grace_period_ends_at' => now()->addDays($policy->grace_period_days),
                        ]);
                        SubscriptionNotificationService::gracePeriodStarted($admin);
                        $count++;
                    } elseif ($policy->auto_suspend_on_renewal_failure) {
                        $this->suspend($admin, 'Subscription renewal payment not received');
                        $count++;
                    }
                    // Neither grace period nor auto-suspend enabled — Super
                    // Admin has opted into fully manual handling; leave status
                    // as 'active' until they intervene via Edit Company Admin.
                } catch (\Throwable $e) {
                    Log::warning('[subscription:check-lifecycle] renewal expiry failed for admin ' . $admin->id . ': ' . $e->getMessage());
                }
            });

        return $count;
    }

    private function remindTrialEndingSoon(SubscriptionPolicy $policy): int
    {
        $count = 0;

        foreach ($policy->renewal_reminder_days as $days) {
            $days = (int) $days;
            if ($days <= 0) continue;
            $type = "trial_ending_soon_{$days}d";
            $target = now()->addDays($days)->toDateString();

            CompanyAdmin::where('subscription_status', 'trial')
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', $target)
                ->each(function (CompanyAdmin $admin) use ($type, $days, &$count) {
                    if (SubscriptionNotificationService::alreadyNotified($admin, $type)) return;
                    try {
                        SubscriptionNotificationService::trialEndingSoon($admin, $days);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('[subscription:check-lifecycle] trial reminder failed for admin ' . $admin->id . ': ' . $e->getMessage());
                    }
                });
        }

        return $count;
    }

    private function remindRenewalDue(SubscriptionPolicy $policy): int
    {
        $count = 0;

        foreach ($policy->renewal_reminder_days as $days) {
            $days = (int) $days;
            if ($days <= 0) continue;
            $type = "renewal_due_{$days}d";
            $target = now()->addDays($days)->toDateString();

            CompanyAdmin::where('subscription_status', 'active')
                ->whereNotNull('subscription_ends_at')
                ->whereDate('subscription_ends_at', $target)
                ->each(function (CompanyAdmin $admin) use ($type, $days, &$count) {
                    if (SubscriptionNotificationService::alreadyNotified($admin, $type)) return;
                    try {
                        SubscriptionNotificationService::renewalDue($admin, $days);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('[subscription:check-lifecycle] renewal reminder failed for admin ' . $admin->id . ': ' . $e->getMessage());
                    }
                });
        }

        return $count;
    }

    private function suspend(CompanyAdmin $admin, string $reason): void
    {
        $admin->update([
            'subscription_status'  => 'suspended',
            'suspended_at'         => now(),
            'suspended_reason'     => $reason,
            'grace_period_ends_at' => null,
        ]);
        // Redundant (but harmless) when this follows the trial_expired branch
        // above, which already disabled — covers the other two callers here
        // (grace-period expiry, renewal failure), which suspend directly from
        // an unblocked status where modules were still enabled.
        $admin->disableAllModules();
        SubscriptionNotificationService::accountSuspended($admin, $reason);
    }
}
