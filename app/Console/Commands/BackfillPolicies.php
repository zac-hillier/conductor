<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\PolicyDefaults;
use Illuminate\Console\Command;

class BackfillPolicies extends Command
{
    protected $signature = 'conductor:backfill-policies';

    protected $description = 'Create a default policy for any profile that lacks one, and fill any missing rule keys';

    public function handle(): int
    {
        $created = 0;
        $filled = 0;

        Profile::query()->with('policy')->each(function (Profile $profile) use (&$created, &$filled) {
            $defaults = PolicyDefaults::for($profile->kind);

            if ($profile->policy === null) {
                $profile->policy()->create(['rules' => $defaults]);

                $this->line("Seeded policy for [{$profile->slug}] ({$profile->kind->value})");
                $created++;

                return;
            }

            $rules = $profile->policy->rules ?? [];
            $missing = array_diff_key($defaults, $rules);

            if ($missing === []) {
                return;
            }

            $profile->policy->update(['rules' => array_merge($defaults, $rules)]);

            $this->line('Filled ['.implode(', ', array_keys($missing))."] for [{$profile->slug}]");
            $filled++;
        });

        $this->info("Done. {$created} created, {$filled} updated.");

        return self::SUCCESS;
    }
}
