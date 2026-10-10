<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\ClientAssistant\StaffAssistantService;
use Illuminate\Http\JsonResponse;

/**
 * "AI Assistance" on the Company Admin side — the Admin twin of
 * Api\User\AssistantController, and identical in shape.
 *
 * It differs in exactly one thing, which is the thing that matters: what
 * "yours" means. A Company Admin is not a `users` row and is never a
 * chat_participants row anywhere in this app, so membership cannot scope them.
 * Company OWNERSHIP does — precisely the scope Api\Admin\ClientChatController
 * already applies to these same threads on the Client detail page. This
 * surfaces nothing an Admin could not already open by clicking through that
 * screen, and nothing at all from a company they do not own.
 *
 * Client-initiated still applies: an Admin does not get a widget just because
 * clients exist, only once one of them has actually reached out.
 */
class AssistantController extends Controller
{
    private function admin()
    {
        return auth('admin')->user();
    }

    /** @return array<int, int> */
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->all();
    }

    // ── GET /admin/assistant/conversations ───────────────────────────────────

    public function conversations(): JsonResponse
    {
        $rows = StaffAssistantService::adminConversations($this->companyIds());

        return ApiResponse::success([
            'visible'       => $rows->isNotEmpty(),
            'conversations' => $rows->all(),
            // Admin has no participant row, so there is no last_read_at to
            // measure against and no honest unread number to report.
            'unread_total'  => 0,
        ]);
    }
}
