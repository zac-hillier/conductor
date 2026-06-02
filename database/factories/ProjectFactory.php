<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'profile_id' => Profile::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => Str::title($name),
            'workdir' => sys_get_temp_dir(),
            'manifest_path' => null,
            'is_default' => false,
            'settings' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true, 'slug' => 'default']);
    }
}
