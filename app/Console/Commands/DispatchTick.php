<?php

namespace App\Console\Commands;

use App\Services\DispatchScheduler;
use Illuminate\Console\Command;

class DispatchTick extends Command
{
    protected $signature = 'conductor:dispatch-tick {--dry-run : Show the plan without claiming or dispatching anything}';

    protected $description = 'Auto-dispatch ready tasks for auto-dispatch profiles, respecting concurrency caps';

    public function handle(DispatchScheduler $scheduler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $plan = $scheduler->tick($dryRun);

        if ($dryRun) {
            $this->comment('Dry run — nothing claimed or dispatched.');
        }

        if ($plan === []) {
            $this->line('No auto-dispatch profiles.');

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would dispatch' : 'Dispatched';

        foreach ($plan as $row) {
            $this->line(sprintf(
                '[%s] cap=%d inflight=%d slots=%d',
                $row['profile'],
                $row['cap'],
                $row['inflight'],
                $row['slots'],
            ));

            if ($row['dispatched'] === []) {
                $this->line('    '.($dryRun ? 'Would dispatch nothing' : 'Dispatched nothing'));

                continue;
            }

            $this->line(sprintf('    %s: %s', $verb, implode(', ', $row['dispatched'])));
        }

        return self::SUCCESS;
    }
}
