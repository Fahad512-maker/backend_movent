<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Services\ClientAssistant\StaffAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "AI Assistance" on the staff side — in practice, Client Messages.
 *
 * ONE endpoint, and deliberately no chat with a model. A seller must not have
 * to ask an assistant anything before seeing what their client wrote: the
 * assistant's job was to CONNECT the two of them, and once it has, it gets out
 * of the way. So this returns the conversations and nothing else; reading and
 * replying go through the endpoints that already serve the Direct Chat panel on
 * the Client detail page (Api\User\ClientChatController), which is also what
 * keeps this from becoming a second inbox with its own rules.
 *
 * VISIBILITY. `visible` is the answer to "should the widget render at all",
 * and it is false for the overwhelming majority of staff. A seller who has
 * never been contacted, a seller contacted by somebody else's client, anyone in
 * another company — all get `visible: false` and an empty list, so no floating
 * button appears for them at all. See StaffAssistantService for the two rules
 * (participant membership, and client-initiated) and why neither is new.
 *
 * Nothing in the request selects what comes back: the user is the authenticated
 * Sanctum session and the company is activeCompanyId(), re-derived from an
 * ACTIVE CompanyUserAssignment exactly as every other User-guard controller
 * does.
 */
class AssistantController extends Controller
{
    use ScopesToActiveCompany;

    private function user()
    {
        return auth('sanctum')->user();
    }

    // ── POST /user/assistant/read ────────────────────────────────────────────

    /**
     * "I have seen these" — clears the unread badge.
     *
     * Called when the widget is opened, and again when a single conversation is
     * opened. Nothing else on the staff side was writing last_read_at for these
     * threads, so before this the count could only ever grow.
     *
     * thread_id is optional and is a NARROWING hint, not a grant: the ids are
     * re-derived from the same scoped query the list uses, so one this user is
     * not a participant of matches nothing.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'thread_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $companyId = $this->activeCompanyId();

        StaffAssistantService::markStaffRead(
            (int) $this->user()->id,
            $companyId,
            isset($validated['thread_id']) ? (int) $validated['thread_id'] : null
        );

        $rows = $companyId
            ? StaffAssistantService::staffConversations((int) $this->user()->id, $companyId)
            : collect();

        return ApiResponse::success([
            'visible'       => $rows->isNotEmpty(),
            'conversations' => $rows->all(),
            'unread_total'  => (int) $rows->sum('unread'),
        ]);
    }

    // ── GET /user/assistant/conversations ────────────────────────────────────

    public function conversations(): JsonResponse
    {
        $companyId = $this->activeCompanyId();

        $rows = $companyId
            ? StaffAssistantService::staffConversations((int) $this->user()->id, $companyId)
            : collect();

        return ApiResponse::success([
            // The widget renders nothing when this is false. Frontend
            // visibility follows the backend's answer rather than a role check,
            // and the endpoints behind it are independently authorized anyway —
            // hiding a button is not what makes this private.
            'visible'       => $rows->isNotEmpty(),
            'conversations' => $rows->all(),
            'unread_total'  => (int) $rows->sum('unread'),
        ]);
    }
}
