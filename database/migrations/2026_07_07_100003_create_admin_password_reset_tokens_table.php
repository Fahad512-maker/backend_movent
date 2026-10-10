<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// For App\Models\CompanyAdmin password resets only — kept separate from
// password_reset_tokens (User guard) to avoid any cross-guard email ambiguity.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_tokens');
    }
};
