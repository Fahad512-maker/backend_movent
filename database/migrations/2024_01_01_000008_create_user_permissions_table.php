<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('user_permissions')) return;

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->tinyInteger('can_view')->default(0);
            $table->tinyInteger('can_create')->default(0);
            $table->tinyInteger('can_edit')->default(0);
            $table->tinyInteger('can_delete')->default(0);
            $table->tinyInteger('can_export')->default(0);
            $table->tinyInteger('can_assign')->default(0);
            $table->tinyInteger('can_approve')->default(0);
            $table->tinyInteger('can_send')->default(0);
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user_id', 'module_key']);

            $table->foreign('set_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
