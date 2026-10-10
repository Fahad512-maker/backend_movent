<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::table('employees')
            ->select('company_id', 'department')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->distinct()
            ->orderBy('company_id')
            ->orderBy('department')
            ->chunk(200, function ($rows) {
                $now = now();
                foreach ($rows as $row) {
                    DB::table('departments')->insertOrIgnore([
                        'company_id' => $row->company_id,
                        'name' => trim($row->department),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
