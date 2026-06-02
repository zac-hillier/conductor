<?php

namespace Database\Factories;

use App\Enums\PhaseStatus;
use App\Models\Phase;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Phase>
 */
class PhaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'number' => 1,
            'name' => fake()->words(2, true),
            'objective' => fake()->sentence(),
            'status' => PhaseStatus::Pending,
            'gateway_test' => null,
            'exit_criteria' => ['It works'],
            'prompt_path' => null,
            'summary_path' => null,
            'task_id' => null,
            'outcome' => null,
            'cost' => null,
        ];
    }
}
