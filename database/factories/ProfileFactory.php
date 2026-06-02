<?php

namespace Database\Factories;

use App\Enums\ProfileKind;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => $name,
            'kind' => ProfileKind::Customer,
            'workdir' => sys_get_temp_dir(),
            'default_branch' => 'main',
            'repo_url' => null,
            'concurrency_cap' => 3,
            'settings' => null,
        ];
    }

    public function personal(): static
    {
        return $this->state(fn () => ['kind' => ProfileKind::Personal]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['kind' => ProfileKind::Customer]);
    }
}
