<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'task_id',
        'run_id',
        'capability',
        'command',
        'reason',
        'decision',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class, 'run_id');
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('decision', 'pending');
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopeGranted(Builder $query): void
    {
        $query->where('decision', 'granted');
    }
}
