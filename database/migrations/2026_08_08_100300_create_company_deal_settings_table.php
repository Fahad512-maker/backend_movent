<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenant-level (Company Admin) configuration for the Lead-Won -> Deal
// eligibility -> Project creation workflow — mirrors the tenant-scoping
// pattern already used by company_payment_gateways (one shared config per
// Company Admin account, not per individual company).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_deal_settings')) {
            return;
        }

        Schema::create('company_deal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_admin_id')->unique()->constrained('company_admins')->cascadeOnDelete();
            $table->string('project_creation_trigger', 30)->default('kickoff_amount');
            $table->boolean('auto_create_project')->default(false);
            $table->boolean('require_seller_confirmation')->default(true);
            $table->boolean('require_finance_verification')->default(true);
            $table->boolean('allow_admin_override')->default(true);
            $table->boolean('allow_partial_payment_start')->default(false);
            $table->decimal('default_advance_percentage', 5, 2)->nullable();
            $table->decimal('minimum_advance_percentage', 5, 2)->nullable();
            $table->boolean('notify_seller_on_payment')->default(true);
            $table->boolean('notify_ops_on_project_created')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_deal_settings');
    }
};
