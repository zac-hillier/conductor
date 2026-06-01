<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'ref' => null,
            'title' => fake()->sentence(4),
            'summary' => null,
            'definition_of_done' => null,
            'constraints' => null,
            'target_paths' => null,
            'priority' => 50,
            'status' => TaskStatus::Backlog,
            'readiness_score' => null,
            'readiness_detail' => null,
            'parent_id' => null,
        ];
    }
}
