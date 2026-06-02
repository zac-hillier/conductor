<?php

namespace App\Models;

use App\Services\ProjectContextBuilder;
use App\Services\ProjectContextDiscovery;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'slug',
        'name',
        'workdir',
        'manifest_path',
        'is_default',
        'settings',
        'context_map',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'settings' => 'array',
            'context_map' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Whether this project has a usable working directory: a non-empty path
     * that exists on disk and is writable. Mirrors Profile::hasValidWorkdir so
     * a project workdir is held to the same dispatch-safety bar.
     */
    public function hasValidWorkdir(): bool
    {
        $dir = is_string($this->workdir) ? trim($this->workdir) : '';

        return $dir !== '' && is_dir($dir) && is_writable($dir);
    }

    /**
     * The working directory for this project: its own workdir if set, else the
     * profile's home. Mirrors Task::resolvedWorkdir at the project level.
     */
    public function resolvedWorkdir(): ?string
    {
        if (is_string($this->workdir) && trim($this->workdir) !== '') {
            return $this->workdir;
        }

        return $this->profile?->workdir;
    }

    /**
     * (Re)scan the filesystem and persist the discovered context map. Run on
     * create / workdir change and via the Rescan action — never per prompt.
     */
    public function discoverContext(): void
    {
        $map = app(ProjectContextDiscovery::class)->discover($this->resolvedWorkdir());

        $this->update(['context_map' => $map]);
    }

    /**
     * Compact living-doc pulse for the UI (latest changelog headline, open-P0
     * count, in-progress roadmap line).
     *
     * @return array{changelog: ?string, open_p0: int, roadmap: ?string}
     */
    public function pulse(): array
    {
        return app(ProjectContextBuilder::class)->pulse($this->context_map);
    }
}
