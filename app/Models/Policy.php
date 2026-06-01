<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Policy extends Model
{
    protected $fillable = [
        'profile_id',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function permissionMode(): string
    {
        return (string) ($this->rules['permission_mode'] ?? 'default');
    }

    /**
     * @return array<int, string>
     */
    public function disallowedTools(): array
    {
        $tools = $this->rules['disallowed_tools'] ?? [];

        return is_array($tools) ? array_values($tools) : [];
    }

    public function requiresReview(): bool
    {
        return (bool) ($this->rules['require_review'] ?? false);
    }

    public function autoDispatch(): bool
    {
        return (bool) ($this->rules['auto_dispatch'] ?? false);
    }
}
