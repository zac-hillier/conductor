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
