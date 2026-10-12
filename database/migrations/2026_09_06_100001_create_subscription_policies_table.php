<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single-row (id=1) global policy Super Admin controls end-to-end — distinct
// from `packages`, which is per-plan pricing. This table holds the knobs that
// apply platform-wide regardless of which package a tenant is on: whether
// trial is offered at all, its default length and module set, grace period,
// renewal reminder cadence, and what happens automatically on trial/renewal
// expiry. SubscriptionPolicy::current() (app/Models/SubscriptionPolicy.php)
// is the only accessor — always firstOrCreate(['id' => 1], defaults).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_policies')) {
            return;
        }

        Schema::create('subscription_policies', function (Blueprint $table) {
            $table->id();
            $table->boolean('trial_enabled')->default(true);
            $table->unsignedInteger('trial_duration_days')->default(15);
            // Null/empty = every module available during trial (matches the
            // "All Modules Available" default in the policy spec). A non-empty
            // array restricts trial-period access to just those module keys.
            $table->json('trial_modules')->nullable();
            $table->unsignedInteger('grace_period_days')->default(3);
            // Days-before-expiry thresholds a renewal/trial-ending reminder
            // fires on, e.g. [7,3,1].
            $table->json('renewal_reminder_days')->nullable();
            $table->boolean('auto_suspend_on_trial_expiry')->default(true);
            $table->boolean('auto_suspend_on_renewal_failure')->default(true);
            // 'grace_period' = land in grace_period status first (own knob
            // above controls how long); 'suspend_immediately' = skip straight
            // to suspended with no grace window.
            $table->enum('trial_expiry_behavior', ['grace_period', 'suspend_immediately'])->default('grace_period');
            $table->enum('renewal_failure_behavior', ['grace_period', 'suspend_immediately'])->default('grace_period');
            // Free-text policy notes shown to Company Admin on the billing/
            // plan-selection screen (refund policy, accepted payment methods,
            // etc.) — deliberately unstructured, Super Admin owns the wording.
            $table->text('payment_policy_notes')->nullable();
            $table->foreignId('updated_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_policies');
    }
};
