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
        if (Schema::hasTable('company_payment_gateways')) return;

        Schema::create('company_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 30); // paypal | stripe | authorize_net
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable(); // API keys stored here
            $table->timestamps();

            $table->unique(['company_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_gateways');
    }
};
