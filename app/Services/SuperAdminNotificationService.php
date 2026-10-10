<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\Notification;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Log;

// The Super Admin's bell — and the only thing that writes to it.
//
// The Super Admin runs the PLATFORM, not any one tenant: accounts, companies,
// money, subscriptions, and platform-wide alerts. Nothing from inside a
// tenant's day-to-day (a task moved, an invoice sent, a project comment) has
// any business here, and the way that is enforced is TYPES below — the feed
// (Api\SuperAdmin\NotificationController) only ever returns rows whose type is
// a key of that map, so a row written with any other type simply never shows.
// One list, one place to change, no way for a caller to widen it by accident.
//
// Every method is best-effort: these fire from registration, payment and cron
// paths where the real work is already committed, so a notification failure
// must never surface as an error to whoever triggered it.
class SuperAdminNotificationService
{
    // type => category. This IS the Super Admin's notification permission
    // list: anything not here is not a Super Admin concern and is not shown.
    public const TYPES = [
        // Accounts — Company Admin (tenant owner) accounts
        'company_admin_registered'   => 'accounts',
        'company_admin_created'      => 'accounts',
        'company_admin_activated'    => 'accounts',
        'company_admin_deactivated'  => 'accounts',

        // Companies — the tenants themselves
        'company_registered'         => 'companies',
        'company_activated'          => 'companies',
        'company_deactivated'        => 'companies',

        // Money in
        'payment_successful'         => 'payments',
        'payment_failed'             => 'payments',
        'payment_refunded'           => 'payments',

        // Subscription lifecycle
        'subscription_purchased'     => 'subscriptions',
        'subscription_renewed'       => 'subscriptions',
        'subscription_expired'       => 'subscriptions',
        'subscription_suspended'     => 'subscriptions',
        'subscription_reactivated'   => 'subscriptions',
        // Manual access override — deliberately distinct from
        // subscription_reactivated above, which reads "active again after
        // payment." This one never implies the tenant paid.
        'access_override_granted'    => 'subscriptions',
        'access_override_revoked'    => 'subscriptions',

        // Platform-wide
        'system_alert'               => 'system',
        'compliance_alert'           => 'compliance',
        'support_escalated'          => 'support',
    ];

    // Display names for the feed's category filter.
    public const CATEGORIES = [
        'accounts'      => 'Company Admin Accounts',
        'companies'     => 'Companies',
        'payments'      => 'Payments',
        'subscriptions' => 'Subscriptions',
        'system'        => 'System Alerts',
        'compliance'    => 'Compliance Alerts',
        'support'       => 'Support Escalations',
    ];

