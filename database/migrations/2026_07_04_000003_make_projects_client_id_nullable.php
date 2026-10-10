<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Project Management must work standalone, without the Client module active —
// projects.client_id was NOT NULL with cascadeOnDelete, which both forced every
// project to have a client and would delete the project if that client was
// ever removed. Made nullable + nullOnDelete, matching invoice_id on the same
// table. Existing rows keep their current client_id values untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }
};
