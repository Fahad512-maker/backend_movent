<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A reply consisting of only an attachment (no typed message) is now
        // allowed — see Api\{Client,Admin,User}\SupportController::reply()'s
        // required_without:attachment validation.
        DB::statement('ALTER TABLE support_ticket_replies MODIFY message TEXT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE support_ticket_replies SET message = '' WHERE message IS NULL");
        DB::statement('ALTER TABLE support_ticket_replies MODIFY message TEXT NOT NULL');
    }
};
