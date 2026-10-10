<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Collapses the Deal Workflow down to the only decision it actually needs to
// express: WHEN a client's payment creates the project — after the invoice is
// paid in full, or as soon as any payment lands. project_creation_trigger is
// kept and narrowed to those two values ('full_payment' already existed);
// allow_admin_override is kept because it still gates the manual pre-payment
// handoff in Api\User\ProjectController::store().
//
// Everything else is dropped. Seven of the eight dropped columns were never
// read by any code — only written by the settings screen and read straight back
// (verified by grep across app/, frontend/, routes/): require_seller_confirmation,
// require_finance_verification, default_advance_percentage,
// minimum_advance_percentage, notify_seller_on_payment,
// notify_ops_on_project_created, plus the three retired trigger values.
//
// The two that WERE live are folded into the trigger:
//   auto_create_project          — redundant now that both trigger options
//                                  create a project; the choice itself is the
//                                  switch.
//   allow_partial_payment_start  — becomes trigger === 'partial_payment', and is
//                                  what the value migration below keys off so no
//                                  tenant's behaviour changes silently.
return new class extends Migration
{
    private const DROPPED = [
        'auto_create_project',
        'allow_partial_payment_start',
        'require_seller_confirmation',
        'require_finance_verification',
        'default_advance_percentage',
        'minimum_advance_percentage',
        'notify_seller_on_payment',
        'notify_ops_on_project_created',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('company_deal_settings')) {
            return;
        }

        // Map every existing row onto the new two-value trigger BEFORE the old
        // columns disappear. allow_partial_payment_start was the real switch
        // (the retired trigger values — kickoff_amount/deposit_received/
        // manual_finance/admin_approval — were never read by anything), so it
        // decides the outcome and no tenant silently changes mode.
        if (Schema::hasColumn('company_deal_settings', 'allow_partial_payment_start')) {
            DB::table('company_deal_settings')
                ->where('allow_partial_payment_start', true)
                ->update(['project_creation_trigger' => 'partial_payment']);

            DB::table('company_deal_settings')
                ->where('allow_partial_payment_start', false)
                ->update(['project_creation_trigger' => 'full_payment']);
        }

        // Anything still holding a retired value (e.g. a row written before the
        // column above existed) lands on the safer of the two.
        DB::table('company_deal_settings')
            ->whereNotIn('project_creation_trigger', ['full_payment', 'partial_payment'])
            ->update(['project_creation_trigger' => 'full_payment']);

        Schema::table('company_deal_settings', function (Blueprint $table) {
            foreach (self::DROPPED as $column) {
                if (Schema::hasColumn('company_deal_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Old default was 'kickoff_amount', which no longer exists.
        DB::statement("ALTER TABLE company_deal_settings MODIFY COLUMN project_creation_trigger VARCHAR(30) NOT NULL DEFAULT 'full_payment'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_deal_settings')) {
            return;
        }

        Schema::table('company_deal_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('company_deal_settings', 'auto_create_project')) {
                $table->boolean('auto_create_project')->default(false);
            }
            if (!Schema::hasColumn('company_deal_settings', 'allow_partial_payment_start')) {
                $table->boolean('allow_partial_payment_start')->default(false);
            }
            if (!Schema::hasColumn('company_deal_settings', 'require_seller_confirmation')) {
                $table->boolean('require_seller_confirmation')->default(true);
            }
            if (!Schema::hasColumn('company_deal_settings', 'require_finance_verification')) {
                $table->boolean('require_finance_verification')->default(true);
            }
            if (!Schema::hasColumn('company_deal_settings', 'default_advance_percentage')) {
                $table->decimal('default_advance_percentage', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('company_deal_settings', 'minimum_advance_percentage')) {
                $table->decimal('minimum_advance_percentage', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('company_deal_settings', 'notify_seller_on_payment')) {
                $table->boolean('notify_seller_on_payment')->default(true);
            }
            if (!Schema::hasColumn('company_deal_settings', 'notify_ops_on_project_created')) {
                $table->boolean('notify_ops_on_project_created')->default(true);
            }
        });

        // Restore the pre-simplification meaning of the two live columns.
        DB::table('company_deal_settings')
            ->where('project_creation_trigger', 'partial_payment')
            ->update(['allow_partial_payment_start' => true, 'auto_create_project' => true]);

        DB::table('company_deal_settings')
            ->where('project_creation_trigger', 'full_payment')
            ->update(['allow_partial_payment_start' => false, 'auto_create_project' => true]);

        DB::statement("ALTER TABLE company_deal_settings MODIFY COLUMN project_creation_trigger VARCHAR(30) NOT NULL DEFAULT 'kickoff_amount'");
    }
};
