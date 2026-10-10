<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a Won Lead act as a lightweight "Deal" — the confirmed proposed
// project/service, its kickoff-payment requirement, and a fulfillment
// status distinct from the sales-pipeline `status` enum — without a
// separate deals table. See App\Services\DealEligibilityService.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'deal_reference')) {
                $table->string('deal_reference', 30)->nullable()->unique()->after('status');
            }
            if (!Schema::hasColumn('leads', 'proposed_project_title')) {
                $table->string('proposed_project_title', 255)->nullable()->after('deal_reference');
            }
            if (!Schema::hasColumn('leads', 'service_category')) {
                $table->string('service_category', 100)->nullable()->after('proposed_project_title');
            }
            if (!Schema::hasColumn('leads', 'scope_summary')) {
                $table->text('scope_summary')->nullable()->after('service_category');
            }
            if (!Schema::hasColumn('leads', 'detailed_scope')) {
                $table->text('detailed_scope')->nullable()->after('scope_summary');
            }
            if (!Schema::hasColumn('leads', 'quotation_reference')) {
                $table->string('quotation_reference', 100)->nullable()->after('detailed_scope');
            }
            if (!Schema::hasColumn('leads', 'required_kickoff_amount')) {
                $table->decimal('required_kickoff_amount', 12, 2)->nullable()->after('quotation_reference');
            }
            if (!Schema::hasColumn('leads', 'required_kickoff_percentage')) {
                $table->decimal('required_kickoff_percentage', 5, 2)->nullable()->after('required_kickoff_amount');
            }
            if (!Schema::hasColumn('leads', 'expected_start_date')) {
                $table->date('expected_start_date')->nullable()->after('required_kickoff_percentage');
            }
            if (!Schema::hasColumn('leads', 'expected_end_date')) {
                $table->date('expected_end_date')->nullable()->after('expected_start_date');
            }
            if (!Schema::hasColumn('leads', 'fulfillment_status')) {
                $table->string('fulfillment_status', 30)->nullable()->after('expected_end_date');
            }
            if (!Schema::hasColumn('leads', 'won_at')) {
                $table->timestamp('won_at')->nullable()->after('fulfillment_status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            foreach ([
                'deal_reference', 'proposed_project_title', 'service_category', 'scope_summary',
                'detailed_scope', 'quotation_reference', 'required_kickoff_amount',
                'required_kickoff_percentage', 'expected_start_date', 'expected_end_date',
                'fulfillment_status', 'won_at',
            ] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
