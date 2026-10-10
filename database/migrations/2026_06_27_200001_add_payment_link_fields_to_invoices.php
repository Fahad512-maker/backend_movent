<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_token', 64)->nullable()->unique()->after('sent_at');
            $table->timestamp('token_expires_at')->nullable()->after('payment_token');
            $table->string('customer_name')->nullable()->after('token_expires_at');
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->string('customer_phone')->nullable()->after('customer_email');
            $table->text('customer_address')->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'payment_token', 'token_expires_at',
                'customer_name', 'customer_email', 'customer_phone', 'customer_address',
            ]);
        });
    }
};
