<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComment extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'task_id', 'deliverable_id', 'parent_comment_id',
        'author_admin_id', 'author_user_id', 'body', 'visibility', 'mentions',
    ];

    protected $casts = [
        'mentions' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'author_admin_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectCommentAttachment::class, 'comment_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ProjectCommentLike::class, 'comment_id');
    }

    // The comment this one is replying to — currently only used for a
    // Seller's reply to the internal comment they were tagged into (see
    // Api\User\ProjectCommentController::store()'s seller_reply handling).
    public function parentComment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }
}
