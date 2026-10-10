<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// App\Services\PaymentProjectStartService::migrateChatHistory() only fires at
// the moment a NEW qualifying payment creates/activates a Project — every
// Lead/Client whose Project was already active before that method existed
// (or before a given payment ran through it) was left with its pre-payment
// "Chat with Seller" conversation stranded on the old, now-abandoned
// thread_type='sales' row. The frontend (Lead/Client detail pages'
// chat_project_id redirect) already points everyone at the Project's chat
// once a Project exists, so those stranded messages become permanently
// unreachable from any UI — this is exactly the class of bug manually
// patched once already for lead 32; this backfill sweeps every remaining
// case in one pass instead of one-off fixing each as it's reported.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_threads') || !Schema::hasTable('chat_messages')) {
            return;
        }

        $now = now();

        $salesThreads = DB::table('chat_threads')
            ->where('thread_type', 'sales')
            ->whereIn('linked_to_type', ['Lead', 'Client'])
            ->get(['id', 'linked_to_type', 'linked_to_id', 'company_id', 'last_message_at']);

        foreach ($salesThreads as $salesThread) {
            $messageCount = DB::table('chat_messages')->where('thread_id', $salesThread->id)->count();
            if ($messageCount === 0) {
                continue;
            }

            $projectId = $salesThread->linked_to_type === 'Lead'
                ? DB::table('projects')->where('lead_id', $salesThread->linked_to_id)->orderByDesc('id')->value('id')
                : DB::table('projects')->where('client_id', $salesThread->linked_to_id)->orderByDesc('id')->value('id');

            if (!$projectId) {
                continue;
            }

            $projectThreadId = DB::table('chat_threads')
                ->where('thread_type', 'project_group')
                ->where('linked_to_type', 'Project')
                ->where('linked_to_id', $projectId)
                ->value('id');

            if (!$projectThreadId) {
                $projectThreadId = DB::table('chat_threads')->insertGetId([
                    'company_id'      => $salesThread->company_id,
                    'thread_type'     => 'project_group',
                    'linked_to_type'  => 'Project',
                    'linked_to_id'    => $projectId,
                    'visibility'      => 'internal',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            DB::table('chat_messages')->where('thread_id', $salesThread->id)->update(['thread_id' => $projectThreadId]);

            $projectThreadLastMessageAt = DB::table('chat_threads')->where('id', $projectThreadId)->value('last_message_at');
            if ($salesThread->last_message_at && (!$projectThreadLastMessageAt || $salesThread->last_message_at > $projectThreadLastMessageAt)) {
                DB::table('chat_threads')->where('id', $projectThreadId)->update(['last_message_at' => $salesThread->last_message_at]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
