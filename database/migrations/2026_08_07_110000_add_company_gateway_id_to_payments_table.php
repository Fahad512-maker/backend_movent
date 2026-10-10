<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records exactly WHICH gateway account (not just which gateway type) a
// payment was charged through, now that a tenant can have multiple accounts
// per type. Nullable — legacy rows and non-gateway payment methods leave it
// null.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments') || Schema::hasColumn('payments', 'company_gateway_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('company_gateway_id')->nullable()->after('gateway');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'company_gateway_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('company_gateway_id');
            });
        }
    }
};
