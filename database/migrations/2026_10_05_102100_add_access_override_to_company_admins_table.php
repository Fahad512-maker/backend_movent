<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            // Super Admin's manual "let this tenant in anyway" switch —
            // deliberately separate from subscription_status. Flipping this
            // on does NOT mean they paid; it means Super Admin chose to
            // restore access despite an expired/suspended subscription (see
            // CompanyAdmin::isBlockedFromAccess()). subscription_status stays
            // whatever it genuinely is so billing/reporting is never lied to.
            $table->boolean('access_override')->default(false)->after('suspended_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->dropColumn('access_override');
        });
    }
};
