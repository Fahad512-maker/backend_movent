<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A brand's public website, shown in the "Billed From" block on every
// customer-facing copy of a Brand Invoice (share link, email, client portal,
// preview).
//
// Brand-only on purpose: companies have no website column of their own, so a
// Company Invoice simply has nothing to put here and the line is omitted
// rather than left blank — see Invoice::brandingProfile().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('website')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
