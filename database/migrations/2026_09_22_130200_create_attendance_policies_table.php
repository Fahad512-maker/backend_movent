<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('half_day_threshold_minutes')->default(240);
            $table->unsignedSmallInteger('full_day_threshold_minutes')->default(480);
            $table->boolean('overtime_enabled')->default(false);
            $table->unsignedSmallInteger('overtime_threshold_minutes')->nullable();
            $table->unsignedSmallInteger('default_grace_period_minutes')->default(15);
            // ISO weekday ints (1=Mon..7=Sun). App-level fallback [1,2,3,4,5]
            // when this is also null.
            $table->json('default_working_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
