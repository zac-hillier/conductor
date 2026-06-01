<?php

namespace App\Console\Commands;

use App\Services\TasksYamlImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportTasks extends Command
{
    protected $signature = 'conductor:import {customer : Customer slug} {--path= : Path to tasks.yaml} {--dry-run : Report the plan without persisting}';

    protected $description = 'Import a legacy tasks.yaml into a Conductor customer profile';

    public function handle(TasksYamlImporter $importer): int
    {
        $customer = (string) $this->argument('customer');
        $dryRun = (bool) $this->option('dry-run');

        $path = $this->option('path')
            ?: '/home/zac/'.$customer.'/.claude/tasks/tasks.yaml';

        try {
            $summary = $importer->import($customer, $path, $dryRun);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing persisted.');
        }

        $this->line("Profile: {$summary['profile']}");
        $this->line("Created: {$summary['created']}");
        $this->line("Updated: {$summary['updated']}");

        if ($summary['by_status'] !== []) {
            $this->line('By status:');

            foreach ($summary['by_status'] as $status => $count) {
                $this->line("    {$status}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
