<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Finance > Invoice Reminders.
//
// `invoice_reminders` has existed since the very first migration
// (2024_01_01_000015) and was never written to by anything — no controller,
// no service, no route references it. So this EXPANDS that table rather than
// creating a second one: the invoice relation (Invoice::reminders()) and the
// type/message/sent_at/sent_by columns all stay exactly as they are.
//
// What the table could not express before, and now can:
//   company_id         reminder rows were only reachable through a join to
//                      invoices; every Finance query filters by company, and
//                      an isolation boundary should not depend on remembering
//                      a join. Denormalised deliberately, backfilled below.
//   created_by(_admin) WHO scheduled it, separate from WHO sent it. Both
//                      guards need representing — staff are `users` rows, a
//                      Company Admin is a `company_admins` row — same two-
//                      column pattern invoices.created_by/created_by_admin_id
//                      already uses.
//   reminder_date      WHEN it is meant to go out. Drives the daily
//                      `invoices:send-due-reminders` command.
//   status             scheduled → sent | failed | cancelled. Lets a reminder
//                      exist before it is delivered, which is the whole point
//                      of "Create Reminder … Send when authorized".
//   subject/sent_to    what actually went out, and to which address, frozen
//                      at send time so the history stays truthful after the
//                      client's email is later changed.
//   failure_reason     why a send failed, so Track shows more than "failed".
//
// Existing rows: there are none in practice (nothing ever wrote one), but the
// backfill is written as if there were — company_id from the invoice, and a
// row with sent_at set is 'sent', otherwise 'scheduled'.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_reminders', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_reminders', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('invoice_id');
            }
            if (!Schema::hasColumn('invoice_reminders', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('company_id');
            }
            if (!Schema::hasColumn('invoice_reminders', 'created_by_admin_id')) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('invoice_reminders', 'sent_by_admin_id')) {
                $table->unsignedBigInteger('sent_by_admin_id')->nullable()->after('sent_by');
            }
            if (!Schema::hasColumn('invoice_reminders', 'reminder_date')) {
                $table->date('reminder_date')->nullable()->after('type');
            }
            if (!Schema::hasColumn('invoice_reminders', 'status')) {
                $table->enum('status', ['scheduled', 'sent', 'failed', 'cancelled'])
                    ->default('scheduled')
                    ->after('reminder_date');
            }
            if (!Schema::hasColumn('invoice_reminders', 'subject')) {
                $table->string('subject', 255)->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoice_reminders', 'sent_to')) {
                $table->string('sent_to', 255)->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('invoice_reminders', 'failure_reason')) {
                $table->string('failure_reason', 500)->nullable()->after('sent_to');
            }
            // The original table declared no timestamps at all (the model
            // carries `public $timestamps = false`), so "when was this
            // reminder created" was unanswerable — which Track needs.
            if (!Schema::hasColumn('invoice_reminders', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('invoice_reminders', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        DB::statement('
            UPDATE invoice_reminders
            INNER JOIN invoices ON invoices.id = invoice_reminders.invoice_id
            SET invoice_reminders.company_id = invoices.company_id
            WHERE invoice_reminders.company_id IS NULL
        ');

        DB::statement("
            UPDATE invoice_reminders
            SET status = 'sent'
            WHERE sent_at IS NOT NULL
        ");

        DB::statement('
            UPDATE invoice_reminders
            SET reminder_date = DATE(sent_at), created_at = sent_at, updated_at = sent_at
            WHERE sent_at IS NOT NULL AND reminder_date IS NULL
        ');

        Schema::table('invoice_reminders', function (Blueprint $table) {
            foreach (self::foreignKeys() as $column => $references) {
                if (!self::hasForeignKey('invoice_reminders', "invoice_reminders_{$column}_foreign")) {
                    $table->foreign($column)->references('id')->on($references)->nullOnDelete();
                }
            }
        });

        // The two shapes every Finance reminder query takes: "this company's
        // reminders" (list/track) and "everything due today" (the scheduler).
        if (!self::hasIndex('invoice_reminders', 'invoice_reminders_company_id_status_index')) {
            Schema::table('invoice_reminders', function (Blueprint $table) {
                $table->index(['company_id', 'status']);
            });
        }
        if (!self::hasIndex('invoice_reminders', 'invoice_reminders_status_reminder_date_index')) {
            Schema::table('invoice_reminders', function (Blueprint $table) {
                $table->index(['status', 'reminder_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoice_reminders', function (Blueprint $table) {
            foreach (array_keys(self::foreignKeys()) as $column) {
                if (self::hasForeignKey('invoice_reminders', "invoice_reminders_{$column}_foreign")) {
                    $table->dropForeign("invoice_reminders_{$column}_foreign");
                }
            }
        });

        foreach ([
            'invoice_reminders_company_id_status_index',
            'invoice_reminders_status_reminder_date_index',
        ] as $index) {
            if (self::hasIndex('invoice_reminders', $index)) {
                Schema::table('invoice_reminders', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('invoice_reminders', function (Blueprint $table) {
            $table->dropColumn([
                'company_id', 'created_by', 'created_by_admin_id', 'sent_by_admin_id',
                'reminder_date', 'status', 'subject', 'sent_to', 'failure_reason',
                'created_at', 'updated_at',
            ]);
        });
    }

    /** @return array<string, string> column => referenced table */
    private static function foreignKeys(): array
    {
        return [
            'company_id'          => 'companies',
            'created_by'          => 'users',
            'created_by_admin_id' => 'company_admins',
            'sent_by_admin_id'    => 'company_admins',
        ];
    }

    private static function hasForeignKey(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private static function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
