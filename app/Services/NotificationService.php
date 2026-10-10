<?php

namespace App\Services;

use App\Models\CompanyAdmin;
use App\Models\Notification;
use App\Models\User;

// Central notification creation point — NOT a replacement for the ~40
// existing inline Notification::create() calls scattered across the app
// (those already self-skip/company-scope correctly and are left alone).
// This service exists for NEW notification code going forward, and is the
// only thing that can target a Company Admin as a recipient (recipient_admin_id),
// since CompanyAdmin isn't a `users` row and every pre-existing call site
// only ever knew how to write user_id.
class NotificationService
{
    // Exactly one of recipient_user_id/recipient_admin_id must be set.
    // actor_user_id/actor_admin_id are optional but drive the self-skip
    // check — pass whichever one performed the action (never both).
    public static function send(array $params): ?Notification
    {
        $recipientUserId  = $params['recipient_user_id']  ?? null;
        $recipientAdminId = $params['recipient_admin_id'] ?? null;
        $actorUserId      = $params['actor_user_id']      ?? null;
        $actorAdminId     = $params['actor_admin_id']     ?? null;
        $companyId        = $params['company_id']         ?? null;

        if (!$companyId || (!$recipientUserId && !$recipientAdminId)) {
            return null;
        }

        // Never notify the actor about their own action.
        if ($recipientUserId && $actorUserId && (int) $recipientUserId === (int) $actorUserId) {
            return null;
        }
        if ($recipientAdminId && $actorAdminId && (int) $recipientAdminId === (int) $actorAdminId) {
            return null;
        }

        if ($recipientUserId) {
            $recipientValid = User::where('id', $recipientUserId)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->exists();
            if (!$recipientValid) return null;
        }

        if ($recipientAdminId) {
            $recipientValid = CompanyAdmin::where('id', $recipientAdminId)
                ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
                ->exists();
            if (!$recipientValid) return null;
        }

        $url = $params['url'] ?? null;

        return Notification::create([
            'user_id'            => $recipientUserId,
            'recipient_admin_id' => $recipientAdminId,
            'actor_user_id'      => $actorUserId,
            'actor_admin_id'     => $actorAdminId,
            'company_id'         => $companyId,
            'module'             => $params['module'] ?? null,
            'type'               => $params['type'] ?? null,
            'title'              => $params['title'] ?? null,
            'body'               => $params['message'] ?? ($params['body'] ?? null),
            'entity_type'        => $params['entity_type'] ?? null,
            'entity_id'          => $params['entity_id'] ?? null,
            'url'                => $url,
            // Mirrored into data.link too — the existing frontend read path
            // (n.data.link) keeps working unchanged for these new rows.
            'data'               => array_filter([
                'link'        => $url,
                'entity_type' => $params['entity_type'] ?? null,
                'entity_id'   => $params['entity_id'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
    }

    // Sends to a set of recipients in one call, de-duplicated first — e.g. a
    // user who is both the assigned PM and a project team member must only
    // ever get one notification for the same event, not one per role.
    // $recipients: array of ['user_id' => int] or ['admin_id' => int] specs.
    public static function sendToMany(array $recipients, array $common): void
    {
        $seenUsers = [];
        $seenAdmins = [];

        foreach ($recipients as $recipient) {
            if (isset($recipient['user_id']) && $recipient['user_id']) {
                $uid = (int) $recipient['user_id'];
                if (isset($seenUsers[$uid])) continue;
                $seenUsers[$uid] = true;
                static::send(array_merge($common, ['recipient_user_id' => $uid]));
            } elseif (isset($recipient['admin_id']) && $recipient['admin_id']) {
                $aid = (int) $recipient['admin_id'];
                if (isset($seenAdmins[$aid])) continue;
                $seenAdmins[$aid] = true;
                static::send(array_merge($common, ['recipient_admin_id' => $aid]));
            }
        }
    }

    // Notifies every Company Admin who owns $companyId — a company can have
    // more than one Admin account, and send()'s own self-skip already
    // excludes $actorAdminId from the set when the actor is one of them (a
    // User-guard actor passes null here and nothing gets skipped).
    public static function notifyCompanyAdmins(int $companyId, ?int $actorAdminId, array $common): void
    {
        $adminIds = CompanyAdmin::whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))->pluck('id');

        foreach ($adminIds as $adminId) {
            static::send(array_merge($common, [
                'company_id'         => $companyId,
                'recipient_admin_id' => $adminId,
                'actor_admin_id'     => $actorAdminId,
            ]));
        }
    }
}
