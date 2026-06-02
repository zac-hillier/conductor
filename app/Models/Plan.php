<?php

namespace App\Models;

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'source_task_id',
        'name',
        'slug',
        'status',
        'artifact_dir',
        'concept',
        'summary',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'cost' => 'decimal:4',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'source_task_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(Phase::class)->orderBy('number');
    }

    /**
     * The lowest-numbered phase that is not yet done — the plan's working front.
     */
    public function currentPhase(): ?Phase
    {
        return $this->phases()
            ->where('status', '!=', PhaseStatus::Done->value)
            ->orderBy('number')
            ->first();
    }

    /**
     * Sum of per-phase costs (each phase rolls up its run cost).
     */
    public function costRollup(): float
    {
        return (float) $this->phases()->sum('cost');
    }

    /**
     * A slug unique within the project (e.g. returns-filter, returns-filter-2).
     */
    public static function uniqueSlugFor(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $suffix = 2;

        while ($project->plans()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
