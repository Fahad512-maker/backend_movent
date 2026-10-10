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
        if (Schema::hasTable('recruitment')) return;

        Schema::create('recruitment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('position', 150);
            $table->string('department', 100)->nullable();
            $table->tinyInteger('openings')->default(1);
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'closed', 'on_hold'])->default('open');
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment');
    }
};
