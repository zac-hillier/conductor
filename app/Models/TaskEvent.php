<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'kind',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaskEvent $event): void {
            $event->created_at ??= now();
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function summary(): string
    {
        $payload = $this->payload ?? [];

        return match ($this->kind) {
            'created' => 'Task created',
            'status_changed' => sprintf(
                'Status %s → %s',
                $this->statusLabel($payload['from'] ?? null),
                $this->statusLabel($payload['to'] ?? null),
            ),
            'priority_changed' => sprintf(
                'Priority %s → %s',
                $payload['from'] ?? '?',
                $payload['to'] ?? '?',
            ),
            'updated' => 'Updated '.implode(', ', $payload['fields'] ?? []),
            default => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }

    private function statusLabel(?string $value): string
    {
        if ($value === null) {
            return '?';
        }

        return TaskStatus::tryFrom($value)?->label() ?? $value;
    }
}
