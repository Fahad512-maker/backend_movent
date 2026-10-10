<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('date');
            $table->boolean('is_recurring_annual')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
    }
};
