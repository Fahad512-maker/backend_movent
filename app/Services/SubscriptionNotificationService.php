<?php

namespace App\Services;

use App\Models\CompanyAdmin;

// Pairs every Trial/Suspension/Subscription lifecycle event with both an
// in-app Notification (bell) and an email (EventMailService), the same way
// every other domain event in this app fires both side by side. Kept in its
// own service (rather than folded into NotificationService/EventMailService
// directly) because every one of these events is keyed to a CompanyAdmin —
// which can own several Companies, or (very briefly, mid-registration) none —
// unlike every other notification in the app, which is keyed to exactly one
// Company. anchorCompanyId() below is the one place that resolves "which
// Company row do we attach the in-app Notification to" for a tenant-level
// event; the email always sends regardless, since EventMailService::
// sendToAdmin() mails the Admin directly and needs no Company at all.
class SubscriptionNotificationService
{
    private static function anchorCompanyId(CompanyAdmin $admin): ?int
    {
        return $admin->companies()->orderBy('id')->value('id');
    }

    private static function notify(CompanyAdmin $admin, string $type, string $title, string $message): void
    {
        $companyId = self::anchorCompanyId($admin);
        if (!$companyId) {
            return;
        }

        NotificationService::send([
            'company_id'         => $companyId,
            'recipient_admin_id' => $admin->id,
            'module'             => 'subscription',
            'type'               => $type,
            'title'              => $title,
            'message'            => $message,
            'entity_type'        => 'CompanyAdmin',
            'entity_id'          => $admin->id,
            'url'                => '/admin/plan',
        ]);
    }

    // Threshold reminders (trial-ending/renewal-due) run off a daily
    // scheduled command — CheckSubscriptionLifecycle::remindOnce() checks
    // this before calling trialEndingSoon()/renewalDue(), so a slow/overlapping
    // cron run can't double-send the same day's threshold.
    public static function alreadyNotified(CompanyAdmin $admin, string $type): bool
    {
        return \App\Models\Notification::where('type', $type)
            ->where('entity_type', 'CompanyAdmin')
            ->where('entity_id', $admin->id)
            ->exists();
    }

    public static function trialStarted(CompanyAdmin $admin, int $durationDays): void
    {
        self::notify($admin, 'trial_started', 'Trial started', "Your {$durationDays}-day free trial has started.");
        EventMailService::trialStarted($admin, $durationDays);
    }

    public static function trialEndingSoon(CompanyAdmin $admin, int $daysLeft): void
    {
        self::notify($admin, "trial_ending_soon_{$daysLeft}d", 'Trial ending soon', "Your free trial ends in {$daysLeft} day(s).");
        EventMailService::trialEndingSoon($admin, $daysLeft);
    }

    // The lifecycle events below also concern the platform owner, not just
    // the tenant — so each one mirrors a line into the Super Admin's own bell
    // (SuperAdminNotificationService, which swallows its own failures). Done
    // here rather than at the call sites because this service is already the
    // single funnel every one of them passes through: the daily
    // CheckSubscriptionLifecycle command, registration, and the payment
    // controller all end up in these methods.
    public static function trialExpired(CompanyAdmin $admin): void
    {
        self::notify($admin, 'trial_expired', 'Trial expired', 'Your free trial has expired. Please activate a subscription to continue using Movent CRM.');
        EventMailService::trialExpired($admin);
        SuperAdminNotificationService::subscriptionExpired($admin, 'Trial');
    }

    public static function gracePeriodStarted(CompanyAdmin $admin): void
    {
        self::notify($admin, 'grace_period_started', 'Grace period started', 'Your access continues for a short grace period. Activate a subscription before it ends to avoid suspension.');
        EventMailService::gracePeriodStarted($admin);
    }

    public static function accountSuspended(CompanyAdmin $admin, string $reason): void
    {
        self::notify($admin, 'account_suspended', 'Account suspended', "Your company account and all its users have been suspended. Reason: {$reason}.");
        EventMailService::accountSuspended($admin, $reason);
        SuperAdminNotificationService::subscriptionSuspended($admin, $reason);
    }

    public static function accountReactivated(CompanyAdmin $admin): void
    {
        self::notify($admin, 'account_reactivated', 'Account reactivated', 'Your subscription is active — your company and all its users have been restored.');
        EventMailService::accountReactivated($admin);
        SuperAdminNotificationService::subscriptionReactivated($admin);
    }

    public static function subscriptionActivated(CompanyAdmin $admin): void
    {
        self::notify($admin, 'subscription_activated', 'Subscription activated', "Your {$admin->package?->name} subscription is now active.");
        EventMailService::subscriptionActivated($admin);
    }

    public static function renewalDue(CompanyAdmin $admin, int $daysLeft): void
    {
        self::notify($admin, "renewal_due_{$daysLeft}d", 'Renewal due', "Your subscription renews in {$daysLeft} day(s).");
        EventMailService::renewalDue($admin, $daysLeft);
    }

    public static function paymentSuccessful(CompanyAdmin $admin, string $amount): void
    {
        self::notify($admin, 'payment_successful', 'Payment successful', "We've received your payment of {$amount}.");
        EventMailService::paymentSuccessful($admin, $amount);
        SuperAdminNotificationService::paymentSucceeded($admin, $amount);
    }

    // $amount is optional and trailing so the existing two-argument shape of
    // this method keeps working; it is only used for the Super Admin's copy,
    // where "which payment failed" is the first thing the operator asks.
    public static function paymentFailed(CompanyAdmin $admin, ?string $reason = null, ?string $amount = null, string $what = 'subscription'): void
    {
        self::notify($admin, 'payment_failed', 'Payment failed', 'We were unable to process your subscription payment.' . ($reason ? " Reason: {$reason}." : ''));
        EventMailService::paymentFailed($admin, $reason);
        SuperAdminNotificationService::paymentFailed($admin, $amount ?? 'A payment', $what, $reason);
    }
}
