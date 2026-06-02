<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Jobs\ScoreTaskJob;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'ref',
        'title',
        'summary',
        'definition_of_done',
        'constraints',
        'target_paths',
        'priority',
        'status',
        'readiness_score',
        'readiness_detail',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'definition_of_done' => 'array',
            'constraints' => 'array',
            'target_paths' => 'array',
            'readiness_detail' => 'array',
            'priority' => 'integer',
            'readiness_score' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->latest('created_at')->latest('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class)->latest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Capabilities a human has granted for this task. Subtracted from the
     * profile policy's disallowed-tools set so the next attempt may use them.
     *
     * @return array<int, string>
     */
    public function grantedCapabilities(): array
    {
        return $this->approvals()
            ->granted()
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    public function nextAttempt(): int
    {
        return (int) $this->runs()->max('attempt') + 1;
    }

    /**
     * Atomically transition this task from ready to processing.
     *
     * Guarded on the current status so concurrent dispatchers cannot both
     * claim the same task. Returns true only for the caller that won the
     * transition. Idempotent for non-ready tasks (returns false, no change).
     */
    public function claim(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', TaskStatus::Ready->value)
            ->update(['status' => TaskStatus::Processing->value]) === 1;

        if ($claimed) {
            $this->status = TaskStatus::Processing;
            $this->recordEvent('claimed', [
                'from' => TaskStatus::Ready->value,
                'to' => TaskStatus::Processing->value,
            ]);
        }

        return $claimed;
    }

    /**
     * Trigger readiness scoring when a task lands in ready without a score.
     *
     * Idempotent: does nothing for non-ready tasks or ones already scored.
     * ScoreTaskJob re-checks the status itself, so a stale dispatch is safe.
     */
    public function enterReady(): void
    {
        if ($this->status !== TaskStatus::Ready || $this->readiness_score !== null) {
            return;
        }

        ScoreTaskJob::dispatch($this);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function recordEvent(string $kind, ?array $payload = null): TaskEvent
    {
        return $this->events()->create([
            'kind' => $kind,
            'payload' => $payload,
        ]);
    }
}
