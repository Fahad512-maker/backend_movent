<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_targets')) {
            return;
        }

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // the Seller this target belongs to
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 12, 2)->nullable();   // won-deal value goal
            $table->unsignedInteger('target_deals')->nullable();   // won-deal count goal
            $table->unsignedBigInteger('created_by')->nullable();  // who set the target
            $table->timestamps();

            $table->unique(['user_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
