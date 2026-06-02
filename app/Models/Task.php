<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Jobs\ScopeTaskJob;
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
        'project_id',
        'phase_id',
        'ref',
        'title',
        'summary',
        'definition_of_done',
        'constraints',
        'target_paths',
        'pinned_docs',
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
            'pinned_docs' => 'array',
            'readiness_detail' => 'array',
            'priority' => 'integer',
            'readiness_score' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * The working directory a worker should run in for this task: the task's
     * project workdir if set, else the profile workdir. The single resolution
     * point used by the jobs, prompt builders, and dispatcher.
     */
    public function resolvedWorkdir(): ?string
    {
        $projectWorkdir = $this->project?->workdir;

        if (is_string($projectWorkdir) && trim($projectWorkdir) !== '') {
            return $projectWorkdir;
        }

        return $this->profile?->workdir;
    }

    /**
     * Tool-disallow patterns that protect discovered `reference/` directories
     * from edits — the frozen end of the doc mutability spectrum. Merged into
     * the worker's disallowed tools so a write there is blocked unless the
     * human has explicitly granted it (which lifts the matching pattern).
     *
     * @return array<int, string>
     */
    public function referenceGuards(): array
    {
        $dirs = $this->project?->context_map['roles']['reference'] ?? [];

        $patterns = [];
        foreach ($dirs as $dir) {
            $dir = rtrim((string) $dir, '/');
            $patterns[] = 'Edit('.$dir.'/**)';
            $patterns[] = 'Write('.$dir.'/**)';
        }

        return $patterns;
    }

    /**
     * Whether the resolved working directory is usable for dispatch.
     */
    public function hasValidWorkdir(): bool
    {
        $dir = is_string($this->resolvedWorkdir()) ? trim((string) $this->resolvedWorkdir()) : '';

        return $dir !== '' && is_dir($dir) && is_writable($dir);
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
     * Launch a scope run when a task enters scoping, unless one is already in
     * flight. Idempotent: no-op for non-scoping tasks or when a scope run is
     * already running — so a manual status change, drag, or the buttons all
     * actually start scoping without stacking duplicate runs.
     */
    public function enterScoping(): void
    {
        if ($this->status !== TaskStatus::Scoping || $this->hasActiveScopeRun()) {
            return;
        }

        ScopeTaskJob::dispatch($this);
    }

    /**
     * Whether a scope run has started but not finished — the source of truth
     * for the "Scoping…" indicator (so a stuck status can't fake progress).
     */
    public function hasActiveScopeRun(): bool
    {
        return $this->runs()
            ->where('kind', 'scope')
            ->whereNull('finished_at')
            ->exists();
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
