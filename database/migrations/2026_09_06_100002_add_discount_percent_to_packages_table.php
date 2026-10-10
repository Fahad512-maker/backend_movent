<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Packages already carry a single `billing_cycle` (monthly XOR yearly) with
// its own `price_usd`/`price_pkr` — a plan's "Monthly Plan" and "Yearly Plan"
// are two separate Package rows sharing the same tier/name convention, not
// one row with both prices. This column is that row's own discount against
// its list price for its own cycle (e.g. a yearly-cycle row's discount_percent
// is "% off vs. paying that price monthly ×12") — Super Admin sets it per
// row via the existing Package CRUD, additive to price_usd/price_pkr.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (!Schema::hasColumn('packages', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('trial_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
        });
    }
};
