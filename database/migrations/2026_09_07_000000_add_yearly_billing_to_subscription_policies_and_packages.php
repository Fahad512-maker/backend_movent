<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_policies', function (Blueprint $table) {
            // Applies to every yearly package automatically — see
            // App\Services\YearlyPackageSync. Monthly packages are never
            // discounted; this is the only lever for a yearly discount.
            $table->decimal('yearly_discount_percent', 5, 2)->default(0)->after('renewal_failure_behavior');
        });

        Schema::table('packages', function (Blueprint $table) {
            // Non-null on a package row means "this is the system-generated
            // yearly companion of that monthly package" — see
            // App\Services\YearlyPackageSync. Such rows are not directly
            // editable via the normal package create/edit endpoints.
            $table->foreignId('monthly_package_id')->nullable()->after('id')
                ->constrained('packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('monthly_package_id');
        });

        Schema::table('subscription_policies', function (Blueprint $table) {
            $table->dropColumn('yearly_discount_percent');
        });
    }
};
