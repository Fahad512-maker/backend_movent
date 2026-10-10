<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Super Admin-managed multi-year billing options — replaces the fixed
        // Yearly/2-Year/3-Year set (and their 3 hardcoded discount columns on
        // subscription_policies) with an arbitrary, addable/removable list.
        // Monthly billing is never a row here — it's the base package itself.
        Schema::create('billing_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "Yearly", "2-Year", "18 Months"
            $table->unsignedInteger('months'); // e.g. 12, 24, 18
            $table->decimal('discount_percent', 5, 2)->default(0);
            // Deactivating (not deleting) hides it from new sign-ups/checkout
            // and hides its companion packages, without touching any company
            // already on it — see App\Services\YearlyPackageSync.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('billing_term_id')->nullable()->after('monthly_package_id')
                ->constrained('billing_terms')->nullOnDelete();
        });

        // Preserve whatever was already configured under the old fixed
        // Yearly/2-Year/3-Year fields as real, editable Billing Terms rows —
        // nothing about existing pricing changes for admins already on one.
        if (Schema::hasColumn('subscription_policies', 'yearly_discount_percent')) {
            $policy = DB::table('subscription_policies')->where('id', 1)->first();
            if ($policy) {
                $now = now();
                DB::table('billing_terms')->insert([
                    ['name' => 'Yearly',  'months' => 12, 'discount_percent' => $policy->yearly_discount_percent ?? 0,     'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                    ['name' => '2-Year',  'months' => 24, 'discount_percent' => $policy->two_year_discount_percent ?? 0,   'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                    ['name' => '3-Year',  'months' => 36, 'discount_percent' => $policy->three_year_discount_percent ?? 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ]);
            }

            Schema::table('subscription_policies', function (Blueprint $table) {
                $table->dropColumn(['yearly_discount_percent', 'two_year_discount_percent', 'three_year_discount_percent']);
            });
        }

        // billing_cycle is no longer authoritative for period-length math
        // (billing_term_id + BillingTerm::months is) — widened from the old
        // fixed enum to a plain slug string, kept only for display/back-compat.
        DB::statement("ALTER TABLE packages MODIFY billing_cycle VARCHAR(50) NULL");
    }

    public function down(): void
    {
        Schema::table('subscription_policies', function (Blueprint $table) {
            $table->decimal('yearly_discount_percent', 5, 2)->default(0);
            $table->decimal('two_year_discount_percent', 5, 2)->default(0);
            $table->decimal('three_year_discount_percent', 5, 2)->default(0);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_term_id');
        });

        Schema::dropIfExists('billing_terms');

        DB::statement("ALTER TABLE packages MODIFY billing_cycle ENUM('monthly','yearly','2_year','3_year') NULL");
    }
};