    public static function categoryFor(?string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    // One row per active Super Admin. There is normally exactly one account,
    // but the table allows more and a platform event concerns all of them.
    //
    // $opts: company_id, entity_type, entity_id, url, data.
    private static function send(string $type, string $title, string $body, array $opts = []): void
    {
        // A typo'd type would write a row the feed then silently hides —
        // louder to refuse it here than to lose the notification.
        if (!isset(self::TYPES[$type])) {
            Log::warning("[SuperAdminNotificationService] unknown notification type '{$type}' — not sent.");
            return;
        }

        try {
            $recipients = SuperAdmin::where('is_active', true)->pluck('id');

            foreach ($recipients as $superAdminId) {
                Notification::create([
                    'recipient_super_admin_id' => $superAdminId,
                    // Null for events that belong to no single tenant (a new
                    // admin account, a subscription payment) — the column was
                    // made nullable for exactly this.
                    'company_id'  => $opts['company_id'] ?? null,
                    'type'        => $type,
                    'module'      => self::TYPES[$type],
                    'title'       => $title,
                    'body'        => $body,
                    'entity_type' => $opts['entity_type'] ?? null,
                    'entity_id'   => $opts['entity_id'] ?? null,
                    'url'         => $opts['url'] ?? null,
                    // Mirrored into data.link as well, matching
                    // NotificationService::send() — the frontend bell reads
                    // that key for every other feed in the app.
                    'data'        => array_filter([
                        'link'     => $opts['url'] ?? null,
                        'category' => self::TYPES[$type],
                    ] + ($opts['data'] ?? []), fn ($v) => $v !== null),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── Accounts ────────────────────────────────────────────────────────────

    // Self sign-up through the public registration form.
    public static function companyAdminRegistered(CompanyAdmin $admin, ?string $packageName = null): void
    {
        self::send(
            'company_admin_registered',
            'New Company Admin account',
            "{$admin->name} ({$admin->email}) signed up"
                . ($packageName ? " on the {$packageName} plan." : '.'),
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // Created by the Super Admin from the console.
    public static function companyAdminCreated(CompanyAdmin $admin): void
    {
        self::send(
            'company_admin_created',
            'Company Admin account created',
            "{$admin->name} ({$admin->email}) was added from the console.",
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // is_active on the account — the approval switch for a Company Admin.
    public static function companyAdminStatusChanged(CompanyAdmin $admin, bool $isActive): void
    {
        self::send(
            $isActive ? 'company_admin_activated' : 'company_admin_deactivated',
            $isActive ? 'Company Admin approved' : 'Company Admin access revoked',
            "{$admin->name} ({$admin->email}) was " . ($isActive ? 'activated' : 'deactivated') . '.',
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // ── Companies ───────────────────────────────────────────────────────────

    public static function companyRegistered(Company $company, ?CompanyAdmin $admin = null): void
    {
        self::send(
            'company_registered',
            'New company registered',
            "\"{$company->name}\" was registered"
                . ($admin ? " by {$admin->name} ({$admin->email})." : '.'),
            [
                'company_id'  => $company->id,
                'entity_type' => 'Company',
                'entity_id'   => $company->id,
                'url'         => '/super-admin/companies',
            ]
        );
    }

    public static function companyStatusChanged(Company $company, bool $isActive): void
    {
        self::send(
            $isActive ? 'company_activated' : 'company_deactivated',
            $isActive ? 'Company activated' : 'Company suspended',
            "\"{$company->name}\" was " . ($isActive ? 'activated' : 'suspended') . '.',
            [
                'company_id'  => $company->id,
                'entity_type' => 'Company',
                'entity_id'   => $company->id,
                'url'         => '/super-admin/companies',
            ]
        );
    }

    // ── Payments ────────────────────────────────────────────────────────────

    // $what says which kind of purchase it was ("subscription", "module
    // upgrade", "extra seats") — the amount alone doesn't tell the operator
    // what was actually bought.
    public static function paymentSucceeded(CompanyAdmin $admin, string $amount, string $what = 'subscription', ?string $gateway = null): void
    {
        self::send(
            'payment_successful',
            'Payment received',
            "{$amount} from {$admin->name} ({$admin->email}) for {$what}"
                . ($gateway ? " via {$gateway}." : '.'),
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    public static function paymentFailed(CompanyAdmin $admin, string $amount, string $what = 'subscription', ?string $reason = null): void
    {
        self::send(
            'payment_failed',
            'Payment failed',
            "{$amount} from {$admin->name} ({$admin->email}) for {$what} did not go through"
                . ($reason ? ": {$reason}" : '.'),
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // No refund flow exists in the app yet — this is here so that when one is
    // added it has a notification to call, rather than inventing a type that
    // TYPES above would then reject.
    public static function paymentRefunded(CompanyAdmin $admin, string $amount, ?string $reason = null): void
    {
        self::send(
            'payment_refunded',
            'Payment refunded',
            "{$amount} was refunded to {$admin->name} ({$admin->email})"
                . ($reason ? ": {$reason}" : '.'),
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // ── Subscriptions ───────────────────────────────────────────────────────

    // $isRenewal separates "a new subscriber" from "an existing one paid
    // again" — the first is growth, the second is retention, and an operator
    // reads them differently.
    public static function subscriptionPaid(CompanyAdmin $admin, bool $isRenewal, ?string $packageName = null, ?string $periodEnd = null): void
    {
        $plan = $packageName ? " ({$packageName})" : '';

        self::send(
            $isRenewal ? 'subscription_renewed' : 'subscription_purchased',
            $isRenewal ? 'Subscription renewed' : 'Subscription purchased',
            "{$admin->name}{$plan} " . ($isRenewal ? 'renewed' : 'started') . ' a subscription'
                . ($periodEnd ? ", now paid until {$periodEnd}." : '.'),
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    public static function subscriptionExpired(CompanyAdmin $admin, string $what = 'Trial'): void
    {
        self::send(
            'subscription_expired',
            "{$what} expired",
            "{$what} for {$admin->name} ({$admin->email}) has expired.",
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    public static function subscriptionSuspended(CompanyAdmin $admin, string $reason): void
    {
        self::send(
            'subscription_suspended',
            'Account suspended',
            "{$admin->name} ({$admin->email}) was suspended: {$reason}.",
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    public static function subscriptionReactivated(CompanyAdmin $admin): void
    {
        self::send(
            'subscription_reactivated',
            'Account reactivated',
            "{$admin->name} ({$admin->email}) is active again after payment.",
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // CompanyAdminController::toggleAccessOverride() — a manual grant/revoke
    // of access_override, NOT a payment. Kept separate from
    // subscriptionReactivated() above so the feed never implies a tenant
    // paid when Super Admin simply chose to let them in anyway.
    public static function accessOverrideChanged(CompanyAdmin $admin, bool $granted): void
    {
        self::send(
            $granted ? 'access_override_granted' : 'access_override_revoked',
            $granted ? 'Access override granted' : 'Access override revoked',
            $granted
                ? "{$admin->name} ({$admin->email}) was granted manual access despite subscription status \"{$admin->effective_subscription_status}\"."
                : "{$admin->name} ({$admin->email})'s manual access override was removed — access now follows their real subscription status again.",
            ['entity_type' => 'CompanyAdmin', 'entity_id' => $admin->id, 'url' => "/super-admin/admins/{$admin->id}/edit"]
        );
    }

    // ── Platform-wide ───────────────────────────────────────────────────────

    // Anything operational the platform owner must know about — a failing
    // integration, a job that keeps dying. Open by design: callers supply
    // their own wording rather than this file growing a method per incident.
    public static function systemAlert(string $title, string $body, array $opts = []): void
    {
        self::send('system_alert', $title, $body, $opts);
    }

    public static function complianceAlert(string $title, string $body, ?int $companyId = null, array $opts = []): void
    {
        self::send('compliance_alert', $title, $body, ['company_id' => $companyId] + $opts);
    }

    // A tenant's support ticket that is urgent enough to be the platform's
    // problem too — see the callers for what qualifies.
    public static function supportEscalated(int $ticketId, string $subject, ?string $companyName, ?int $companyId = null): void
    {
        self::send(
            'support_escalated',
            'Urgent support ticket',
            "#{$ticketId} \"{$subject}\"" . ($companyName ? " from {$companyName}." : '.'),
            [
                'company_id'  => $companyId,
                'entity_type' => 'SupportTicket',
                'entity_id'   => $ticketId,
            ]
        );
    }
}
