<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One-time data consolidation ahead of the "one project = one chat" unique
// index (see the migration right after this one). Merges every Project's
// multiple project_group/project_direct chat_threads rows into a single
// surviving thread, moving messages and participants across, before the
// index makes duplicates impossible to create again. Deliberately leaves
// the older, dormant thread_type='project' rows (linked_to_type is always
// NULL there) untouched — a separate, never-wired-into-any-frontend legacy
// feature. Old 1:1 (project_direct) message history becomes visible to
// everyone in the merged thread going forward (confirmed with product
// owner — only ~20 messages exist total across every project today, and
// the new model is "one team channel per project", not per-pair privacy).
return new class extends Migration
{
    private const TYPES = ['project_group', 'project_direct'];

    public function up(): void
    {
        $projectIds = DB::table('chat_threads')
            ->where('linked_to_type', 'Project')
            ->whereIn('thread_type', self::TYPES)
            ->distinct()
            ->pluck('linked_to_id');

        foreach ($projectIds as $projectId) {
            DB::transaction(function () use ($projectId) {
                $threads = DB::table('chat_threads')
                    ->where('linked_to_type', 'Project')
                    ->where('linked_to_id', $projectId)
                    ->whereIn('thread_type', self::TYPES)
                    ->orderBy('id')
                    ->get();

                if ($threads->isEmpty()) {
                    return;
                }

                $primary = $threads->firstWhere('thread_type', 'project_group') ?? $threads->first();
                $others = $threads->where('id', '!=', $primary->id)->pluck('id');

                if ($others->isNotEmpty()) {
                    DB::table('chat_messages')->whereIn('thread_id', $others)->update(['thread_id' => $primary->id]);

                    $otherParticipants = DB::table('chat_participants')->whereIn('thread_id', $others)->get();
                    $primaryParticipants = DB::table('chat_participants')->where('thread_id', $primary->id)->get()->keyBy('user_id');

                    foreach ($otherParticipants->groupBy('user_id') as $userId => $rows) {
                        $existing = $primaryParticipants->get($userId);

                        if (!$existing) {
                            // First (earliest id) duplicate row for this user wins as the
                            // base; merge every other duplicate for the same user into it.
                            $base = $rows->sortBy('id')->first();
                            DB::table('chat_participants')->where('id', $base->id)->update(['thread_id' => $primary->id]);
                            $existing = $base;
                            $rows = $rows->where('id', '!=', $base->id);
                        }

                        if ($rows->isEmpty()) {
                            continue;
                        }

                        DB::table('chat_participants')->where('id', $existing->id)->update([
                            'role' => $rows->contains('role', 'admin') || $existing->role === 'admin' ? 'admin' : 'member',
                            'muted_at' => $rows->contains(fn ($r) => $r->muted_at !== null) || $existing->muted_at !== null
                                ? ($existing->muted_at ?? $rows->firstWhere('muted_at', '!=', null)?->muted_at)
                                : null,
                            'last_read_at' => $rows->pluck('last_read_at')->push($existing->last_read_at)->filter()->max(),
                        ]);

                        DB::table('chat_participants')->whereIn('id', $rows->pluck('id'))->delete();
                    }

                    DB::table('chat_threads')->whereIn('id', $others)->delete();
                }

                $lastMessageAt = DB::table('chat_messages')->where('thread_id', $primary->id)->max('sent_at');

                DB::table('chat_threads')->where('id', $primary->id)->update([
                    'thread_type' => 'project_group',
                    'visibility' => $primary->visibility ?? 'internal',
                    'last_message_at' => $lastMessageAt,
                ]);
            });
        }

        $this->enforceSellerMixRule();
    }

    // Two originally-separate threads can each be individually valid
    // ("Seller + PM" in one, "Developer + PM" in another) yet merge into a
    // participant set that violates the standing rule that a Seller may
    // only ever share the thread with Company Admin and the Project
    // Manager. Idempotent — a project with no Seller-mix violation is a
    // no-op every time this runs. Drops the Seller's own row rather than
    // anyone else's, matching the rule that a Seller should never have been
    // mixed with non-PM staff in the first place.
    private function enforceSellerMixRule(): void
    {
        $threadIds = DB::table('chat_threads')
            ->where('linked_to_type', 'Project')
            ->where('thread_type', 'project_group')
            ->pluck('id');

        foreach ($threadIds as $threadId) {
            $participants = DB::table('chat_participants')
                ->join('users', 'users.id', '=', 'chat_participants.user_id')
                ->where('chat_participants.thread_id', $threadId)
                ->get(['chat_participants.id as participant_id', 'users.role_type']);

            $sellerRows = $participants->where('role_type', 'seller');
            if ($sellerRows->isEmpty()) {
                continue;
            }

            $hasDisallowed = $participants->contains(fn ($p) => !in_array($p->role_type, ['seller', 'project_manager'], true));
            if (!$hasDisallowed) {
                continue;
            }

            DB::table('chat_participants')->whereIn('id', $sellerRows->pluck('participant_id'))->delete();
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — merging duplicate chat threads back
        // apart cannot be done without losing the "which message/participant
        // originally belonged to which thread" information that was
        // discarded on merge.
    }
};
