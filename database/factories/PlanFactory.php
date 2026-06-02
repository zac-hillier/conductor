<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'project_id' => Project::factory(),
            'source_task_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => PlanStatus::Drafting,
            'artifact_dir' => null,
            'concept' => fake()->sentence(),
            'summary' => null,
            'cost' => null,
        ];
    }
}
