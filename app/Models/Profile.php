<?php

namespace App\Models;

use App\Enums\ProfileKind;
use App\Support\PolicyDefaults;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'kind',
        'workdir',
        'default_branch',
        'repo_url',
        'concurrency_cap',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProfileKind::class,
            'settings' => 'array',
            'concurrency_cap' => 'integer',
        ];
    }

    public function policy(): HasOne
    {
        return $this->hasOne(Policy::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The profile's default project — the implicit single project every profile
     * has (created at backfill / on profile creation). Simple profiles never
     * need more than this one.
     */
    public function defaultProject(): ?Project
    {
        return $this->projects()->where('is_default', true)->first()
            ?? $this->projects()->orderBy('id')->first();
    }

    /**
     * Whether this profile has a usable project home: a non-empty path that
     * exists on disk and is writable. Workers must never run without one — an
     * empty workdir would otherwise fall back to Conductor's own directory and
     * write files into the wrong place.
     */
    public function hasValidWorkdir(): bool
    {
        $dir = is_string($this->workdir) ? trim($this->workdir) : '';

        return $dir !== '' && is_dir($dir) && is_writable($dir);
    }

    /**
     * The persisted policy, or an unsaved default built from the profile kind.
     */
    public function policyOrDefault(): Policy
    {
        return $this->policy ?? new Policy([
            'profile_id' => $this->id,
            'rules' => PolicyDefaults::for($this->kind),
        ]);
    }
}
