<?php

namespace App\Console\Commands;

use App\Services\StuckTaskRecovery;
use Illuminate\Console\Command;

class RecoverStuck extends Command
{
    protected $signature = 'conductor:recover {--dry-run : List stuck tasks without changing anything}';

    protected $description = 'Recover tasks stuck in processing whose worker run never finished';

    public function handle(StuckTaskRecovery $recovery): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $recovered = $recovery->recover($dryRun);

        if ($dryRun) {
            $this->comment('Dry run — nothing changed.');
        }

        if ($recovered === []) {
            $this->line('No stuck tasks found.');

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would recover' : 'Recovered';

        foreach ($recovered as $row) {
            $this->line(sprintf(
                '%s task #%d [%s] (run #%d started %s)',
                $verb,
                $row['task_id'],
                $row['ref'] ?? '—',
                $row['run_id'],
                $row['started_at'] ?? '—',
            ));
        }

        $this->info(sprintf('%s %d task(s).', $verb, count($recovered)));

        return self::SUCCESS;
    }
}
