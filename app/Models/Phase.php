<?php

namespace App\Models;

use App\Enums\PhaseStatus;
use App\Enums\TaskStatus;
use Database\Factories\PhaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Phase extends Model
{
    /** @use HasFactory<PhaseFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'number',
        'name',
        'objective',
        'status',
        'gateway_test',
        'exit_criteria',
        'prompt_path',
        'summary_path',
        'task_id',
        'outcome',
        'review_verdict',
        'cost',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PhaseStatus::class,
            'exit_criteria' => 'array',
            'number' => 'integer',
            'cost' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * Whether this phase may be turned into a board task now: it's pending and
     * every earlier-numbered phase in the plan is done.
     */
    public function isExecutable(): bool
    {
        if ($this->status !== PhaseStatus::Pending) {
            return false;
        }

        return ! $this->plan->phases()
            ->where('number', '<', $this->number)
            ->where('status', '!=', PhaseStatus::Done->value)
            ->exists();
    }

    /**
     * Create the board Task that executes this phase, link it back, and move the
     * phase to ready. The Task flows through the board with all existing
     * machinery; its phase_id tells RunTaskJob to run it as a phase (WS-2C P3).
     */
    public function createBackingTask(): Task
    {
        $plan = $this->plan;
        $project = $plan->project;
        $profile = $project->profile;

        $task = $profile->tasks()->create([
            'project_id' => $project->id,
            'phase_id' => $this->id,
            'ref' => $profile->nextTaskRef(),
            'title' => 'Phase '.$this->number.': '.$this->name,
            'summary' => $this->objective,
            'status' => TaskStatus::Ready,
        ]);

        $task->recordEvent('created', ['plan_id' => $plan->id, 'phase' => $this->number]);

        $this->update([
            'task_id' => $task->id,
            'status' => PhaseStatus::Ready,
            'started_at' => now(),
        ]);

        return $task;
    }
}
