<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('invite_token', 80)->nullable()->unique()->after('email');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token');
            $table->boolean('must_change_password')->default(false)->after('invite_expires_at');
            $table->string('status', 20)->default('active')->after('is_active');
        });

        // Expand role_type enum to include invoice roles
        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }

    public function down(): void
    {
        // Revert enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production'
        ) NOT NULL DEFAULT 'seller'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invite_token', 'invite_expires_at', 'must_change_password', 'status']);
        });
    }
};
