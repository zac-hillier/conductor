<?php

namespace App\Models;

use App\Enums\TaskStatus;
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
}
