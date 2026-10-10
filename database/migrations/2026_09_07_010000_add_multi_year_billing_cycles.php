<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL has no ALTER TYPE for enums — recreate the column with the
        // widened value list, preserving existing data.
        DB::statement("ALTER TABLE packages MODIFY billing_cycle ENUM('monthly','yearly','2_year','3_year') NULL");

        Schema::table('subscription_policies', function (Blueprint $table) {
            // Same idea as yearly_discount_percent — applied automatically to
            // every package's 2-Year / 3-Year companion (see YearlyPackageSync).
            $table->decimal('two_year_discount_percent', 5, 2)->default(0)->after('yearly_discount_percent');
            $table->decimal('three_year_discount_percent', 5, 2)->default(0)->after('two_year_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_policies', function (Blueprint $table) {
            $table->dropColumn(['two_year_discount_percent', 'three_year_discount_percent']);
        });

        DB::table('packages')->whereIn('billing_cycle', ['2_year', '3_year'])->delete();
        DB::statement("ALTER TABLE packages MODIFY billing_cycle ENUM('monthly','yearly') NULL");
    }
};
