<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('subscription_payments')) return;

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('company_admins')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('PKR');
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_ref', 255)->nullable();
            $table->enum('status', ['paid', 'failed', 'refunded'])->default('paid');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
