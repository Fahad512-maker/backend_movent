<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'thread_id', 'sender_id', 'sender_admin_id', 'guest_sender_name', 'content', 'mentions', 'hidden_from_user_ids', 'message_type', 'visibility', 'attachment_path',
        'attachment_name', 'forwarded_from_id', 'is_deleted', 'hidden_for_staff', 'sent_at', 'edited_at',
        // 2026_09_22_100100_add_storage_type_to_attachment_tables — added to
        // the migration but not here until now, so every ::create() silently
        // dropped them (no preventSilentlyDiscardingAttributes() in this
        // app). See App\Services\AttachmentStorageResult::locationAttributes().
        'attachment_size', 'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $casts = [
        'mentions'             => 'array',
        'hidden_from_user_ids' => 'array',
        'is_deleted'           => 'boolean',
        'hidden_for_staff'     => 'boolean',
        'sent_at'              => 'datetime',
        'edited_at'            => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    // withTrashed(): User soft-deletes, so without this a soft-deleted staff
    // member's messages resolve sender to null and render as a fallback
    // ("Unknown"/"—") even though the record still exists. Every chat
    // controller eager-loads through this relation, so fixing it here fixes
    // all of them at once. senderAdmin() needs no equivalent — CompanyAdmin
    // doesn't soft-delete, so a null there really does mean "row is gone".
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function senderAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'sender_admin_id');
    }

    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'forwarded_from_id');
    }
}
