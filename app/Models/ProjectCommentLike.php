<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCommentLike extends Model
{
    protected $fillable = ['company_id', 'comment_id', 'user_id', 'admin_id'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProjectComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'admin_id');
    }
}
