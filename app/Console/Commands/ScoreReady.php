<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Jobs\ScoreTaskJob;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Console\Command;

class ScoreReady extends Command
{
    protected $signature = 'conductor:score-ready {--profile= : Limit to a single profile slug}';

    protected $description = 'Enqueue readiness scoring for ready tasks that have no score yet';

    public function handle(): int
    {
        $slug = $this->option('profile');

        if ($slug !== null) {
            $profile = Profile::query()->where('slug', $slug)->first();

            if ($profile === null) {
                $this->error("Profile [{$slug}] not found.");

                return self::FAILURE;
            }
        }

        $tasks = Task::query()
            ->where('status', TaskStatus::Ready->value)
            ->whereNull('readiness_score')
            ->when(isset($profile), fn ($query) => $query->where('profile_id', $profile->id))
            ->get();

        foreach ($tasks as $task) {
            ScoreTaskJob::dispatch($task);
        }

        $count = $tasks->count();
        $scope = $slug !== null ? " for profile [{$slug}]" : '';

        $this->info("Enqueued readiness scoring for {$count} ready task(s){$scope}.");

        return self::SUCCESS;
    }
}
