<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\PublicFile;

class SupportTicketReply extends Model
{
    protected $fillable = [
        'ticket_id', 'replied_by', 'replied_by_admin_id', 'message', 'attachment_path', 'attachment_name',
        // 2026_09_22_100100_add_storage_type_to_attachment_tables — added to
        // the migration but not here until now, so every ::create() silently
        // dropped them (no preventSilentlyDiscardingAttributes() in this
        // app). See App\Services\AttachmentStorageResult::locationAttributes().
        'attachment_size', 'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $appends = ['attachment_url'];

    // Unchanged for an 'internal' row — a Drive-backed one (no local
    // attachment_path) has no direct public URL; download it through the
    // authenticated GET .../support/{id}/replies/{replyId}/attachment route
    // instead (see Api\{Admin,User,Client}\SupportController::downloadReplyAttachment()).
    public function getAttachmentUrlAttribute(): ?string
    {
        return PublicFile::url($this->attachment_path);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    public function repliedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'replied_by_admin_id');
    }
}
