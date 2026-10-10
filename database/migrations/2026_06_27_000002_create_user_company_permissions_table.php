<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_company_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('module_key', 50);
            $table->string('permission_key', 100);
            $table->timestamps();
            $table->unique(['user_id', 'company_id', 'module_key', 'permission_key'], 'ucp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company_permissions');
    }
};
