<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    public const AUTHOR_AGENT = 'agent';

    public const AUTHOR_HUMAN = 'human';

    protected $fillable = [
        'task_id',
        'author',
        'body',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isAgent(): bool
    {
        return $this->author === self::AUTHOR_AGENT;
    }
}
