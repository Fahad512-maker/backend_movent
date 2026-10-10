<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Api\User\ProjectController::store() only ever called
// ProjectChatService::addSeller() on the "handoff" create path
// ($isHandoff) — a Seller holding the default canCreateProjects grant
// (the common case) took the "full create" path instead, which skipped it
// entirely. That left them with the canViewProjectChat/canSendProjectChatMessage
// permissions but no chat_participants row, so every chat endpoint 403'd with
// "you don't have access... yet" on a project they created themselves. The
// controller now adds them regardless of which create path was taken; this
// is the one-off catch-up for every project already created before that fix,
// silently (no "added to chat" notification — this is data correction, not
// a new event) — creates the project's chat thread if none exists yet, and
// only ever adds a missing participant row, never touches an existing one.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects') || !Schema::hasTable('chat_threads') || !Schema::hasTable('chat_participants')) {
            return;
        }

        $now = now();
        $projects = DB::table('projects')->whereNotNull('seller_id')->get(['id', 'seller_id', 'company_id']);

        foreach ($projects as $project) {
            $threadId = DB::table('chat_threads')
                ->where('linked_to_type', 'Project')
                ->where('linked_to_id', $project->id)
                ->where('thread_type', 'project_group')
                ->value('id');

            if (!$threadId) {
                $threadId = DB::table('chat_threads')->insertGetId([
                    'company_id'     => $project->company_id,
                    'thread_type'    => 'project_group',
                    'linked_to_type' => 'Project',
                    'linked_to_id'   => $project->id,
                    'visibility'     => 'internal',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            $isParticipant = DB::table('chat_participants')
                ->where('thread_id', $threadId)
                ->where('user_id', $project->seller_id)
                ->exists();

            if (!$isParticipant) {
                DB::table('chat_participants')->insert([
                    'thread_id' => $threadId,
                    'user_id'   => $project->seller_id,
                    'role'      => 'member',
                    'joined_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_08_14_100000's rationale.
    }
};
