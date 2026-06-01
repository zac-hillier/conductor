<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRun extends Model
{
    protected $fillable = [
        'task_id',
        'attempt',
        'kind',
        'started_at',
        'finished_at',
        'outcome',
        'summary',
        'files_changed',
        'approvals',
        'token_count',
        'cost',
        'session_id',
        'claude_session_path',
        'log_ref',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'files_changed' => 'array',
            'approvals' => 'array',
            'token_count' => 'integer',
            'cost' => 'decimal:4',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return $this->finished_at->diffInSeconds($this->started_at);
    }
}
